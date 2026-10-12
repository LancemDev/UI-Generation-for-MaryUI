import pytest

from services.edits import EditError, apply_edits
from services.schema import FileEdit

FILES = {"php": "<?php\nclass A {}\n", "blade": '<div>\n    <x-input label="Email" />\n</div>\n'}


def test_applies_edits_in_order():
    result = apply_edits(FILES, [
        FileEdit(file="blade", search='<x-input label="Email" />', replace='<x-input label="Email" />\n    <x-input label="Phone" />'),
        FileEdit(file="blade", search='label="Phone"', replace='label="Mobile"'),
    ])
    assert 'label="Mobile"' in result["blade"]
    assert result["php"] == FILES["php"]
    assert FILES["blade"].count("x-input") == 1  # input not mutated


def test_tolerates_surrounding_whitespace():
    result = apply_edits(FILES, [FileEdit(file="blade", search='  <x-input label="Email" />  \n', replace=' <x-toggle label="On" /> ')])
    assert '<x-toggle label="On" />' in result["blade"]


@pytest.mark.parametrize("search, message", [("<x-select", "not found"), ("x", "appears"), ("", "empty")])
def test_rejects_missing_ambiguous_and_empty_search(search, message):
    with pytest.raises(EditError, match=message):
        apply_edits({"php": "", "blade": "<x-a/><x-b/>"}, [FileEdit(file="blade", search=search, replace="")])
