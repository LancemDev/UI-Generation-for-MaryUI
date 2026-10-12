import json

import httpx
import pytest
from openai import OpenAI

from core.config import Settings
from core.exceptions import ConfigurationError, ProviderError
from services.providers.base import make_provider
from services.providers.gemini_provider import GeminiProvider
from services.providers.openai_provider import OpenAIProvider


def openai_provider(handler, model="gpt-4.1") -> OpenAIProvider:
    provider = OpenAIProvider(Settings(provider="openai", openai_api_key="test", openai_model=model))
    provider.client = OpenAI(api_key="test", http_client=httpx.Client(transport=httpx.MockTransport(handler)))
    return provider


def completion(content: str, finish_reason: str = "stop") -> dict:
    return {
        "id": "chatcmpl-1",
        "object": "chat.completion",
        "created": 0,
        "model": "gpt-4.1-2025-04-14",
        "choices": [{"index": 0, "finish_reason": finish_reason, "message": {"role": "assistant", "content": content}}],
        "usage": {"prompt_tokens": 10, "completion_tokens": 5, "total_tokens": 15},
    }


def test_openai_sends_strict_schema_and_parses_output():
    captured = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured.update(json.loads(request.content))
        return httpx.Response(200, json=completion(json.dumps({"reply": "Hi", "actions": []})))

    result = openai_provider(handler).generate("system prompt", [{"role": "user", "content": "hello"}], 1000)

    assert result.data == {"reply": "Hi", "actions": []}
    assert result.usage == {"input_tokens": 10, "cached_input_tokens": 0, "output_tokens": 5}
    assert captured["response_format"]["json_schema"]["strict"] is True
    assert captured["messages"][0] == {"role": "system", "content": "system prompt"}
    assert captured["max_completion_tokens"] == 1000
    assert captured["temperature"] == 0.2


def test_openai_reasoning_models_omit_temperature():
    captured = {}

    def handler(request):
        captured.update(json.loads(request.content))
        return httpx.Response(200, json=completion('{"reply": "", "actions": []}'))

    openai_provider(handler, model="gpt-5-mini").generate("s", [{"role": "user", "content": "x"}], 10)
    assert "temperature" not in captured


def test_openai_truncated_output_raises():
    def handler(request):
        return httpx.Response(200, json=completion('{"reply": "cut', finish_reason="length"))

    with pytest.raises(ProviderError, match="ran out of output tokens"):
        openai_provider(handler).generate("s", [{"role": "user", "content": "x"}], 10)


def test_missing_key_and_unknown_provider():
    with pytest.raises(ConfigurationError):
        make_provider(Settings(provider="openai", openai_api_key=None))
    with pytest.raises(ConfigurationError):
        make_provider(Settings(provider="llama"))


def test_gemini_network_errors_become_provider_errors():
    provider = GeminiProvider(Settings(provider="gemini", gemini_api_key="test"))

    class FailingModels:
        def generate_content(self, **kwargs):
            raise httpx.ConnectError("connection refused")

    provider.client = type("Client", (), {"models": FailingModels()})()

    with pytest.raises(ProviderError, match="Gemini request failed"):
        provider.generate("s", [{"role": "user", "content": "x"}], 10)


def sse(chunks: list[dict]) -> httpx.Response:
    body = "".join(f"data: {json.dumps(c)}\n\n" for c in chunks) + "data: [DONE]\n\n"
    return httpx.Response(200, headers={"content-type": "text/event-stream"}, content=body.encode())


def chunk(content=None, finish_reason=None, usage=None) -> dict:
    choices = [] if usage else [{"index": 0, "delta": {"content": content} if content else {}, "finish_reason": finish_reason}]
    return {"id": "c", "object": "chat.completion.chunk", "created": 0, "model": "gpt-4.1", "choices": choices, "usage": usage}


def test_openai_streams_text_and_reports_cached_tokens():
    captured = {}

    def handler(request):
        captured.update(json.loads(request.content))
        return sse([
            chunk('{"reply": "Hel'),
            chunk('lo", "actions": []}'),
            chunk(finish_reason="stop"),
            chunk(usage={"prompt_tokens": 100, "completion_tokens": 7, "total_tokens": 107, "prompt_tokens_details": {"cached_tokens": 64}}),
        ])

    parts = []
    result = openai_provider(handler).generate(
        "s", [{"role": "user", "content": "x"}], 10, model="gpt-4.1-mini", on_text=parts.append, cache_key="k"
    )

    assert "".join(parts) == '{"reply": "Hello", "actions": []}'
    assert result.data == {"reply": "Hello", "actions": []}
    assert result.usage == {"input_tokens": 100, "cached_input_tokens": 64, "output_tokens": 7}
    assert captured["stream"] is True
    assert captured["model"] == "gpt-4.1-mini"
    assert captured["prompt_cache_key"] == "k"
