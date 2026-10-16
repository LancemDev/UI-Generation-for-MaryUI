import json
import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from services.providers.base import ProviderResult  # noqa: E402


class FakeProvider:
    """Records prompts and returns canned ModelOutput payloads.

    Pass one payload, or a list to answer successive calls (the last one repeats).
    Streams the JSON in small chunks when asked to.
    """

    def __init__(self, data: dict | list[dict]):
        self.responses = data if isinstance(data, list) else [data]
        self.calls: list[dict] = []

    @property
    def data(self) -> dict:
        return self.responses[0]

    def generate(self, system, messages, max_tokens, model=None, on_text=None, cache_key=None):
        data = self.responses[min(len(self.calls), len(self.responses) - 1)]
        self.calls.append({"system": system, "messages": messages, "max_tokens": max_tokens, "model": model, "cache_key": cache_key})
        if on_text:
            raw = json.dumps(data)
            for i in range(0, len(raw), 5):
                on_text(raw[i : i + 5])
        return ProviderResult(data=data, model="fake-model", usage={"input_tokens": 10, "cached_input_tokens": 4, "output_tokens": 1})


@pytest.fixture
def fake_provider_factory():
    return FakeProvider


def component_php(name: str, view: str | None = None) -> str:
    view = view or "livewire." + "".join("-" + c.lower() if c.isupper() else c for c in name).lstrip("-")
    return f"""<?php

namespace App\\Livewire;

use Livewire\\Component;

class {name} extends Component
{{
    public function render()
    {{
        return view('{view}');
    }}
}}
"""
