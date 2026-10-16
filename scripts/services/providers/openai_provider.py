"""OpenAI adapter using strict JSON-schema structured outputs."""

from __future__ import annotations

import json

from openai import OpenAI, OpenAIError

from core.config import Settings
from core.exceptions import ConfigurationError, ProviderError
from services.providers.base import ProviderResult, TextCallback
from services.schema import strict_json_schema

# Reasoning models reject a custom temperature.
NO_TEMPERATURE_PREFIXES = ("o1", "o3", "o4", "gpt-5")


class OpenAIProvider:
    def __init__(self, settings: Settings):
        if not settings.openai_api_key:
            raise ConfigurationError("OPENAI_API_KEY is not set")
        self.model = settings.openai_model
        self.client = OpenAI(api_key=settings.openai_api_key)

    def generate(
        self,
        system: str,
        messages: list[dict[str, str]],
        max_tokens: int,
        model: str | None = None,
        on_text: TextCallback | None = None,
        cache_key: str | None = None,
    ) -> ProviderResult:
        model = model or self.model
        options = {}
        if not model.startswith(NO_TEMPERATURE_PREFIXES):
            options["temperature"] = 0.2
        if cache_key:
            # Routes requests sharing a prompt prefix to the same cache.
            options["prompt_cache_key"] = cache_key

        request = dict(
            model=model,
            messages=[{"role": "system", "content": system}, *messages],
            response_format={
                "type": "json_schema",
                "json_schema": {"name": "skylarr_generation", "strict": True, "schema": strict_json_schema()},
            },
            max_completion_tokens=max_tokens,
            **options,
        )

        try:
            if on_text:
                content, finish_reason, refusal, model_name, usage = self._stream(request, on_text)
            else:
                response = self.client.chat.completions.create(**request)
                choice = response.choices[0]
                content, finish_reason = choice.message.content or "", choice.finish_reason
                refusal, model_name, usage = getattr(choice.message, "refusal", None), response.model, response.usage
        except OpenAIError as exc:
            raise ProviderError(f"OpenAI request failed: {exc}") from exc

        if finish_reason == "length":
            raise ProviderError("The model ran out of output tokens before finishing. Try a smaller request.")
        if refusal:
            raise ProviderError(f"The model refused the request: {refusal}")

        try:
            data = json.loads(content)
        except json.JSONDecodeError as exc:
            raise ProviderError("OpenAI returned invalid JSON") from exc

        return ProviderResult(data=data, model=model_name, usage=_usage(usage))

    def _stream(self, request: dict, on_text: TextCallback):
        parts: list[str] = []
        finish_reason = refusal = usage = None
        model_name = request["model"]

        stream = self.client.chat.completions.create(**request, stream=True, stream_options={"include_usage": True})
        for chunk in stream:
            model_name = chunk.model or model_name
            if chunk.usage:
                usage = chunk.usage
            if not chunk.choices:
                continue
            choice = chunk.choices[0]
            finish_reason = choice.finish_reason or finish_reason
            if getattr(choice.delta, "refusal", None):
                refusal = (refusal or "") + choice.delta.refusal
            if choice.delta.content:
                parts.append(choice.delta.content)
                on_text(choice.delta.content)

        return "".join(parts), finish_reason, refusal, model_name, usage


def _usage(usage) -> dict[str, int]:
    if not usage:
        return {}
    details = getattr(usage, "prompt_tokens_details", None)
    return {
        "input_tokens": usage.prompt_tokens,
        "cached_input_tokens": (getattr(details, "cached_tokens", None) or 0) if details else 0,
        "output_tokens": usage.completion_tokens,
    }
