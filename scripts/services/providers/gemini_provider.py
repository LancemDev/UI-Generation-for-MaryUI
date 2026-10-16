"""Gemini adapter using the google-genai SDK with a response schema.

Gemini 2.5 models cache repeated prompt prefixes implicitly; cached tokens are
reported in usage as `cached_input_tokens`.
"""

from __future__ import annotations

import json
from typing import Literal

import httpx
from google import genai
from google.genai import errors, types
from pydantic import BaseModel

from core.config import Settings
from core.exceptions import ConfigurationError, ProviderError
from services.providers.base import ProviderResult, TextCallback


# Gemini's schema support rejects `additionalProperties`, so it gets plain mirrors
# of ModelOutput; the result is validated against the strict models afterwards.
class _Edit(BaseModel):
    file: Literal["php", "blade"]
    search: str
    replace: str


class _Action(BaseModel):
    op: Literal["create", "update"]
    component: str
    summary: str
    php: str
    blade: str
    edits: list[_Edit]


class _Output(BaseModel):
    reply: str
    actions: list[_Action]


class GeminiProvider:
    def __init__(self, settings: Settings):
        if not settings.gemini_api_key:
            raise ConfigurationError("GEMINI_API_KEY is not set")
        self.model = settings.gemini_model
        self.client = genai.Client(api_key=settings.gemini_api_key)

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
        request = dict(
            model=model,
            contents=[
                types.Content(role="model" if m["role"] == "assistant" else "user", parts=[types.Part(text=m["content"])])
                for m in messages
            ],
            config=types.GenerateContentConfig(
                system_instruction=system,
                response_mime_type="application/json",
                response_schema=_Output,
                temperature=0.2,
                max_output_tokens=max_tokens,
            ),
        )

        try:
            if on_text:
                text, finish_reason, usage = self._stream(request, on_text)
            else:
                response = self.client.models.generate_content(**request)
                candidate = response.candidates[0] if response.candidates else None
                text, finish_reason, usage = response.text or "", candidate and candidate.finish_reason, response.usage_metadata
        except (errors.APIError, httpx.HTTPError) as exc:
            raise ProviderError(f"Gemini request failed: {exc}") from exc

        if finish_reason == types.FinishReason.MAX_TOKENS:
            raise ProviderError("The model ran out of output tokens before finishing. Try a smaller request.")

        try:
            data = json.loads(text)
        except json.JSONDecodeError as exc:
            raise ProviderError("Gemini returned invalid JSON") from exc

        return ProviderResult(data=data, model=model, usage=_usage(usage))

    def _stream(self, request: dict, on_text: TextCallback):
        parts: list[str] = []
        finish_reason = usage = None
        for chunk in self.client.models.generate_content_stream(**request):
            if chunk.usage_metadata:
                usage = chunk.usage_metadata
            if chunk.candidates and chunk.candidates[0].finish_reason:
                finish_reason = chunk.candidates[0].finish_reason
            if chunk.text:
                parts.append(chunk.text)
                on_text(chunk.text)
        return "".join(parts), finish_reason, usage


def _usage(metadata) -> dict[str, int]:
    if not metadata:
        return {}
    return {
        "input_tokens": metadata.prompt_token_count or 0,
        "cached_input_tokens": metadata.cached_content_token_count or 0,
        "output_tokens": metadata.candidates_token_count or 0,
    }
