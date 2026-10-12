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


def form_component() -> ExistingComponent:
    return ExistingComponent(
        name="RegisterForm",
        route="/register-form",
        php=component_php("RegisterForm"),
        blade='<div>\n    <x-input label="Email" wire:model="email" />\n</div>\n',
    )


def edit_action(search: str, replace: str) -> dict:
    return {
        "op": "update", "component": "RegisterForm", "summary": "Added phone", "php": "", "blade": "",
        "edits": [{"file": "blade", "search": search, "replace": replace}],
    }


def test_edits_are_applied_to_the_current_source(fake_provider_factory):
    response, provider = run(
        GenerateRequest(message="add a phone field", components=[form_component()]),
        {"reply": "Added.", "actions": [edit_action('<x-input label="Email" wire:model="email" />',
                                                    '<x-input label="Email" wire:model="email" />\n    <x-input label="Phone" wire:model="phone" />')]},
        fake_provider_factory,
    )
    action = response.actions[0]
    assert action.op == "update"
    assert 'label="Email"' in action.blade and 'label="Phone"' in action.blade
    assert "class RegisterForm extends Component" in action.php
    assert response.calls == 1 and len(provider.calls) == 1


def test_failed_edits_fall_back_to_complete_files(fake_provider_factory):
    full = {"op": "update", "component": "RegisterForm", "summary": "s", "php": component_php("RegisterForm"),
            "blade": '<div><x-input label="Phone" /></div>', "edits": []}
    response, provider = run(
        GenerateRequest(message="add a phone field", components=[form_component()]),
        [{"reply": "Added.", "actions": [edit_action("<x-select", "nope")]}, {"reply": "ignored", "actions": [full]}],
        fake_provider_factory,
    )
    assert response.reply == "Added."
    assert 'label="Phone"' in response.actions[0].blade
    assert response.calls == 2
    assert "Edits could not be applied" in provider.calls[1]["messages"][-1]["content"]
    assert provider.calls[1]["model"] == get_settings().main_model


def test_static_errors_get_one_repair_call_before_returning(fake_provider_factory):
    broken = {"op": "create", "component": "UserTable", "summary": "Table", "php": component_php("UserTable"),
              "blade": '<div><x-table :headers="$headers" /></div>'}
    fixed = {**broken, "op": "update", "blade": '<div><x-table :headers="$headers" :rows="$rows" /></div>'}
    response, provider = run(
        GenerateRequest(message="users table"),
        [{"reply": "Built it.", "actions": [broken]}, {"reply": "Fixed.", "actions": [fixed]}],
        fake_provider_factory,
    )
    assert response.reply == "Built it."
    assert response.actions[0].op == "create"
    assert ':rows="$rows"' in response.actions[0].blade
    assert "missing required prop(s): rows" in provider.calls[1]["messages"][-1]["content"]
    assert response.calls == 2


def test_fast_model_handles_repairs_and_selections(fake_provider_factory, monkeypatch):
    from dataclasses import replace

    from services.generator import choose_model

    settings = replace(get_settings(), provider="openai", openai_model="big", openai_fast_model="small")
    assert choose_model(GenerateRequest(message="x"), settings) == "big"
    assert choose_model(GenerateRequest(message="x", errors=["A: boom"]), settings) == "small"
    assert choose_model(GenerateRequest(message="x", selection="<button>Save</button>"), settings) == "small"
    assert replace(settings, openai_fast_model="").fast_model == "big"


def test_system_prompt_is_identical_across_requests_for_caching(fake_provider_factory):
    kb = get_knowledge_base()
    first = build_prompt(GenerateRequest(message="a sortable users table"), kb)
    second = build_prompt(GenerateRequest(message="a signup form with a modal", components=[form_component()]), kb)
    assert first.system == second.system
    assert "### Tables" in first.messages[-1]["content"]
    assert "### Tables" not in first.system


def test_selection_is_included_in_the_prompt():
    request = GenerateRequest(message="make it red", components=[form_component()], focus_component="RegisterForm",
                              selection='<button class="btn">Save</button>')
    content = build_prompt(request, get_knowledge_base()).messages[-1]["content"]
    assert '<button class="btn">Save</button>' in content
    assert "limited to it" in content


def test_reply_is_streamed_and_usage_and_timings_reported(fake_provider_factory):
    parts = []
    provider = fake_provider_factory({"reply": "Line one\nwith \"quotes\"", "actions": []})
    response = generate(GenerateRequest(message="hi"), provider, get_knowledge_base(), get_settings(), on_reply=parts.append)
    assert "".join(parts) == "Line one\nwith \"quotes\""
    assert response.usage == {"input_tokens": 10, "cached_input_tokens": 4, "output_tokens": 1}
    assert set(response.timings) == {"model_ms", "total_ms"}
    assert provider.calls[0]["cache_key"] == "skylarr-generate"
