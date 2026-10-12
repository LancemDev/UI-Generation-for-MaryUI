import sys
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from services.providers.base import ProviderResult  # noqa: E402


class FakeProvider:
    """Records prompts and returns a canned ModelOutput payload."""

    def __init__(self, data: dict):
        self.data = data
        self.calls: list[dict] = []

    def generate(self, system, messages, max_tokens):
        self.calls.append({"system": system, "messages": messages, "max_tokens": max_tokens})
        return ProviderResult(data=self.data, model="fake-model", usage={"input_tokens": 1, "output_tokens": 1})


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
