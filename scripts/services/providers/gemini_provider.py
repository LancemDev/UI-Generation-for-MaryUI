"""Gemini adapter using the google-genai SDK with a response schema."""

from __future__ import annotations

import json
from typing import Literal

import httpx
from google import genai
from google.genai import errors, types
from pydantic import BaseModel

from core.config import Settings
from core.exceptions import ConfigurationError, ProviderError
from services.providers.base import ProviderResult


# Gemini's schema support rejects `additionalProperties`, so it gets plain mirrors
# of ModelOutput; the result is validated against the strict models afterwards.
class _Action(BaseModel):
    op: Literal["create", "update"]
    component: str
    summary: str
    php: str
    blade: str


class _Output(BaseModel):
    reply: str
    actions: list[_Action]


class GeminiProvider:
    def __init__(self, settings: Settings):
        if not settings.gemini_api_key:
            raise ConfigurationError("GEMINI_API_KEY is not set")
        self.model = settings.gemini_model
        self.client = genai.Client(api_key=settings.gemini_api_key)

    def generate(self, system: str, messages: list[dict[str, str]], max_tokens: int) -> ProviderResult:
        contents = [
            types.Content(role="model" if m["role"] == "assistant" else "user", parts=[types.Part(text=m["content"])])
            for m in messages
        ]
        try:
            response = self.client.models.generate_content(
                model=self.model,
                contents=contents,
                config=types.GenerateContentConfig(
                    system_instruction=system,
                    response_mime_type="application/json",
                    response_schema=_Output,
                    temperature=0.2,
                    max_output_tokens=max_tokens,
                ),
            )
        except (errors.APIError, httpx.HTTPError) as exc:
            raise ProviderError(f"Gemini request failed: {exc}") from exc

        candidate = response.candidates[0] if response.candidates else None
        if candidate and candidate.finish_reason == types.FinishReason.MAX_TOKENS:
            raise ProviderError("The model ran out of output tokens before finishing. Try a smaller request.")

        try:
            data = json.loads(response.text or "")
        except json.JSONDecodeError as exc:
            raise ProviderError("Gemini returned invalid JSON") from exc

        usage = {}
        if response.usage_metadata:
            usage = {
                "input_tokens": response.usage_metadata.prompt_token_count or 0,
                "output_tokens": response.usage_metadata.candidates_token_count or 0,
            }
        return ProviderResult(data=data, model=self.model, usage=usage)
