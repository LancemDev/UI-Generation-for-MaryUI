"""Pull the `reply` string out of a JSON document while it is still being generated."""

from __future__ import annotations

import json
import re

_REPLY_START = re.compile(r'^\s*\{\s*"reply"\s*:\s*"')
_ESCAPE = re.compile(r'\\(?:u[0-9a-fA-F]{4}|[^u])')
_HIGH_SURROGATE = re.compile(r'\\u[dD][89abAB][0-9a-fA-F]{2}')
_SURROGATE_PAIR = re.compile(r'\\u[dD][89abAB][0-9a-fA-F]{2}\\u[dD][c-fC-F][0-9a-fA-F]{2}')


class ReplyExtractor:
    """Feed raw output chunks; get back newly decoded characters of the reply.

    Relies on `reply` being the first property, which the response schema fixes.
    Stops at the closing quote; returns nothing if the document starts differently.
    """

    def __init__(self) -> None:
        self._buffer = ""
        self._start: int | None = None
        self._pos = 0  # next undecoded index into _buffer
        self.done = False

    def feed(self, chunk: str) -> str:
        if self.done:
            return ""
        self._buffer += chunk

        if self._start is None:
            match = _REPLY_START.match(self._buffer)
            if not match:
                # Give up once the prefix can no longer become `{"reply": "`.
                if len(self._buffer) > 64 or not _could_start(self._buffer):
                    self.done = True
                return ""
            self._start = self._pos = match.end()

        out: list[str] = []
        buffer, pos = self._buffer, self._pos
        while pos < len(buffer):
            char = buffer[pos]
            if char == '"':
                self.done = True
                pos += 1
                break
            if char != "\\":
                out.append(char)
                pos += 1
                continue
            match = _SURROGATE_PAIR.match(buffer, pos) or _ESCAPE.match(buffer, pos)
            if not match:
                break  # escape split across chunks; wait for more
            sequence = match.group(0)
            if _HIGH_SURROGATE.fullmatch(sequence) and len(buffer) - match.end() < 6:
                break  # first half of an emoji; wait for the second half
            out.append(json.loads(f'"{sequence}"'))
            pos = match.end()
        self._pos = pos
        return "".join(out)


def _could_start(prefix: str) -> bool:
    target = '{"reply":"'
    compact = re.sub(r"\s+", "", prefix)
    return target.startswith(compact) or compact.startswith(target)
