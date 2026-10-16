"""Errors raised by the AI service."""


class ProviderError(RuntimeError):
    """The model provider failed or returned something unusable."""


class ConfigurationError(RuntimeError):
    """The service is missing required configuration (API key, provider name)."""


class GenerationCancelled(Exception):
    """The client went away; stop before spending more on model calls."""
