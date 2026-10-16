"""One structured model call per chat message, plus deterministic clean-up of the result.

Extra calls happen only when they are cheaper than the alternative:
- edits that cannot be applied are retried once asking for complete files;
- certain render failures found statically (unknown tags, missing required props)
  get one repair call before anything is written to a preview container.
"""

from __future__ import annotations

import re
import threading
import time
from collections.abc import Callable
from dataclasses import dataclass, field

from pydantic import ValidationError

from core.config import Settings
from core.exceptions import GenerationCancelled, ProviderError
from services.edits import EditError, apply_edits
from services.knowledge import KnowledgeBase
from services.prompt_builder import Prompt, build_prompt
from services.providers.base import Provider
from services.schema import (
    COMPONENT_NAME_PATTERN,
    ComponentAction,
    ExistingComponent,
    GenerateRequest,
    GenerateResponse,
    ModelOutput,
    ResolvedAction,
)
from services.streaming import ReplyExtractor

CLASS_PATTERN = re.compile(r"\bclass\s+([A-Z][A-Za-z0-9]*)\s+extends\s+(?:\\?Livewire\\)?Component\b")
VIEW_CALL_PATTERN = re.compile(r"view\(\s*['\"]livewire\.([a-z0-9.\-]+)['\"]")
CODE_FENCE_PATTERN = re.compile(r"^```[a-z]*\s*\n|\n```\s*$")

ReplyCallback = Callable[[str], None]


def kebab(name: str) -> str:
    return re.sub(r"(?<!^)(?=[A-Z])", "-", name).lower()


@dataclass
class _Run:
    """Accumulates cost and timing across the model calls of one request."""

    provider: Provider
    settings: Settings
    cancelled: threading.Event | None = None
    usage: dict[str, int] = field(default_factory=dict)
    model_ms: float = 0.0
    calls: int = 0
    model: str = ""

    def call(self, prompt: Prompt, model: str, on_reply: ReplyCallback | None = None) -> ModelOutput:
        self._check_cancelled()
        on_text = None
        if on_reply:
            extractor = ReplyExtractor()

            def on_text(chunk: str) -> None:
                # Raising here unwinds the provider's streaming loop and closes its connection.
                self._check_cancelled()
                text = extractor.feed(chunk)
                if text:
                    on_reply(text)

        started = time.perf_counter()
        result = self.provider.generate(
            prompt.system,
            prompt.messages,
            self.settings.max_output_tokens,
            model=model,
            on_text=on_text,
            cache_key="skylarr-generate",
        )
        self.model_ms += (time.perf_counter() - started) * 1000
        self.calls += 1
        self.model = self.model or result.model
        for key, value in result.usage.items():
            self.usage[key] = self.usage.get(key, 0) + value

        try:
            return ModelOutput.model_validate(result.data)
        except ValidationError as exc:
            raise ProviderError(f"Model output did not match the schema: {exc.errors()[:3]}") from exc

    def _check_cancelled(self) -> None:
        if self.cancelled and self.cancelled.is_set():
            raise GenerationCancelled()


def choose_model(request: GenerateRequest, settings: Settings) -> str:
    """Repairs and changes scoped to a selection are small, targeted jobs: use the fast model."""

    return settings.fast_model if request.errors or request.selection else settings.main_model


def generate(
    request: GenerateRequest,
    provider: Provider,
    kb: KnowledgeBase,
    settings: Settings,
    on_reply: ReplyCallback | None = None,
    cancelled: threading.Event | None = None,
) -> GenerateResponse:
    started = time.perf_counter()
    run = _Run(provider, settings, cancelled)

    output = run.call(build_prompt(request, kb), choose_model(request, settings), on_reply)
    existing = {c.name: c for c in request.components}
    actions, warnings, failed = resolve_actions(output.actions, existing, kb)

    if failed:
        try:
            retry = run.call(build_prompt(request, kb, full_files_for=failed), settings.main_model)
        except ProviderError as exc:
            # The changes that did apply are still worth returning.
            retry = ModelOutput(reply="", actions=[])
            warnings.append(f"Retrying with complete files failed: {exc}")
        wanted = [a for a in retry.actions if a.component in failed]
        more, more_warnings, still_failed = resolve_actions(wanted, existing, kb)
        actions = _merge(actions, more)
        warnings += more_warnings
        warnings += [f"Could not apply the changes to {name}: {reason}" for name, reason in still_failed.items()]

    if not request.errors:
        actions, warnings = _static_repair(run, request, kb, actions, warnings)

    return GenerateResponse(
        reply=output.reply.strip(),
        actions=actions,
        warnings=list(dict.fromkeys(warnings)),
        model=run.model,
        usage=run.usage,
        timings={"model_ms": round(run.model_ms), "total_ms": round((time.perf_counter() - started) * 1000)},
        calls=run.calls,
    )


def resolve_actions(
    raw: list[ComponentAction], existing: dict[str, ExistingComponent], kb: KnowledgeBase
) -> tuple[list[ResolvedAction], list[str], dict[str, str]]:
    """Turn model actions into complete, normalized files.

    Returns (actions, warnings, failed) where `failed` maps component names whose
    edits could not be applied to the reason.
    """

    actions: list[ResolvedAction] = []
    warnings: list[str] = []
    failed: dict[str, str] = {}

    for action in raw:
        if not action.php.strip() and not action.blade.strip():
            current = existing.get(action.component)
            if not action.edits:
                failed[action.component] = "the action had neither files nor edits"
                continue
            if not current or not (current.php or current.blade):
                failed[action.component] = "edits need an existing component with source"
                continue
            try:
                files = apply_edits({"php": current.php, "blade": current.blade}, action.edits)
            except EditError as exc:
                failed[action.component] = str(exc)
                continue
            action = action.model_copy(update={"php": files["php"], "blade": files["blade"]})

        normalized, action_warnings = normalize_action(action, set(existing), kb)
        warnings.extend(action_warnings)
        if normalized:
            actions.append(normalized)

    return _dedupe(actions), warnings, failed


