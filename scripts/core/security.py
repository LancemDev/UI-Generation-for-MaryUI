"""Shared-secret authentication between the Laravel app and this service."""

from __future__ import annotations

import hmac

from fastapi import Header, HTTPException, status

from core.config import get_settings


def require_service_token(authorization: str | None = Header(default=None)) -> None:
    token = get_settings().service_token
    if not token:
        return

    supplied = (authorization or "").removeprefix("Bearer ").strip()
    if not hmac.compare_digest(supplied, token):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid service token")
