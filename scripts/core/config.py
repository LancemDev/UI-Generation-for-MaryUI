"""Runtime settings for the Skylarr AI service, read once from the environment."""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from functools import lru_cache
from pathlib import Path

from dotenv import find_dotenv, load_dotenv

SCRIPTS_DIR = Path(__file__).resolve().parents[1]

if not load_dotenv(find_dotenv(usecwd=True)):
    load_dotenv(SCRIPTS_DIR / ".env")


def _int(name: str, default: int) -> int:
    value = os.getenv(name)
    return int(value) if value else default


@dataclass(frozen=True)
class Settings:
    provider: str = field(default_factory=lambda: os.getenv("AI_PROVIDER", "openai").lower())
    openai_api_key: str | None = field(default_factory=lambda: os.getenv("OPENAI_API_KEY"))
    openai_model: str = field(default_factory=lambda: os.getenv("OPENAI_MODEL", "gpt-4.1"))
    gemini_api_key: str | None = field(default_factory=lambda: os.getenv("GEMINI_API_KEY"))
    gemini_model: str = field(default_factory=lambda: os.getenv("GEMINI_MODEL", "gemini-2.5-flash"))
    # Optional cheaper/faster models for repairs and changes scoped to a selection.
    # Empty means the main model handles everything.
    openai_fast_model: str = field(default_factory=lambda: os.getenv("OPENAI_FAST_MODEL", ""))
    gemini_fast_model: str = field(default_factory=lambda: os.getenv("GEMINI_FAST_MODEL", ""))
    max_output_tokens: int = field(default_factory=lambda: _int("AI_MAX_OUTPUT_TOKENS", 16000))
    # Shared secret with the Laravel app. Empty disables auth (local development only).
    service_token: str = field(default_factory=lambda: os.getenv("AI_SERVICE_TOKEN", ""))
    knowledge_dir: Path = SCRIPTS_DIR / "knowledge"
    prompts_dir: Path = SCRIPTS_DIR / "services" / "prompts"

    @property
    def main_model(self) -> str:
        return self.gemini_model if self.provider == "gemini" else self.openai_model

    @property
    def fast_model(self) -> str:
        fast = self.gemini_fast_model if self.provider == "gemini" else self.openai_fast_model
        return fast or self.main_model


@lru_cache
def get_settings() -> Settings:
    return Settings()
