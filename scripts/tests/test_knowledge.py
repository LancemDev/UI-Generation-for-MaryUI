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
    assert "### Livewire component conventions" in context


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
