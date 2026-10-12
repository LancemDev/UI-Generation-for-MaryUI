"""Grounding for generation: MaryUI component reference, usage guides and icon names.

The reference (`maryui_reference.json`) is extracted from the MaryUI version the
preview containers run (see knowledge/extract_maryui.php), so props and icons
offered to the model are the ones that actually exist.
"""

from __future__ import annotations

import difflib
import json
import re
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path

from core.config import get_settings

ALWAYS_INCLUDED_GUIDE = "Livewire component conventions"
MAX_GUIDES = 4

# Shown to the model up front; every name here exists in the bundled Heroicons set
# (enforced by tests). Anything else the model uses is validated against the full set.
COMMON_ICONS = [
    "o-home", "o-user", "o-users", "o-user-group", "o-user-plus", "o-cog-6-tooth",
    "o-bell", "o-envelope", "o-inbox", "o-chat-bubble-left", "o-magnifying-glass",
    "o-funnel", "o-plus", "o-pencil", "o-trash", "o-check", "o-x-mark", "o-eye",
    "o-arrow-path", "o-arrow-down-tray", "o-arrow-top-right-on-square", "o-chevron-right",
    "o-ellipsis-vertical", "o-bars-3", "o-power", "o-lock-closed", "o-key", "o-shield-check",
    "o-calendar", "o-clock", "o-document-text", "o-folder", "o-photo", "o-tag", "o-star",
    "o-heart", "o-sparkles", "o-bolt", "o-light-bulb", "o-rocket-launch", "o-chart-bar",
    "o-chart-pie", "o-presentation-chart-line", "o-arrow-trending-up", "o-arrow-trending-down",
    "o-currency-dollar", "o-banknotes", "o-credit-card", "o-wallet", "o-receipt-percent",
    "o-shopping-cart", "o-shopping-bag", "o-truck", "o-cube", "o-building-office",
    "o-briefcase", "o-map-pin", "o-phone", "o-globe-alt", "o-link", "o-check-circle",
    "o-exclamation-triangle", "o-information-circle", "o-x-circle", "o-question-mark-circle",
    "o-table-cells", "o-list-bullet", "o-clipboard-document-list", "o-squares-2x2",
    "o-academic-cap", "o-book-open", "o-code-bracket", "o-server", "o-cloud", "o-moon", "o-sun",
]

ICON_ATTRIBUTE_PATTERN = re.compile(
    r'\b((?:icon|icon-right|name|true-icon|false-icon|separator|popover-icon))="([ocms]-[a-z0-9-]+)"'
)
WORD_PATTERN = re.compile(r"[a-z0-9]+")
TAG_PATTERN = re.compile(r"<(x-[a-z0-9.:-]+)")
# Blade components the preview app defines itself (preview-reference/app/View/Components).
PREVIEW_TAGS = {"x-app-brand"}


@dataclass(frozen=True)
class Guide:
    title: str
    keywords: frozenset[str]
    components: tuple[str, ...]
    body: str


