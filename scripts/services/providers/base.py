"""Provider interface: send a prompt, get back a dict matching ModelOutput."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Protocol

from core.config import Settings
from core.exceptions import ConfigurationError


@dataclass
class ProviderResult:
    data: dict
    model: str
    usage: dict[str, int] = field(default_factory=dict)


class Provider(Protocol):
    def generate(self, system: str, messages: list[dict[str, str]], max_tokens: int) -> ProviderResult: ...


def make_provider(settings: Settings) -> Provider:
    if settings.provider == "openai":
        from services.providers.openai_provider import OpenAIProvider

        return OpenAIProvider(settings)
    if settings.provider == "gemini":
        from services.providers.gemini_provider import GeminiProvider

        return GeminiProvider(settings)
    raise ConfigurationError(f"Unknown AI_PROVIDER '{settings.provider}'. Use 'openai' or 'gemini'.")
