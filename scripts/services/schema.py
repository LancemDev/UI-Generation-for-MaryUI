"""Request/response contracts for the generation API.

`ModelOutput` is also the JSON schema the model must follow (structured output),
so the model returns files or edits directly instead of text we have to parse.
"""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field

COMPONENT_NAME_PATTERN = r"^[A-Z][A-Za-z0-9]{1,63}$"


class ChatTurn(BaseModel):
    role: Literal["user", "assistant"]
    content: str
    feedback: Literal["positive", "negative"] | None = None


class ExistingComponent(BaseModel):
    name: str = Field(pattern=COMPONENT_NAME_PATTERN)
    route: str
    php: str = ""
    blade: str = ""


class GenerateRequest(BaseModel):
    message: str = Field(min_length=1)
    history: list[ChatTurn] = []
    components: list[ExistingComponent] = []
    # Component the user explicitly picked in the UI ("Modify: X").
    focus_component: str | None = None
    # Part of the focus component the user selected (rendered HTML from the preview or
    # a span of source from the editor); the change should stay inside it.
    selection: str | None = Field(default=None, max_length=6000)
    # Repair mode: errors observed when running previously generated code.
    errors: list[str] = []


class FileEdit(BaseModel):
    model_config = ConfigDict(extra="forbid")

    file: Literal["php", "blade"]
    search: str = Field(description="Exact text copied from the current file, unique within it")
    replace: str = Field(description="Text that replaces it")


class ComponentAction(BaseModel):
    """One component change as the model returns it: complete files or edits."""

    model_config = ConfigDict(extra="forbid")

    op: Literal["create", "update"]
    component: str = Field(description="PascalCase Livewire class name, e.g. UserTable")
    summary: str = Field(description="One sentence describing what changed")
    php: str = Field(description="Complete PHP class file starting with <?php, or empty when using edits")
    blade: str = Field(description="Complete Blade view with a single root element, or empty when using edits")
    edits: list[FileEdit] = Field(
        default=[],
        description="For small updates to an existing component: search/replace edits instead of complete files",
    )


class ModelOutput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    reply: str = Field(description="Short conversational reply shown in chat. No code.")
    actions: list[ComponentAction] = Field(
        description="Components to create or update. Empty when the user only asked a question."
    )


class ResolvedAction(BaseModel):
    """A component change with complete files, ready to write. What Laravel receives."""

    op: Literal["create", "update"]
    component: str
    summary: str
    php: str
    blade: str


class GenerateResponse(BaseModel):
    reply: str
    actions: list[ResolvedAction]
    warnings: list[str] = []
    model: str
    usage: dict[str, int] = {}
    # Wall-clock milliseconds: model_ms (sum of model calls), total_ms.
    timings: dict[str, int] = {}
    # How many model calls this request took (edit fallback and static repair add calls).
    calls: int = 1


def strict_json_schema() -> dict:
    """JSON schema for ModelOutput in the strict subset OpenAI structured outputs accept."""

    fields = ComponentAction.model_fields
    edit = {
        "type": "object",
        "additionalProperties": False,
        "required": ["file", "search", "replace"],
        "properties": {
            "file": {"type": "string", "enum": ["php", "blade"]},
            "search": {"type": "string", "description": FileEdit.model_fields["search"].description},
            "replace": {"type": "string", "description": FileEdit.model_fields["replace"].description},
        },
    }
    action = {
        "type": "object",
        "additionalProperties": False,
        "required": ["op", "component", "summary", "php", "blade", "edits"],
        "properties": {
            "op": {"type": "string", "enum": ["create", "update"]},
            "component": {"type": "string", "description": fields["component"].description},
            "summary": {"type": "string", "description": fields["summary"].description},
            "php": {"type": "string", "description": fields["php"].description},
            "blade": {"type": "string", "description": fields["blade"].description},
            "edits": {"type": "array", "items": edit, "description": fields["edits"].description},
        },
    }
    # `reply` comes first so it can be streamed to the chat before the files arrive.
    return {
        "type": "object",
        "additionalProperties": False,
        "required": ["reply", "actions"],
        "properties": {
            "reply": {"type": "string", "description": ModelOutput.model_fields["reply"].description},
            "actions": {"type": "array", "items": action, "description": ModelOutput.model_fields["actions"].description},
        },
    }
