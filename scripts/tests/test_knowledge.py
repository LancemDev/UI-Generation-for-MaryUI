from services.knowledge import COMMON_ICONS, get_knowledge_base


def test_reference_has_core_components():
    kb = get_knowledge_base()
    for tag in ["x-table", "x-form", "x-input", "x-modal", "x-card", "x-stat", "x-menu-item"]:
        assert tag in kb.components
    assert "x-table.th" not in kb.components


def test_common_icons_all_exist():
    kb = get_knowledge_base()
    missing = [icon for icon in COMMON_ICONS if icon not in kb.icons]
    assert missing == []


def test_guide_tags_exist_in_reference():
    kb = get_knowledge_base()
    for guide in kb.guides:
        for tag in guide.components:
            assert tag in kb.components, f"{guide.title} references unknown {tag}"


def test_table_request_retrieves_table_guide_and_props():
    kb = get_knowledge_base()
    context = kb.context_for("Show my users in a sortable table")
    assert "### Tables" in context
    assert "<x-table> props: headers, rows" in context
    assert "required: headers, rows" in context
    # Static parts live in the cached system prompt, not the per-request context.
    assert "### Livewire component conventions" not in context
    assert "### Livewire component conventions" in kb.static_context()


def test_guides_are_capped():
    kb = get_knowledge_base()
    guides = kb.select_guides("dashboard with table form modal tabs stats sidebar list steps")
    assert len(guides) <= 4


def test_fix_icons_replaces_unknown_and_keeps_valid():
    kb = get_knowledge_base()
    blade = '<x-button icon="o-dollar" /><x-icon name="o-user" /><x-stat icon="o-chart-bar-square" />'
    fixed, fixes = kb.fix_icons(blade)
    assert 'icon="o-dollar"' not in fixed
    assert 'name="o-user"' in fixed
    assert 'icon="o-chart-bar-square"' in fixed
    assert len(fixes) == 1


def test_unknown_tags():
    kb = get_knowledge_base()
    blade = "<div><x-table.th>Name</x-table.th><x-slot:actions /><x-layouts.app-with-sidebar /><x-card /></div>"
    assert kb.unknown_tags(blade) == ["x-table.th"]


def test_check_blade_finds_render_failures_and_prop_typos():
    kb = get_knowledge_base()
    blade = """<div>
        <x-table :headers="$headers" />
        <x-input lable="Name" wire:model="name" placeholder="x" />
        <x-step step="1" text="a > b" />
        <x-icon name="o-user" class="w-4" />
        <x-table.th />
    </div>"""
    errors, warnings = kb.check_blade(blade)
    assert errors == ["<x-table.th> is not a MaryUI component", "<x-table> is missing required prop(s): rows"]
    assert warnings == ["<x-input> has unknown prop 'lable'; did you mean 'label'?"]


def test_check_blade_accepts_camel_case_bound_props_and_spreads():
    kb = get_knowledge_base()
    assert kb.check_blade('<x-table :headers="$h" :rows="$r" :noHover="true" />') == ([], [])
    assert kb.check_blade("<x-table {{ $attributes }} />") == ([], [])