@dataclass(frozen=True)
class KnowledgeBase:
    mary_version: str
    components: dict[str, dict]
    icons: frozenset[str]
    guides: tuple[Guide, ...]

    def context_for(self, text: str, used_tags: set[str] | None = None) -> str:
        """Build the reference section of the prompt for a request."""

        guides = self.select_guides(text)
        tags = set(used_tags or ())
        for guide in guides:
            tags.update(guide.components)
        tags.update(tag for tag in self.components if _tag_word(tag) in _words(text))

        parts = [f"MaryUI {self.mary_version} reference (only these components and props exist):"]
        parts.extend(self._describe(tag) for tag in sorted(tags) if tag in self.components)
        parts.append("\nAll available component tags: " + ", ".join(sorted(self.components)))
        parts.append("\nCommon Heroicons (outline, prefix o-): " + ", ".join(COMMON_ICONS))
        parts.append("\nUsage guides:")
        parts.extend(f"\n### {g.title}\n{g.body}" for g in guides)
        return "\n".join(parts)

    def select_guides(self, text: str) -> list[Guide]:
        words = _words(text)
        scored = []
        for guide in self.guides:
            if guide.title == ALWAYS_INCLUDED_GUIDE:
                continue
            score = len(words & guide.keywords)
            if score:
                scored.append((score, guide))
        scored.sort(key=lambda item: item[0], reverse=True)

        selected = [g for g in self.guides if g.title == ALWAYS_INCLUDED_GUIDE]
        selected.extend(guide for _, guide in scored[: MAX_GUIDES - 1])
        return selected

    def fix_icons(self, blade: str) -> tuple[str, list[str]]:
        """Replace icon names that don't exist with the closest real one."""

        fixes: list[str] = []

        def replace(match: re.Match) -> str:
            attribute, icon = match.groups()
            if icon in self.icons:
                return match.group(0)
            prefix = icon[:2]
            candidates = [i for i in self.icons if i.startswith(prefix)]
            suggestion = difflib.get_close_matches(icon, candidates, n=1, cutoff=0.6)
            replacement = suggestion[0] if suggestion else f"{prefix}question-mark-circle"
            fixes.append(f"Replaced unknown icon {icon} with {replacement}")
            return f'{attribute}="{replacement}"'

        return ICON_ATTRIBUTE_PATTERN.sub(replace, blade), fixes

    def unknown_tags(self, blade: str) -> list[str]:
        """MaryUI-looking tags in a view that MaryUI does not provide."""

        unknown = []
        for tag in sorted(set(TAG_PATTERN.findall(blade))):
            if tag in self.components or tag in PREVIEW_TAGS or tag.startswith(("x-slot", "x-layouts.")):
                continue
            unknown.append(tag)
        return unknown

    def _describe(self, tag: str) -> str:
        component = self.components[tag]
        props = ", ".join(_format_prop(p) for p in component["props"] if p["name"] != "id")
        line = f"- <{tag}> props: {props or 'none'}"
        if component["slots"]:
            line += f"; named slots: {', '.join(component['slots'])}"
        if component["uses_default_slot"]:
            line += "; accepts inner content"
        return line


def _format_prop(prop: dict) -> str:
    default = prop["default"]
    if default in (None, False, "", "[]"):
        return prop["name"]
    return f"{prop['name']}={default}"


def _tag_word(tag: str) -> str:
    return tag.removeprefix("x-").replace("-", "")


def _words(text: str) -> set[str]:
    words = set()
    for word in WORD_PATTERN.findall(text.lower()):
        words.add(word)
        if len(word) > 3 and word.endswith("s"):
            words.add(word[:-1])
    return words


def parse_guides(markdown: str) -> tuple[Guide, ...]:
    guides = []
    for section in re.split(r"^## ", markdown, flags=re.MULTILINE)[1:]:
        title, _, rest = section.partition("\n")
        keywords: frozenset[str] = frozenset()
        components: tuple[str, ...] = ()
        body_lines = []
        for line in rest.splitlines():
            if line.startswith("keywords:"):
                keywords = frozenset(_words(line.removeprefix("keywords:")))
            elif line.startswith("components:"):
                components = tuple(line.removeprefix("components:").split())
            else:
                body_lines.append(line)
        guides.append(Guide(title.strip(), keywords, components, "\n".join(body_lines).strip()))
    return tuple(guides)


def load_knowledge_base(directory: Path) -> KnowledgeBase:
    reference = json.loads((directory / "maryui_reference.json").read_text())
    guides = parse_guides((directory / "maryui_guides.md").read_text())
    return KnowledgeBase(
        mary_version=reference["mary_version"],
        components={c["tag"]: c for c in reference["components"]},
        icons=frozenset(reference["heroicons"]),
        guides=guides,
    )


@lru_cache
def get_knowledge_base() -> KnowledgeBase:
    return load_knowledge_base(get_settings().knowledge_dir)
