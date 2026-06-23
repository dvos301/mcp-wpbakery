"""Shared transport errors."""


class TransportError(RuntimeError):
    """Could not reach or talk to the site (network/SSH/HTTP/config)."""


class RemoteError(RuntimeError):
    """The plugin ran but returned ok:false (a WordPress/WPBakery-side error)."""
