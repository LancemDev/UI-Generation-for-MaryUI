"""One structured model call per chat message, plus deterministic clean-up of the result."""

from __future__ import annotations

import re

from pydantic import ValidationError

from core.config import Settings
from core.exceptions import ProviderError
from services.knowledge import KnowledgeBase
from services.prompt_builder import build_prompt
from services.providers.base import Provider
from services.schema import ComponentAction, GenerateRequest, GenerateResponse, ModelOutput

CLASS_PATTERN = re.compile(r"\bclass\s+([A-Z][A-Za-z0-9]*)\s+extends\s+(?:\\?Livewire\\)?Component\b")
VIEW_CALL_PATTERN = re.compile(r"view\(\s*['\"]livewire\.([a-z0-9.\-]+)['\"]")
CODE_FENCE_PATTERN = re.compile(r"^```[a-z]*\s*\n|\n```\s*$")


def kebab(name: str) -> str:
    return re.sub(r"(?<!^)(?=[A-Z])", "-", name).lower()


def generate(request: GenerateRequest, provider: Provider, kb: KnowledgeBase, settings: Settings) -> GenerateResponse:
    prompt = build_prompt(request, kb)
    result = provider.generate(prompt.system, prompt.messages, settings.max_output_tokens)

    try:
        output = ModelOutput.model_validate(result.data)
    except ValidationError as exc:
        raise ProviderError(f"Model output did not match the schema: {exc.errors()[:3]}") from exc

    existing = {c.name for c in request.components}
    actions, warnings = [], []
    for action in output.actions:
        normalized, action_warnings = normalize_action(action, existing, kb)
        actions.append(normalized)
        warnings.extend(action_warnings)

    return GenerateResponse(
        reply=output.reply.strip(),
        actions=_dedupe(actions),
        warnings=warnings,
        model=result.model,
        usage=result.usage,
    )


def normalize_action(action: ComponentAction, existing: set[str], kb: KnowledgeBase) -> tuple[ComponentAction, list[str]]:
    """Make the files internally consistent so they can be written and served as-is."""

    warnings: list[str] = []
    php = _strip_fences(action.php)
    blade = _strip_fences(action.blade)

    # The class declared in the file is what Livewire will load, so it wins.
    match = CLASS_PATTERN.search(php)
    name = match.group(1) if match else _pascal(action.component)
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
    for tag in kb.unknown_tags(blade):
        warnings.append(f"{name}: <{tag}> is not a MaryUI component")

    op = "update" if name in existing else "create"
    normalized = ComponentAction(op=op, component=name, summary=action.summary.strip(), php=php.strip() + "\n", blade=blade.strip() + "\n")
    return normalized, warnings


def _dedupe(actions: list[ComponentAction]) -> list[ComponentAction]:
    """If the model emits the same component twice, keep the last version."""

    by_name: dict[str, ComponentAction] = {}
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
