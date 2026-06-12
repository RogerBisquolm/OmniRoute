import httpx
from typing import Optional
import logging

logger = logging.getLogger(__name__)

# Global shared client pool
httpx_client: Optional[httpx.AsyncClient] = None

def get_http_client() -> httpx.AsyncClient:
    """Get the global AsyncClient. Initializes it as fallback if not set."""
    global httpx_client
    if httpx_client is None:
        limits = httpx.Limits(max_keepalive_connections=50, max_connections=200, keepalive_expiry=60.0)
        httpx_client = httpx.AsyncClient(limits=limits)
    return httpx_client

async def init_http_client():
    """Initialize the global shared AsyncClient with connection pooling."""
    global httpx_client
    if httpx_client is None:
        limits = httpx.Limits(max_keepalive_connections=50, max_connections=200, keepalive_expiry=60.0)
        httpx_client = httpx.AsyncClient(limits=limits)
        logger.info("Initialized global shared HTTP client pool with connection pooling.")

async def close_http_client():
    """Dispose of the global AsyncClient pool."""
    global httpx_client
    if httpx_client is not None:
        await httpx_client.aclose()
        httpx_client = None
        logger.info("Closed global shared HTTP client pool.")
