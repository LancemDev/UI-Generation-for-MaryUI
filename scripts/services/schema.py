"""Request/response contracts for the generation API.

`ModelOutput` is also the JSON schema the model must follow (structured output),
so the model returns files directly instead of text we have to parse.
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
    # Repair mode: errors observed when running previously generated code.
    errors: list[str] = []


class ComponentAction(BaseModel):
    model_config = ConfigDict(extra="forbid")

    op: Literal["create", "update"]
    component: str = Field(description="PascalCase Livewire class name, e.g. UserTable")
    summary: str = Field(description="One sentence describing what changed")
    php: str = Field(description="Complete PHP class file, starting with <?php")
    blade: str = Field(description="Complete Blade view file with a single root element")


class ModelOutput(BaseModel):
    model_config = ConfigDict(extra="forbid")

    reply: str = Field(description="Short conversational reply shown in chat. No code.")
    actions: list[ComponentAction] = Field(
        description="Components to create or update. Empty when the user only asked a question."
    )


class GenerateResponse(BaseModel):
    reply: str
    actions: list[ComponentAction]
    warnings: list[str] = []
    model: str
    usage: dict[str, int] = {}


def strict_json_schema() -> dict:
    """JSON schema for ModelOutput in the strict subset OpenAI structured outputs accept."""

    action = {
        "type": "object",
        "additionalProperties": False,
        "required": ["op", "component", "summary", "php", "blade"],
        "properties": {
            "op": {"type": "string", "enum": ["create", "update"]},
            "component": {"type": "string", "description": ComponentAction.model_fields["component"].description},
            "summary": {"type": "string", "description": ComponentAction.model_fields["summary"].description},
            "php": {"type": "string", "description": ComponentAction.model_fields["php"].description},
            "blade": {"type": "string", "description": ComponentAction.model_fields["blade"].description},
        },
    }
    return {
        "type": "object",
        "additionalProperties": False,
        "required": ["reply", "actions"],
        "properties": {
            "reply": {"type": "string", "description": ModelOutput.model_fields["reply"].description},
            "actions": {"type": "array", "items": action, "description": ModelOutput.model_fields["actions"].description},
        },
    }
