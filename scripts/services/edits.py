"""Apply the model's search/replace edits to a component's current files."""

from __future__ import annotations

from services.schema import FileEdit


class EditError(ValueError):
    pass


def apply_edits(files: dict[str, str], edits: list[FileEdit]) -> dict[str, str]:
    """Return updated copies of `files` ({"php": ..., "blade": ...}).

    Each search text must occur exactly once in the file as it stands after the
    previous edits; anything else is ambiguous and raises EditError so the caller
    can ask for complete files instead of guessing.
    """

    result = dict(files)
    for number, edit in enumerate(edits, start=1):
        source = result.get(edit.file, "")
        if not edit.search:
            raise EditError(f"edit {number} on the {edit.file} file has empty search text")

        count = source.count(edit.search)
        search = edit.search
        if count == 0:
            # Models often get trailing whitespace or the final newline wrong.
            search = edit.search.strip()
            count = source.count(search) if search else 0
        if count == 0:
            raise EditError(f"edit {number}: search text not found in the {edit.file} file")
        if count > 1:
            raise EditError(f"edit {number}: search text appears {count} times in the {edit.file} file")

        replace = edit.replace if search == edit.search else edit.replace.strip()
        result[edit.file] = source.replace(search, replace, 1)
    return result