def normalize_action(action: ComponentAction, existing: set[str], kb: KnowledgeBase) -> tuple[ResolvedAction | None, list[str]]:
    """Make the files internally consistent so they can be written and served as-is.

    Returns no action when the component name cannot be used as a file name.
    """

    warnings: list[str] = []
    php = _strip_fences(action.php)
    blade = _strip_fences(action.blade)

    # The class declared in the file is what Livewire will load, so it wins.
    match = CLASS_PATTERN.search(php)
    name = match.group(1) if match else _pascal(action.component)
    if not re.fullmatch(COMPONENT_NAME_PATTERN, name):
        return None, [f"Skipped {action.component}: {name!r} is not a valid component name"]
    if not match:
        warnings.append(f"{name}: PHP file has no Livewire class declaration")
    elif name != action.component:
        warnings.append(f"Renamed {action.component} to {name} to match its class declaration")

    if not php.lstrip().startswith("<?php"):
        php = "<?php\n\n" + php.lstrip()
    if "namespace App\\Livewire;" not in php:
        php = php.replace("<?php", "<?php\n\nnamespace App\\Livewire;", 1)
    if "use Livewire\\Component;" not in php and "extends Component" in php:
        php = php.replace("namespace App\\Livewire;", "namespace App\\Livewire;\n\nuse Livewire\\Component;", 1)

    view = f"livewire.{kebab(name)}"
    php, count = VIEW_CALL_PATTERN.subn(f"view('{view}'", php)
    if count == 0:
        warnings.append(f"{name}: render() does not return view('{view}')")

    if blade.lstrip().startswith("<?php"):
        blade = re.sub(r"^\s*<\?php.*?(\?>|$)", "", blade, count=1, flags=re.DOTALL)
        warnings.append(f"{name}: removed PHP code from the Blade view")

    blade, icon_fixes = kb.fix_icons(blade)
    warnings.extend(f"{name}: {fix}" for fix in icon_fixes)

    op = "update" if name in existing else "create"
    resolved = ResolvedAction(op=op, component=name, summary=action.summary.strip(), php=php.strip() + "\n", blade=blade.strip() + "\n")
    return resolved, warnings


def static_errors(actions: list[ResolvedAction], kb: KnowledgeBase) -> tuple[list[str], list[str]]:
    errors, warnings = [], []
    for action in actions:
        action_errors, action_warnings = kb.check_blade(action.blade)
        errors += [f"{action.component}: {e}" for e in action_errors]
        warnings += [f"{action.component}: {w}" for w in action_warnings]
    return errors, warnings


def _static_repair(
    run: _Run, request: GenerateRequest, kb: KnowledgeBase, actions: list[ResolvedAction], warnings: list[str]
) -> tuple[list[ResolvedAction], list[str]]:
    """Fix certain render failures with one model call instead of a container round trip."""

    errors, check_warnings = static_errors(actions, kb)
    if not errors:
        return actions, warnings + check_warnings

    # The repair sees the generated files as the project's current state.
    components = {c.name: c for c in request.components}
    for action in actions:
        route = components[action.component].route if action.component in components else f"/{kebab(action.component)}"
        components[action.component] = ExistingComponent(name=action.component, route=route, php=action.php, blade=action.blade)
    repair_request = request.model_copy(update={"components": list(components.values()), "errors": errors, "selection": None})

    try:
        repair = run.call(build_prompt(repair_request, kb), run.settings.fast_model)
    except ProviderError as exc:
        # Optional step: keep the generated files and let the render check and repair loop handle them.
        return actions, warnings + check_warnings + errors + [f"Static repair call failed: {exc}"]
    touched = {a.component for a in actions}
    fixed, fix_warnings, failed = resolve_actions([a for a in repair.actions if a.component in touched], components, kb)
    actions = _merge(actions, fixed)

    remaining_errors, remaining_warnings = static_errors(actions, kb)
    warnings = warnings + fix_warnings + remaining_warnings + remaining_errors
    warnings += [f"Could not apply the fix to {name}: {reason}" for name, reason in failed.items()]
    return actions, warnings


def _merge(actions: list[ResolvedAction], replacements: list[ResolvedAction]) -> list[ResolvedAction]:
    """Replace actions by component name, keeping the original op (a created component stays "create")."""

    by_name = {a.component: a for a in actions}
    for replacement in replacements:
        original = by_name.get(replacement.component)
        by_name[replacement.component] = replacement.model_copy(update={"op": original.op}) if original else replacement
    return list(by_name.values())


def _dedupe(actions: list[ResolvedAction]) -> list[ResolvedAction]:
    """If the model emits the same component twice, keep the last version."""

    by_name: dict[str, ResolvedAction] = {}
    for action in actions:
        by_name.pop(action.component, None)
        by_name[action.component] = action
    return list(by_name.values())


def _strip_fences(code: str) -> str:
    return CODE_FENCE_PATTERN.sub("", code.strip())


def _pascal(name: str) -> str:
    parts = re.findall(r"[A-Za-z0-9]+", name) or ["Generated"]
    candidate = "".join(p[:1].upper() + p[1:] for p in parts)
    return candidate if candidate[0].isalpha() else f"Component{candidate}"
