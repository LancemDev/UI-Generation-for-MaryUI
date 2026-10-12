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


def build_prompt(request: GenerateRequest, kb: KnowledgeBase) -> Prompt:
    with_source, names_only = select_components_for_context(request)

    used_tags: set[str] = set()
    for component in with_source:
        used_tags.update(TAG_PATTERN.findall(component.blade))

    system = _template("system.md").replace("{knowledge}", kb.context_for(request.message, used_tags))

    messages = [_history_message(turn) for turn in request.history[-MAX_HISTORY_TURNS:]]
    messages.append({"role": "user", "content": _final_user_message(request, with_source, names_only)})
    return Prompt(system=system, messages=messages)


def select_components_for_context(
    request: GenerateRequest,
) -> tuple[list[ExistingComponent], list[ExistingComponent]]:
    """Pick which existing components get their full source in the prompt.

    Priority: the component the user selected, then ones named in the message,
    then the most recently changed (Laravel sends them oldest first).
    """

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
        must_include = component.name == focus or failing
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
) -> str:
    sections = []

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

    if request.errors:
        errors = "\n".join(f"- {error}" for error in request.errors)
        sections.append(_template("repair.md").replace("{errors}", errors))
        sections.append(f"## Original request\n{request.message}")
    else:
        sections.append(f"## Request\n{request.message}")

    return "\n\n".join(sections)

