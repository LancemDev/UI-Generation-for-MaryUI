"""OpenAI adapter using strict JSON-schema structured outputs."""

from __future__ import annotations

import json

from openai import OpenAI, OpenAIError

from core.config import Settings
from core.exceptions import ConfigurationError, ProviderError
from services.providers.base import ProviderResult
from services.schema import strict_json_schema

# Reasoning models reject a custom temperature.
NO_TEMPERATURE_PREFIXES = ("o1", "o3", "o4", "gpt-5")


class OpenAIProvider:
    def __init__(self, settings: Settings):
        if not settings.openai_api_key:
            raise ConfigurationError("OPENAI_API_KEY is not set")
        self.model = settings.openai_model
        self.client = OpenAI(api_key=settings.openai_api_key)

    def generate(self, system: str, messages: list[dict[str, str]], max_tokens: int) -> ProviderResult:
        options = {}
        if not self.model.startswith(NO_TEMPERATURE_PREFIXES):
            options["temperature"] = 0.2

        try:
            response = self.client.chat.completions.create(
                model=self.model,
                messages=[{"role": "system", "content": system}, *messages],
                response_format={
                    "type": "json_schema",
                    "json_schema": {"name": "skylarr_generation", "strict": True, "schema": strict_json_schema()},
                },
                max_completion_tokens=max_tokens,
                **options,
            )
        except OpenAIError as exc:
            raise ProviderError(f"OpenAI request failed: {exc}") from exc

        choice = response.choices[0]
        if choice.finish_reason == "length":
            raise ProviderError("The model ran out of output tokens before finishing. Try a smaller request.")
        if getattr(choice.message, "refusal", None):
            raise ProviderError(f"The model refused the request: {choice.message.refusal}")

        try:
            data = json.loads(choice.message.content or "")
        except json.JSONDecodeError as exc:
            raise ProviderError("OpenAI returned invalid JSON") from exc

        usage = {}
        if response.usage:
            usage = {"input_tokens": response.usage.prompt_tokens, "output_tokens": response.usage.completion_tokens}
        return ProviderResult(data=data, model=response.model, usage=usage)
