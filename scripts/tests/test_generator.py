import pytest

from conftest import component_php
from core.config import get_settings
from core.exceptions import ProviderError
from services.generator import generate, kebab
from services.knowledge import get_knowledge_base
from services.prompt_builder import SOURCE_BUDGET_CHARS, build_prompt, select_components_for_context
from services.schema import ChatTurn, ExistingComponent, GenerateRequest


def run(request, data, factory):
    provider = factory(data)
    response = generate(request, provider, get_knowledge_base(), get_settings())
    return response, provider


def test_question_returns_no_actions(fake_provider_factory):
    response, _ = run(
        GenerateRequest(message="What does wire:model do?"),
        {"reply": "It binds an input to a property.", "actions": []},
        fake_provider_factory,
    )
    assert response.actions == []
    assert response.reply.startswith("It binds")


def test_update_sends_existing_source_to_model(fake_provider_factory):
    existing = ExistingComponent(
        name="RegisterForm",
        route="/register-form",
        php=component_php("RegisterForm"),
        blade='<div><x-input label="Email" wire:model="email" /></div>',
    )
    _, provider = run(
        GenerateRequest(message="add a phone field to the register form", components=[existing]),
        {"reply": "Added.", "actions": []},
        fake_provider_factory,
    )
    final_message = provider.calls[0]["messages"][-1]["content"]
    assert 'wire:model="email"' in final_message
    assert "class RegisterForm extends Component" in final_message


def test_class_name_wins_and_view_is_fixed(fake_provider_factory):
    php = component_php("UserTable", view="livewire.users")
    response, _ = run(
        GenerateRequest(message="users table"),
        {"reply": "Done.", "actions": [{"op": "create", "component": "UsersTable", "summary": "Table", "php": php, "blade": "<div></div>"}]},
        fake_provider_factory,
    )
    action = response.actions[0]
    assert action.component == "UserTable"
    assert "view('livewire.user-table')" in action.php
    assert any("Renamed" in w for w in response.warnings)


def test_op_is_derived_from_existing_components(fake_provider_factory):
    existing = ExistingComponent(name="LoginForm", route="/login-form", php=component_php("LoginForm"), blade="<div></div>")
    response, _ = run(
        GenerateRequest(message="tweak login", components=[existing]),
        {"reply": "Done.", "actions": [{"op": "create", "component": "LoginForm", "summary": "s", "php": component_php("LoginForm"), "blade": "<div></div>"}]},
        fake_provider_factory,
    )
    assert response.actions[0].op == "update"


def test_code_fences_and_php_in_blade_are_removed(fake_provider_factory):
    response, _ = run(
        GenerateRequest(message="card"),
        {
            "reply": "Done.",
            "actions": [{
                "op": "create", "component": "InfoCard", "summary": "s",
                "php": "```php\n" + component_php("InfoCard") + "\n```",
                "blade": "<?php namespace App\\Livewire; ?>\n<div><x-card title=\"Hi\" /></div>",
            }],
        },
        fake_provider_factory,
    )
    action = response.actions[0]
    assert not action.php.startswith("```")
    assert "<?php" not in action.blade
    assert action.blade.startswith("<div>")


def test_duplicate_components_keep_last(fake_provider_factory):
    first = {"op": "create", "component": "A1", "summary": "first", "php": component_php("Alpha"), "blade": "<div>1</div>"}
    second = {"op": "create", "component": "A2", "summary": "second", "php": component_php("Alpha"), "blade": "<div>2</div>"}
    response, _ = run(GenerateRequest(message="x"), {"reply": "r", "actions": [first, second]}, fake_provider_factory)
    assert len(response.actions) == 1
    assert response.actions[0].summary == "second"


def test_invalid_model_output_raises(fake_provider_factory):
    with pytest.raises(ProviderError):
        run(GenerateRequest(message="x"), {"reply": "missing actions"}, fake_provider_factory)


def test_negative_feedback_is_annotated_in_history():
    request = GenerateRequest(
        message="try again",
        history=[ChatTurn(role="user", content="make a form"), ChatTurn(role="assistant", content="Built it.", feedback="negative")],
    )
    prompt = build_prompt(request, get_knowledge_base())
    assert "unhelpful" in prompt.messages[1]["content"]


def test_focus_component_always_gets_source_and_budget_applies():
    big_blade = "<div>" + "x" * SOURCE_BUDGET_CHARS + "</div>"
    components = [
        ExistingComponent(name=f"Page{i}", route=f"/page{i}", php=component_php(f"Page{i}"), blade=big_blade)
        for i in range(3)
    ]
    request = GenerateRequest(message="change the header", components=components, focus_component="Page0")
    with_source, names_only = select_components_for_context(request)
    assert with_source[0].name == "Page0"
    assert {c.name for c in names_only} == {"Page1", "Page2"}


def test_repair_mode_includes_errors():
    request = GenerateRequest(message="make a table", errors=["Undefined variable $users"])
    prompt = build_prompt(request, get_knowledge_base())
    assert "Undefined variable $users" in prompt.messages[-1]["content"]


def test_kebab():
    assert kebab("UserTable") == "user-table"
    assert kebab("Dashboard") == "dashboard"


def test_invalid_component_names_are_skipped(fake_provider_factory):
    response, _ = run(
        GenerateRequest(message="make a widget"),
        {"reply": "Done.", "actions": [{"op": "create", "component": "x", "summary": "s", "php": "<?php // no class", "blade": "<div></div>"}]},
        fake_provider_factory,
    )
    assert response.actions == []
    assert any("not a valid component name" in w for w in response.warnings)
