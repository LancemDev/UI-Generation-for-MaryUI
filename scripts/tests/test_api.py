from dataclasses import replace

import pytest
from fastapi.testclient import TestClient

import main
from core.config import get_settings
from core.exceptions import ProviderError


@pytest.fixture
def client(monkeypatch, fake_provider_factory):
    provider = fake_provider_factory({"reply": "Hello!", "actions": []})
    monkeypatch.setattr(main, "make_provider", lambda settings: provider)
    with TestClient(main.app) as test_client:
        yield test_client


def test_health(client):
    body = client.get("/health").json()
    assert body["status"] == "ok"
    assert body["maryui"]


def test_generate(client):
    response = client.post("/generate", json={"message": "hi"})
    assert response.status_code == 200
    assert response.json()["reply"] == "Hello!"
    assert response.json()["model"] == "fake-model"


def test_rejects_invalid_component_names(client):
    response = client.post(
        "/generate",
        json={"message": "hi", "components": [{"name": "../../etc", "route": "/x"}]},
    )
    assert response.status_code == 422


def test_service_token_required_when_configured(client, monkeypatch):
    settings = replace(get_settings(), service_token="secret")
    monkeypatch.setattr("core.security.get_settings", lambda: settings)

    assert client.post("/generate", json={"message": "hi"}).status_code == 401
    ok = client.post("/generate", json={"message": "hi"}, headers={"Authorization": "Bearer secret"})
    assert ok.status_code == 200


def test_provider_errors_map_to_502(monkeypatch, fake_provider_factory):
    class Failing:
        def generate(self, *args):
            raise ProviderError("upstream down")

    monkeypatch.setattr(main, "make_provider", lambda settings: Failing())
    with TestClient(main.app) as client:
        response = client.post("/generate", json={"message": "hi"})
    assert response.status_code == 502
    assert "upstream down" in response.json()["detail"]
