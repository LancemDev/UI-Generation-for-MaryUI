"""Turns a GenerateRequest into the system prompt and message list sent to the model."""

from __future__ import annotations

import re
from dataclasses import dataclass
from functools import lru_cache

from core.config import get_settings
from services.knowledge import TAG_PATTERN, KnowledgeBase
from services.schema import ExistingComponent, GenerateRequest

MAX_HISTORY_TURNS = 12
# Rough budget for component source included verbatim (~15k tokens).
SOURCE_BUDGET_CHARS = 60_000


@dataclass(frozen=True)
class Prompt:
    system: str
    messages: list[dict[str, str]]


@lru_cache
def _template(name: str) -> str:
    return (get_settings().prompts_dir / name).read_text()


def build_prompt(request: GenerateRequest, kb: KnowledgeBase, full_files_for: dict[str, str] | None = None) -> Prompt:
    """Build the prompt.

    Ordered for provider prompt caching: the system prompt is identical for every
    request and history only grows, so everything that changes per request goes
    in the final user message.

    `full_files_for` maps component names to why their edits could not be applied;
    the model is asked for complete files for those.
    """

    with_source, names_only = select_components_for_context(request, required=set(full_files_for or ()))

    used_tags: set[str] = set()
    for component in with_source:
        used_tags.update(TAG_PATTERN.findall(component.blade))

    messages = [_history_message(turn) for turn in request.history[-MAX_HISTORY_TURNS:]]
    final = _final_user_message(request, with_source, names_only, kb.context_for(request.message, used_tags), full_files_for)
    messages.append({"role": "user", "content": final})
    return Prompt(system=system_prompt(kb), messages=messages)


def system_prompt(kb: KnowledgeBase) -> str:
    return _template("system.md").replace("{knowledge}", kb.static_context())


def select_components_for_context(
    request: GenerateRequest,
    required: set[str] | None = None,
) -> tuple[list[ExistingComponent], list[ExistingComponent]]:
    """Pick which existing components get their full source in the prompt.

    Priority: the component the user selected, then ones named in the message,
    then the most recently changed (Laravel sends them oldest first). Components
    in `required` (e.g. those asked for again as complete files) always get source.
    """

    required = required or set()

    message = request.message.lower()
    focus = request.focus_component

    def priority(item: tuple[int, ExistingComponent]) -> tuple[int, int]:
        index, component = item
        if component.name == focus:
            return (0, 0)
        if _is_mentioned(component, message):
            return (1, 0)
        return (2, -index)

    ordered = [c for _, c in sorted(enumerate(request.components), key=priority)]

    with_source, names_only, used = [], [], 0
    for component in ordered:
        size = len(component.php) + len(component.blade)
        # In repair mode the failing components must be visible; errors are prefixed "Name: ...".
        failing = any(error.startswith(f"{component.name}:") for error in request.errors)
        must_include = component.name == focus or failing or component.name in required
        if must_include or used + size <= SOURCE_BUDGET_CHARS:
            with_source.append(component)
            used += size
        else:
            names_only.append(component)
    return with_source, names_only


def _is_mentioned(component: ExistingComponent, message: str) -> bool:
    words = re.findall(r"[A-Z][a-z0-9]*", component.name)
    # "UserTable" is mentioned by "user table", "usertable" or "user-table".
    return component.name.lower() in message or " ".join(w.lower() for w in words) in message or (
        component.route.strip("/") in message
    )


def _history_message(turn) -> dict[str, str]:
    content = turn.content
    if turn.role == "assistant" and turn.feedback == "negative":
        content += "\n\n[The user marked this response as unhelpful. Do not repeat its approach.]"
    elif turn.role == "assistant" and turn.feedback == "positive":
        content += "\n\n[The user marked this response as helpful.]"
    return {"role": turn.role, "content": content}


def _final_user_message(
    request: GenerateRequest,
    with_source: list[ExistingComponent],
    names_only: list[ExistingComponent],
    reference: str,
    full_files_for: dict[str, str] | None,
) -> str:
    sections = [reference]

    if with_source or names_only:
        sections.append("## Existing components in this project")
        for component in with_source:
            sections.append(
                f"### {component.name} (route {component.route})\n"
                f"```php\n{component.php.strip()}\n```\n"
                f"```blade\n{component.blade.strip()}\n```"
            )
        if names_only:
            listing = ", ".join(f"{c.name} ({c.route})" for c in names_only)
            sections.append(f"Other components (source omitted): {listing}")
    else:
        sections.append("## Existing components in this project\nNone yet.")

    if request.focus_component:
        sections.append(f"The user selected `{request.focus_component}` to modify.")
    if request.selection:
        sections.append(
            "The user selected this part of it and wants the change limited to it "
            "(rendered HTML from the preview or a span of the source; find the matching Blade):\n"
            f"```\n{request.selection.strip()}\n```"
        )

    if request.errors:
        errors = "\n".join(f"- {error}" for error in request.errors)
        sections.append(_template("repair.md").replace("{errors}", errors))
        sections.append(f"## Original request\n{request.message}")
    else:
        sections.append(f"## Request\n{request.message}")

    if full_files_for:
        reasons = "\n".join(f"- {name}: {reason}" for name, reason in full_files_for.items())
        sections.append(
            "## Edits could not be applied\n"
            f"{reasons}\n"
            "Return complete `php` and `blade` files (and empty `edits`) for these components."
        )

    return "\n\n".join(sections)

