import os
import json
import time
import logging
import asyncio
import httpx
import copy
from typing import Dict, Any, List, Optional, AsyncGenerator, Union

from fastapi import FastAPI, Request, Depends, HTTPException, Header, status, BackgroundTasks
from fastapi.responses import StreamingResponse, JSONResponse
from pydantic import BaseModel, Field

from config import settings, dynamic_config
from services.db import init_db_connection, close_db_connection, log_token_usage, validate_api_key_from_db, deduct_api_key_budget, load_routing_rules_from_db, token_logs_batch_writer
from services.cache import init_cache, close_cache, check_semantic_cache, save_to_semantic_cache, delete_similar_cache_entry
from services.router import init_router, classify_intent, get_routing_target
from services.compressor import init_compressor, compress_prompt
from services.http_client import init_http_client, close_http_client, get_http_client

# Configure Logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s"
)
logger = logging.getLogger("omniroute_proxy")

# Redis Pub/Sub Background Listener Task
config_listener_task: Optional[asyncio.Task] = None
token_logs_writer_task: Optional[asyncio.Task] = None

# Cache TTL by intent (in seconds)
INTENT_TTL_MAPPING = {
    "code": 604800,       # 7 days (code templates are highly stable)
    "creative": 86400,    # 1 day (creative prompts are moderate)
    "support": 14400,     # 4 hours (support queries might shift, system updates)
    "general": 86400,     # 1 day (general factual information is moderately stable)
}

async def redis_pubsub_listener():
    """Background task to listen for dynamic routing updates from Laravel via Redis Pub/Sub."""
    from services.cache import redis_client
    if not redis_client:
        logger.error("Redis client not initialized. Pub/Sub listener aborted.")
        return

    # Create a pubsub connection
    pubsub = redis_client.pubsub()
    try:
        await pubsub.subscribe(settings.CONFIG_PUBSUB_CHANNEL)
        logger.info(f"Subscribed to Redis channel '{settings.CONFIG_PUBSUB_CHANNEL}' for real-time config reloads.")
        
        async for message in pubsub.listen():
            if message["type"] == "message":
                channel_data = message["data"]
                # Decode bytes to string
                if isinstance(channel_data, bytes):
                    channel_data = channel_data.decode("utf-8")
                logger.info(f"Received config update: {channel_data}")
                try:
                    data = json.loads(channel_data)
                    if isinstance(data, dict) and data.get("action") == "train_fasttext":
                        dataset_path = data.get("dataset_path")
                        from services.router import init_router
                        logger.info(f"Triggering FastText model retraining with dataset: {dataset_path}")
                        asyncio.create_task(init_router(dataset_path))
                    elif isinstance(data, dict) and data.get("action") == "update_rephrase_config":
                        rephrase_config = data.get("rephrase_config", {})
                        dynamic_config.rephrase_enabled = bool(rephrase_config.get("enabled", False))
                        dynamic_config.rephrase_provider = str(rephrase_config.get("provider", "ollama"))
                        dynamic_config.rephrase_model = str(rephrase_config.get("model", "phi3"))
                        if "threshold" in rephrase_config:
                            dynamic_config.cache_threshold = float(rephrase_config["threshold"])
                        logger.info(
                            f"Updated rephrase config from Pub/Sub: enabled={dynamic_config.rephrase_enabled}, "
                            f"provider={dynamic_config.rephrase_provider}, model={dynamic_config.rephrase_model}, "
                            f"threshold={dynamic_config.cache_threshold}"
                        )
                    elif isinstance(data, dict) and data.get("action") == "update_compressor_config":
                        compressor_config = data.get("compressor_config", {})
                        dynamic_config.compressor_method = str(compressor_config.get("method", "llmlingua"))
                        dynamic_config.compressor_ratio = float(compressor_config.get("ratio", 0.70))
                        dynamic_config.compressor_caveman_intensity = str(compressor_config.get("caveman_intensity", "full"))
                        logger.info(
                            f"Updated compressor config from Pub/Sub: method={dynamic_config.compressor_method}, "
                            f"ratio={dynamic_config.compressor_ratio}, caveman_intensity={dynamic_config.compressor_caveman_intensity}"
                        )
                    else:
                        dynamic_config.update_rules(channel_data)
                except Exception as parse_err:
                    logger.warning(f"Error parsing pubsub message: {parse_err}. Processing as routing rules directly...")
                    dynamic_config.update_rules(channel_data)
    except asyncio.CancelledError:
        logger.info("Redis Pub/Sub config listener task cancelled.")
    except Exception as e:
        logger.error(f"Error in Redis Pub/Sub listener: {e}")
    finally:
        await pubsub.unsubscribe(settings.CONFIG_PUBSUB_CHANNEL)
        await pubsub.aclose()

# FastAPI Lifespan Handler
async def lifespan(app: FastAPI):
    global config_listener_task, token_logs_writer_task
    logger.info("Initializing OmniRoute AI Gateway services...")
    
    # 0. Initialize global shared HTTP client pool
    await init_http_client()
    
    # 1. Connect DBs
    await init_db_connection()
    
    # Load rules from DB
    rules = await load_routing_rules_from_db()
    if rules:
        dynamic_config.routing_rules = rules
        logger.info("Loaded routing rules from database on startup.")
    
    # 2. Connect Redis and setup VSS index
    await init_cache()
    
    # Load rephrase configuration from Redis on startup
    from services.cache import redis_client
    if redis_client:
        try:
            enabled_bytes = await redis_client.get("gateway:rephrase_cache_enabled")
            provider_bytes = await redis_client.get("gateway:rephrase_cache_provider")
            model_bytes = await redis_client.get("gateway:rephrase_cache_model")
            threshold_bytes = await redis_client.get("gateway:semantic_cache_threshold")
            
            enabled_str = enabled_bytes.decode("utf-8") if enabled_bytes else "0"
            provider_str = provider_bytes.decode("utf-8") if provider_bytes else "ollama"
            model_str = model_bytes.decode("utf-8") if model_bytes else "phi3"
            threshold_str = threshold_bytes.decode("utf-8") if threshold_bytes else "0.10"
            
            dynamic_config.rephrase_enabled = (enabled_str == "1" or enabled_str.lower() == "true")
            dynamic_config.rephrase_provider = provider_str
            dynamic_config.rephrase_model = model_str
            dynamic_config.cache_threshold = float(threshold_str)
            logger.info(
                f"Loaded rephrase config on startup: enabled={dynamic_config.rephrase_enabled}, "
                f"provider={dynamic_config.rephrase_provider}, model={dynamic_config.rephrase_model}, "
                f"threshold={dynamic_config.cache_threshold}"
            )
            
            # Load compressor configuration on startup
            method_bytes = await redis_client.get("gateway:compressor_method")
            ratio_bytes = await redis_client.get("gateway:compressor_ratio")
            caveman_intensity_bytes = await redis_client.get("gateway:compressor_caveman_intensity")
            
            method_str = method_bytes.decode("utf-8") if method_bytes else "llmlingua"
            ratio_str = ratio_bytes.decode("utf-8") if ratio_bytes else "0.70"
            caveman_intensity_str = caveman_intensity_bytes.decode("utf-8") if caveman_intensity_bytes else "full"
            
            dynamic_config.compressor_method = method_str
            dynamic_config.compressor_ratio = float(ratio_str)
            dynamic_config.compressor_caveman_intensity = caveman_intensity_str
            logger.info(
                f"Loaded compressor config on startup: method={dynamic_config.compressor_method}, "
                f"ratio={dynamic_config.compressor_ratio}, caveman_intensity={dynamic_config.compressor_caveman_intensity}"
            )
        except Exception as e:
            logger.warning(f"Failed to load rephrase/compressor config from Redis on startup: {e}")
            
    # 3. Initialize FastText classifier (auto-train if missing)
    await init_router()
    
    # 4. Initialize LLMLingua compressor (graceful download/load)
    await init_compressor()
    
    # 5. Start Redis Pub/Sub listener
    config_listener_task = asyncio.create_task(redis_pubsub_listener())
    
    # 6. Start token logs batch writer task
    token_logs_writer_task = asyncio.create_task(token_logs_batch_writer())
    
    logger.info("OmniRoute AI Gateway services initialized successfully.")
    yield
    
    # Cleanups
    logger.info("Shutting down OmniRoute AI Gateway services...")
    if token_logs_writer_task:
        token_logs_writer_task.cancel()
        try:
            await token_logs_writer_task
        except asyncio.CancelledError:
            pass
            
    if config_listener_task:
        config_listener_task.cancel()
        try:
            await config_listener_task
        except asyncio.CancelledError:
            pass
            
    await close_db_connection()
    await close_cache()
    await close_http_client()
    logger.info("OmniRoute AI Gateway shutdown complete.")

app = FastAPI(
    title="OmniRoute AI Gateway",
    version="1.0.0",
    description="High-performance, containerized AI API Gateway",
    lifespan=lifespan
)

@app.get("/healthz")
async def healthz():
    """Active health check verifying Redis and Database status."""
    from services.cache import redis_client
    from services.db import master_engine, slave_engine
    from sqlalchemy import text

    redis_ok = False
    master_ok = False
    slave_ok = False

    # Check Redis
    if redis_client:
        try:
            await redis_client.ping()
            redis_ok = True
        except Exception as e:
            logger.error(f"Healthcheck Redis error: {e}")

    # Check DB Master
    try:
        async with master_engine.connect() as conn:
            await conn.execute(text("SELECT 1"))
        master_ok = True
    except Exception as e:
        logger.error(f"Healthcheck DB Master error: {e}")

    # Check DB Slave
    try:
        async with slave_engine.connect() as conn:
            await conn.execute(text("SELECT 1"))
        slave_ok = True
    except Exception as e:
        logger.error(f"Healthcheck DB Slave error: {e}")

    status_code = 200
    if not (redis_ok and master_ok and slave_ok):
        status_code = 503

    return JSONResponse(
        status_code=status_code,
        content={
            "status": "healthy" if status_code == 200 else "unhealthy",
            "services": {
                "redis": "healthy" if redis_ok else "unhealthy",
                "db_master": "healthy" if master_ok else "unhealthy",
                "db_slave": "healthy" if slave_ok else "unhealthy"
            }
        }
    )


# Request Models matching OpenAI specifications
class ChatMessage(BaseModel):
    role: str
    content: Any

class ChatCompletionRequest(BaseModel):
    model: str
    messages: List[ChatMessage]
    temperature: Optional[float] = 1.0
    stream: Optional[bool] = False
    max_tokens: Optional[int] = None

# Authentication Helper
async def authenticate_request(authorization: Optional[str] = Header(None)) -> Dict[str, Any]:
    """
    Authenticate the request by validating the Bearer token.
    Checks Redis first, then queries the MariaDB Slave replica as a fallback.
    """
    if not authorization or not authorization.startswith("Bearer "):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Missing or malformed Authorization header. Use Bearer token."
        )
        
    api_key = authorization.split(" ")[1]
    
    # Compute SHA-256 hash of the plain API key token
    import hashlib
    api_key_hash = hashlib.sha256(api_key.encode('utf-8')).hexdigest()
    
    # 1. Rate Limiting: 60 requests per minute per key
    # Check rate limit before doing DB/cache metadata lookups to protect resources
    from services.cache import redis_client
    if redis_client:
        try:
            RATE_LIMIT = 60
            current_minute = int(time.time() / 60)
            rate_limit_key = f"rate:limit:{api_key_hash}:{current_minute}"
            request_count = await redis_client.incr(rate_limit_key)
            if request_count == 1:
                await redis_client.expire(rate_limit_key, 60)
            if request_count > RATE_LIMIT:
                raise HTTPException(
                    status_code=status.HTTP_429_TOO_MANY_REQUESTS,
                    detail="Too many requests. Limit is 60 requests per minute."
                )
        except HTTPException:
            raise
        except Exception as e:
            logger.warning(f"Rate limiter failed to communicate with Redis: {e}")
            
    # 2. Check Redis Cache for key validity
    from services.cache import redis_client
    redis_key = f"auth:key:{api_key_hash}"
    if redis_client:
        try:
            cached_data = await redis_client.get(redis_key)
            if cached_data:
                key_meta = json.loads(cached_data)
                if not key_meta.get("active"):
                    raise HTTPException(
                        status_code=status.HTTP_401_UNAUTHORIZED,
                        detail="API key is inactive."
                    )
                if key_meta.get("remaining_budget", 0) <= 0:
                    raise HTTPException(
                        status_code=status.HTTP_402_PAYMENT_REQUIRED,
                        detail="API key has exceeded its token budget."
                    )
                key_meta["api_key_hash"] = api_key_hash
                return key_meta
        except HTTPException:
            raise
        except Exception as e:
            logger.warning(f"Failed to fetch auth from Redis: {e}")

    # 2. Query Database Slave Replica
    key_meta = await validate_api_key_from_db(api_key_hash)
    if not key_meta:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid API key."
        )
        
    if not key_meta["active"]:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="API key is inactive."
        )
        
    if key_meta["remaining_budget"] <= 0:
        raise HTTPException(
            status_code=status.HTTP_402_PAYMENT_REQUIRED,
            detail="API key has exceeded its token budget."
        )

    # 3. Cache the verified API key details in Redis for 60s
    if redis_client:
        try:
            await redis_client.setex(redis_key, 60, json.dumps(key_meta))
        except Exception as e:
            logger.warning(f"Failed to cache auth in Redis: {e}")
            
    key_meta["api_key_hash"] = api_key_hash
    return key_meta

@app.get("/v1/models")
@app.get("/models")
async def list_models(auth_meta: Dict[str, Any] = Depends(authenticate_request)):
    """
    List available models that the API key has access to.
    """
    allowed_rules = auth_meta.get("allowed_rules")
    rules = dynamic_config.routing_rules
    
    available_models = set()
    
    for intent, targets in rules.items():
        if isinstance(targets, dict):
            targets = [targets]
        for t in targets:
            # Check if this rule is allowed by the API key
            rule_id = t.get("id")
            if allowed_rules is None or rule_id is None or rule_id in allowed_rules:
                model_name = t.get("model")
                if model_name:
                    available_models.add(model_name)
                fb_model = t.get("fallback_model")
                if fb_model:
                    available_models.add(fb_model)
                    
    # If no models are found, fallback to some default list
    if not available_models:
        available_models = {"gpt-4o-mini", "gemini-1.5-flash", "claude-3-5-sonnet-20240620"}
        
    model_list = []
    for model_id in sorted(available_models):
        owned_by = "openai"
        if "gemini" in model_id:
            owned_by = "google"
        elif "claude" in model_id:
            owned_by = "anthropic"
            
        model_list.append({
            "id": model_id,
            "object": "model",
            "created": int(time.time()),
            "owned_by": owned_by
        })
        
    return {
        "object": "list",
        "data": model_list
    }

async def get_model_prices(model: str, provider: Optional[str] = None, url: Optional[str] = None) -> Dict[str, float]:
    """
    Look up the input and output token rates (USD) for a model.
    Checks Redis hash 'gateway:model_prices' first, falling back to static default rates.
    """
    # Ollama runs locally and is free
    if provider == "ollama" or (url and "ollama" in url):
        return {"input": 0.0, "output": 0.0}

    from services.cache import redis_client
    if redis_client:
        try:
            raw_data = await redis_client.hget("gateway:model_prices", model)
            if raw_data:
                data = json.loads(raw_data)
                return {
                    "input": float(data.get("input", 0.0)),
                    "output": float(data.get("output", 0.0))
                }
        except Exception as e:
            logger.warning(f"Failed to fetch model pricing from Redis for '{model}': {e}")
            
    # Static fallbacks (USD per token)
    defaults = {
        "claude-3-5-sonnet-20240620": {"input": 0.000003, "output": 0.000015},
        "claude-3-5-sonnet": {"input": 0.000003, "output": 0.000015},
        "gpt-4o-mini": {"input": 0.00000015, "output": 0.0000006},
        "gemini-1.5-flash": {"input": 0.000000075, "output": 0.0000003},
        "semantic-cache": {"input": 0.0, "output": 0.0}
    }
    # Match substring/alias
    for key, rate in defaults.items():
        if key in model:
            return rate
            
    return {"input": 0.000002, "output": 0.000010} # Generic fallback

def count_tokens_locally(text: str, model: str) -> int:
    """
    Count tokens locally using tiktoken.
    Falls back to simple whitespace/character heuristic if tiktoken fails.
    """
    try:
        import tiktoken
        try:
            encoding = tiktoken.encoding_for_model(model)
        except KeyError:
            encoding = tiktoken.get_encoding("cl100k_base")
        return len(encoding.encode(text))
    except Exception as e:
        logger.warning(f"Local token counting using tiktoken failed, falling back to heuristic: {e}")
        return int(len(text.split()) * 1.3) + 1

async def trigger_gateway_alert(payload: Dict[str, Any]):
    """
    Asynchronously post an alert notification payload to the Laravel backend.
    """
    # Laravel backend url within docker network is http://laravel:80 (or http://laravel)
    url = "http://laravel/api/alerts"
    try:
        client = get_http_client()
        response = await client.post(url, json=payload, timeout=10.0)
        if response.status_code == 200:
            logger.info("Successfully dispatched alert payload to Laravel backend.")
        else:
            logger.error(f"Laravel alert endpoint returned status {response.status_code}: {response.text}")
    except Exception as e:
        logger.error(f"Failed to dispatch alert to Laravel backend: {e}")

async def check_security_guardrails(prompt: str) -> bool:
    """
    Check if user prompt violates safety rules or contains injections.
    Checks:
    1. Dynamic/Static keyword rules
    2. Redis VSS search against unsafe injection prompt vectors.
    Returns True if blocked, False if safe.
    """
    unsafe_keywords = [
        "ignore previous instructions",
        "system prompt",
        "jailbreak",
        "override settings",
        "you are now a",
        "bypass security",
    ]
    cleaned = prompt.lower()
    
    from services.cache import redis_client, get_embedding
    
    # Try loading dynamic keywords from Redis Set
    if redis_client:
        try:
            dynamic_keys = await redis_client.smembers("gateway:unsafe_keywords")
            if dynamic_keys:
                decoded_keys = [k.decode("utf-8").lower() if isinstance(k, bytes) else k.lower() for k in dynamic_keys]
                for kw in decoded_keys:
                    if kw and kw in cleaned:
                        logger.warning(f"Guardrail block: dynamic keyword '{kw}' detected in prompt.")
                        return True
        except Exception as e:
            logger.warning(f"Failed to fetch dynamic guardrail keywords from Redis: {e}")

    # Fallback/Check static keywords
    for kw in unsafe_keywords:
        if kw in cleaned:
            logger.warning(f"Guardrail block: static keyword '{kw}' detected in prompt.")
            return True
            
    if redis_client:
        try:
            query_vector = await get_embedding(prompt)
            query_vector_bytes = query_vector.tobytes()
            
            from redis.commands.search.query import Query
            knn_query = (
                Query("*=>[KNN 1 @vector $query_vector AS score]")
                .sort_by("score")
                .return_fields("pattern", "score")
                .paging(0, 1)
                .dialect(2)
            )
            
            results = await redis_client.ft("guardrails_idx").search(
                knn_query, 
                query_params={"query_vector": query_vector_bytes}
            )
            
            if results.docs:
                doc = results.docs[0]
                score = float(doc.score)
                # Cosine distance < 0.15 means high similarity (> 0.85)
                if score < 0.15:
                    logger.warning(f"Guardrail block: VSS match detected. Nearest match score: {score:.4f}")
                    return True
        except Exception as e:
            logger.debug(f"Redis VSS guardrail check skipped / no index: {e}")
            
    return False

def resolve_api_key(key_or_env: Optional[str]) -> str:
    """Resolve environment variable name or return raw API key value."""
    if not key_or_env:
        return "mock-key"
    if key_or_env in os.environ:
        return os.environ[key_or_env]
    return key_or_env

# SSE Stream formatting helpers
def format_sse_chunk(content: str, model: str, finish_reason: Optional[str] = None) -> str:
    """Format single chunk to OpenAI SSE standard."""
    chunk = {
        "id": "chatcmpl-omniroute",
        "object": "chat.completion.chunk",
        "created": int(time.time()),
        "model": model,
        "choices": [{
            "index": 0,
            "delta": {"content": content} if content else {},
            "finish_reason": finish_reason
        }]
    }
    return f"data: {json.dumps(chunk)}\n\n"

async def mock_downstream_llm_stream(
    prompt: str, 
    model: str, 
    intent: str
) -> AsyncGenerator[str, None]:
    """Generates a mock stream of text for local sandbox verification."""
    yield format_sse_chunk(f"[Routed Model: {model} | Intent: {intent}]\n", model)
    yield format_sse_chunk("This is a streamed response back from OmniRoute Gateway. ", model)
    
    words = "The gateway successfully processed your prompt. Sequential operations: Semantic cache checked, Intent classified on CPU, Prompt compressed, Dynamic routing rule evaluated, and request streamed. Token tracking is logged in the background database.".split(" ")
    for word in words:
        await asyncio.sleep(0.04) # Simulate network streaming latency (25 tokens/sec)
        yield format_sse_chunk(word + " ", model)
        
    yield format_sse_chunk("", model, finish_reason="stop")
    yield "data: [DONE]\n\n"

async def stream_openai_downstream(
    messages: List[Dict[str, str]],
    model: str,
    url: str,
    temperature: float,
    max_tokens: Optional[int],
    api_key: str,
    api_key_id: str,
    api_key_hash: str,
    user_id: Optional[str],
    intent: str,
    start_time: float,
    last_user_message: str,
    compression_meta: Dict[str, Any],
    background_tasks: BackgroundTasks
) -> AsyncGenerator[str, None]:
    """Stream response from OpenAI completions API and track token usage."""
    if "ollama" in url:
        from services.ollama import ensure_ollama_model
        await ensure_ollama_model(model)

    body = {
        "model": model,
        "messages": messages,
        "temperature": temperature,
        "stream": True,
        "stream_options": {"include_usage": True}
    }
    if max_tokens:
        body["max_tokens"] = max_tokens
        
    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json"
    }
    
    accumulated_text = []
    prompt_tokens = compression_meta["compressed_tokens"]
    completion_tokens = 0
    
    try:
        client = get_http_client()
        async with client.stream("POST", url, json=body, headers=headers, timeout=60.0) as response:
                if response.status_code != 200:
                    error_body = await response.aread()
                    logger.error(f"OpenAI error response: {error_body.decode('utf-8')}")
                    raise httpx.HTTPStatusError(
                        message=f"OpenAI API status {response.status_code}: {error_body.decode('utf-8')}",
                        request=response.request,
                        response=response
                    )
                    
                async for line in response.aiter_lines():
                    if not line.strip():
                        continue
                    
                    yield line + "\n\n"
                    
                    if line.startswith("data: ") and not line.startswith("data: [DONE]"):
                        try:
                            chunk_data = json.loads(line[6:])
                            choices = chunk_data.get("choices", [])
                            if choices:
                                delta = choices[0].get("delta", {})
                                content = delta.get("content", "")
                                if content:
                                    accumulated_text.append(content)
                            usage = chunk_data.get("usage")
                            if usage:
                                prompt_tokens = usage.get("prompt_tokens", prompt_tokens)
                                completion_tokens = usage.get("completion_tokens", completion_tokens)
                        except Exception:
                            pass
                            
    except Exception as e:
        logger.error(f"OpenAI request failed: {e}")
        raise e
        
    full_reply = "".join(accumulated_text)
    latency_ms = int((time.perf_counter() - start_time) * 1000)
    
    if completion_tokens == 0:
        completion_tokens = count_tokens_locally(full_reply, model)
        
    rates = await get_model_prices(model, url=url)
    cost_usd = (prompt_tokens * rates["input"]) + (completion_tokens * rates["output"])
    
    background_tasks.add_task(
        log_token_usage,
        api_key_id=api_key_id,
        user_id=user_id,
        intent=intent,
        model=model,
        prompt_tokens=prompt_tokens,
        completion_tokens=completion_tokens,
        latency_ms=latency_ms,
        cost_usd=cost_usd
    )
    background_tasks.add_task(
        deduct_api_key_budget,
        api_key_id=api_key_id,
        api_key_hash=api_key_hash,
        cost=cost_usd
    )
    
    await save_to_semantic_cache(
        last_user_message,
        {
            "choices": [{
                "message": {
                    "role": "assistant",
                    "content": full_reply
                }
            }]
        },
        ttl=INTENT_TTL_MAPPING.get(intent, 86400)
    )

async def stream_anthropic_downstream(
    messages: List[Dict[str, str]],
    model: str,
    temperature: float,
    max_tokens: Optional[int],
    api_key: str,
    api_key_id: str,
    api_key_hash: str,
    user_id: Optional[str],
    intent: str,
    start_time: float,
    last_user_message: str,
    compression_meta: Dict[str, Any],
    background_tasks: BackgroundTasks
) -> AsyncGenerator[str, None]:
    """Stream response from Anthropic Messages API, translate SSE format, and log token usage."""
    from services.translator import openai_to_anthropic_payload, AnthropicStreamState
    
    anthropic_body = openai_to_anthropic_payload(messages, model, temperature, max_tokens)
    
    headers = {
        "x-api-key": api_key,
        "anthropic-version": "2023-06-01",
        "content-type": "application/json"
    }
    
    state = AnthropicStreamState()
    
    try:
        client = get_http_client()
        async with client.stream("POST", "https://api.anthropic.com/v1/messages", json=anthropic_body, headers=headers, timeout=60.0) as response:
            if response.status_code != 200:
                error_body = await response.aread()
                logger.error(f"Anthropic error response: {error_body.decode('utf-8')}")
                raise httpx.HTTPStatusError(
                    message=f"Anthropic API status {response.status_code}: {error_body.decode('utf-8')}",
                    request=response.request,
                    response=response
                )
                
            async for line in response.aiter_lines():
                if not line.strip():
                    continue
                    
                event_type, openai_chunk = state.feed_line(line, model)
                if openai_chunk:
                    yield openai_chunk
                        
    except Exception as e:
        logger.error(f"Anthropic request failed: {e}")
        raise e
        
    full_reply = state.accumulated_text
    latency_ms = int((time.perf_counter() - start_time) * 1000)
    
    if state.input_tokens > 0:
        prompt_tokens = state.input_tokens
    else:
        last_msg = next((m["content"] for m in reversed(messages) if m["role"] == "user"), "")
        prompt_tokens = count_tokens_locally(last_msg, model)

    completion_tokens = state.output_tokens if state.output_tokens > 0 else count_tokens_locally(full_reply, model)
    
    rates = await get_model_prices(model)
    cost_usd = (prompt_tokens * rates["input"]) + (completion_tokens * rates["output"])
    
    background_tasks.add_task(
        log_token_usage,
        api_key_id=api_key_id,
        user_id=user_id,
        intent=intent,
        model=model,
        prompt_tokens=prompt_tokens,
        completion_tokens=completion_tokens,
        latency_ms=latency_ms,
        cost_usd=cost_usd
    )
    background_tasks.add_task(
        deduct_api_key_budget,
        api_key_id=api_key_id,
        api_key_hash=api_key_hash,
        cost=cost_usd
    )
    
    await save_to_semantic_cache(
        last_user_message,
        {
            "choices": [{
                "message": {
                    "role": "assistant",
                    "content": full_reply
                }
            }]
        },
        ttl=INTENT_TTL_MAPPING.get(intent, 86400)
    )

async def call_openai_downstream(
    messages: List[Dict[str, str]],
    model: str,
    url: str,
    temperature: float,
    max_tokens: Optional[int],
    api_key: str,
) -> Dict[str, Any]:
    """Execute a non-streaming OpenAI completions API request."""
    body = {
        "model": model,
        "messages": messages,
        "temperature": temperature,
        "stream": False
    }
    if max_tokens is not None:
        body["max_tokens"] = max_tokens
        
    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json"
    }
    
    client = get_http_client()
    response = await client.post(url, json=body, headers=headers, timeout=60.0)
    if response.status_code != 200:
        error_body = response.text
        logger.error(f"OpenAI error response: {error_body}")
        raise httpx.HTTPStatusError(
            message=f"OpenAI API status {response.status_code}: {error_body}",
            request=response.request,
            response=response
        )
    return response.json()

async def call_anthropic_downstream(
    messages: List[Dict[str, str]],
    model: str,
    temperature: float,
    max_tokens: Optional[int],
    api_key: str
) -> Dict[str, Any]:
    """Execute a non-streaming Anthropic Messages API request and translate to OpenAI format."""
    from services.translator import openai_to_anthropic_payload
    
    anthropic_body = openai_to_anthropic_payload(messages, model, temperature, max_tokens)
    # Ensure stream is False for non-streaming
    anthropic_body["stream"] = False
    
    headers = {
        "x-api-key": api_key,
        "anthropic-version": "2023-06-01",
        "content-type": "application/json"
    }
    
    client = get_http_client()
    response = await client.post("https://api.anthropic.com/v1/messages", json=anthropic_body, headers=headers, timeout=60.0)
    if response.status_code != 200:
        error_body = response.text
        logger.error(f"Anthropic error response: {error_body}")
        raise httpx.HTTPStatusError(
            message=f"Anthropic API status {response.status_code}: {error_body}",
            request=response.request,
            response=response
        )
        
    data = response.json()
        
    # Translate Anthropic response format to OpenAI format
    content_text = ""
    for content_block in data.get("content", []):
        if content_block.get("type") == "text":
            content_text += content_block.get("text", "")
            
    stop_reason = data.get("stop_reason")
    # Map stop reasons
    finish_reason = "stop"
    if stop_reason == "end_turn":
        finish_reason = "stop"
    elif stop_reason == "max_tokens":
        finish_reason = "length"
    elif stop_reason:
        finish_reason = stop_reason
        
    usage = data.get("usage", {})
    prompt_tokens = usage.get("input_tokens", 0)
    completion_tokens = usage.get("output_tokens", 0)
    
    return {
        "id": "chatcmpl-omniroute",
        "object": "chat.completion",
        "created": int(time.time()),
        "model": model,
        "choices": [{
            "index": 0,
            "message": {
                "role": "assistant",
                "content": content_text
            },
            "finish_reason": finish_reason
        }],
        "usage": {
            "prompt_tokens": prompt_tokens,
            "completion_tokens": completion_tokens,
            "total_tokens": prompt_tokens + completion_tokens
        }
    }

async def rephrase_cached_response(
    question: str,
    cached_answer: str,
    provider: str,
    model: str
) -> str:
    """Rewrite a cached response using Ollama or external API model to fit the tone/structure of the new question."""
    system_prompt = (
        "You are a helpful assistant. You are given a new user question and a previously cached answer "
        "to a very similar question. Your task is to rewrite the cached answer so that it grammatically "
        "and style-wise matches the tone/structure of the new user question, while keeping all factual information "
        "identical. Do not add or remove any facts. Output ONLY the rewritten answer, without any prefixes, "
        "conversational filler, or markdown commentary."
    )
    user_prompt = f"New Question: {question}\nCached Answer: {cached_answer}\nRewritten Answer:"
    
    try:
        if provider == "ollama":
            from services.ollama import ensure_ollama_model
            await ensure_ollama_model(model)
            url = "http://ollama:11434/api/chat"
            body = {
                "model": model,
                "messages": [
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": user_prompt}
                ],
                "stream": False
            }
            client = get_http_client()
            response = await client.post(url, json=body, timeout=10.0)
            if response.status_code == 200:
                res_data = response.json()
                return res_data["message"]["content"].strip()
            else:
                logger.warning(f"Ollama rephrase failed: Status {response.status_code} - {response.text}")
                    
        elif provider == "openai":
            api_key = os.getenv("OPENAI_API_KEY", "mock-key")
            if not api_key.startswith("mock-key"):
                res = await call_openai_downstream(
                    messages=[
                        {"role": "system", "content": system_prompt},
                        {"role": "user", "content": user_prompt}
                    ],
                    model=model,
                    url="https://api.openai.com/v1/chat/completions",
                    temperature=0.3,
                    max_tokens=None,
                    api_key=api_key
                )
                return res["choices"][0]["message"]["content"].strip()
            else:
                return f"[Rephrased by Mock OpenAI ({model})] {cached_answer}"
                
        elif provider == "google":
            api_key = os.getenv("GEMINI_API_KEY", "mock-key")
            if not api_key.startswith("mock-key"):
                res = await call_openai_downstream(
                    messages=[
                        {"role": "system", "content": system_prompt},
                        {"role": "user", "content": user_prompt}
                    ],
                    model=model,
                    url="https://generativelanguage.googleapis.com/v1beta/openai/chat/completions",
                    temperature=0.3,
                    max_tokens=None,
                    api_key=api_key
                )
                return res["choices"][0]["message"]["content"].strip()
            else:
                return f"[Rephrased by Mock Google ({model})] {cached_answer}"
                
        elif provider == "anthropic":
            api_key = os.getenv("ANTHROPIC_API_KEY", "mock-key")
            if not api_key.startswith("mock-key"):
                res = await call_anthropic_downstream(
                    messages=[
                        {"role": "system", "content": system_prompt},
                        {"role": "user", "content": user_prompt}
                    ],
                    model=model,
                    temperature=0.3,
                    max_tokens=None,
                    api_key=api_key
                )
                return res["choices"][0]["message"]["content"].strip()
            else:
                return f"[Rephrased by Mock Anthropic ({model})] {cached_answer}"
                
    except Exception as e:
        logger.warning(f"Failed to rephrase cached response using {provider}/{model}: {e}")
        
    return cached_answer

def extract_text_content(content: Any) -> str:
    if isinstance(content, str):
        return content
    if isinstance(content, list):
        text_parts = []
        for part in content:
            if isinstance(part, dict):
                if part.get("type") == "text":
                    text_parts.append(part.get("text", ""))
            elif isinstance(part, str):
                text_parts.append(part)
        return " ".join(text_parts)
    return ""

# OpenAI-Compatible Completions Endpoint
@app.post("/v1/chat/completions")
async def chat_completions(
    request: Request,
    payload: ChatCompletionRequest,
    background_tasks: BackgroundTasks,
    auth_meta: Dict[str, Any] = Depends(authenticate_request)
):
    start_time = time.perf_counter()
    api_key_id = auth_meta["api_key_id"]
    user_id = auth_meta["user_id"]
    
    # Validate request payload has messages
    if not payload.messages:
        raise HTTPException(status_code=400, detail="Payload must contain a non-empty messages array.")
        
    # Extract original user prompt (we compress the last user prompt in sequence)
    last_user_message = next((m.content for m in reversed(payload.messages) if m.role == "user"), "")
    if not last_user_message:
        raise HTTPException(status_code=400, detail="Could not find a user message in messages history.")
        
    last_user_text = extract_text_content(last_user_message)

    # 0. Check Security Guardrails & Prompt Injection
    if await check_security_guardrails(last_user_text):
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Prompt blocked by security guardrails."
        )

    # 1. Semantic Cache check (aiming for <50ms response)
    # Check and handle cache bypass instructions
    is_bypass = False
    bypass_phrases = [
        "/nocache",
        "nicht vom cache",
        "cache prompt neu",
        "cache nicht berücksichtigen",
        "nicht aus dem cache",
        "kein cache",
        "cache ignorieren",
        "cache erneuern",
        "cache aktualisieren",
        "aktualisiere cache",
        "cache neu laden",
        "ohne cache",
        "cache umgehen",
        "bypass cache",
        "refresh cache",
        "force refresh"
    ]
    
    import re
    def make_phrase_pattern(p):
        pattern = re.escape(p)
        if p[0].isalnum() or p[0] == '_':
            pattern = r'\b' + pattern
        if p[-1].isalnum() or p[-1] == '_':
            pattern = pattern + r'\b'
        return pattern

    escaped_phrases = "|".join(make_phrase_pattern(p) for p in bypass_phrases)
    bypass_pattern = re.compile(
        rf'(?:\s*[,;\-\s:]+\s*)?[\(\[{{-]*\s*(?:{escaped_phrases})\s*[\)\]}}]*(?:\s*[,;\-\s:!?\.]+)?',
        re.IGNORECASE
    )
    
    cleaned_text = last_user_text
    if bypass_pattern.search(last_user_text):
        is_bypass = True
        cleaned_text = bypass_pattern.sub("", last_user_text)
        cleaned_text = re.sub(r'\s+', ' ', cleaned_text).strip()
        if not cleaned_text:
            cleaned_text = last_user_text

    if is_bypass:
        logger.info(f"Cache bypass detected. Cleaned prompt: '{cleaned_text}'")
        
        # Update payload messages so that the downstream model doesn't receive the bypass instructions
        for msg in reversed(payload.messages):
            if msg.role == "user":
                if isinstance(msg.content, list):
                    for part in msg.content:
                        if isinstance(part, dict) and part.get("type") == "text":
                            part["text"] = cleaned_text
                else:
                    msg.content = cleaned_text
                break
                
        last_user_text = cleaned_text
        last_user_message = cleaned_text
        
        # Delete any similar cached entries from Redis
        await delete_similar_cache_entry(cleaned_text, threshold=dynamic_config.cache_threshold)

    cached_response = None
    if not is_bypass:
        cached_response = await check_semantic_cache(
            last_user_text,
            threshold=dynamic_config.cache_threshold
        )
    if cached_response:
        content_original = cached_response["choices"][0]["message"]["content"]
        
        # Rephrase if enabled
        if dynamic_config.rephrase_enabled:
            logger.info(f"Rephrasing cached response using provider '{dynamic_config.rephrase_provider}' model '{dynamic_config.rephrase_model}'")
            rephrased_content = await rephrase_cached_response(
                question=last_user_text,
                cached_answer=content_original,
                provider=dynamic_config.rephrase_provider,
                model=dynamic_config.rephrase_model
            )
        else:
            rephrased_content = content_original

        latency_ms = int((time.perf_counter() - start_time) * 1000)
        
        # Fire-and-forget logging to master db (recording cache hits as 0 tokens used)
        background_tasks.add_task(
            log_token_usage,
            api_key_id=api_key_id,
            user_id=user_id,
            intent="cached",
            model="semantic-cache",
            prompt_tokens=0,
            completion_tokens=0,
            latency_ms=latency_ms
        )

        if payload.stream:
            # Reformat cache response as SSE stream
            async def cache_stream():
                yield format_sse_chunk(rephrased_content, "semantic-cache")
                yield format_sse_chunk("", "semantic-cache", finish_reason="stop")
                yield "data: [DONE]\n\n"
            return StreamingResponse(cache_stream(), media_type="text/event-stream")
        else:
            # Update choice content in JSON response
            response_json = copy.deepcopy(cached_response)
            response_json["choices"][0]["message"]["content"] = rephrased_content
            return JSONResponse(content=response_json)

    # 2. Intent Routing & Fallbacks
    intent = await classify_intent(last_user_text)
    allowed_rules = auth_meta.get("allowed_rules")
    
    routing_target = None
    if intent == "code":
        # Force route to code intent rules
        try:
            routing_target = get_routing_target("code", allowed_rules=allowed_rules)
        except ValueError as e:
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail=str(e)
            )
    else:
        # If not code, first try to match the user's requested payload model
        # Search all active rules across all intents for a match on the model
        requested_model = payload.model
        matched_target = None
        
        # Filter dynamic_config.routing_rules by allowed rules
        rules = dynamic_config.routing_rules
        import random
        candidates = []
        for rule_intent, targets in rules.items():
            if isinstance(targets, dict):
                targets = [targets]
            for t in targets:
                # Check if allowed by rule ID
                rule_id = t.get("id")
                if allowed_rules is None or rule_id is None or rule_id in allowed_rules:
                    if t.get("model") == requested_model or t.get("fallback_model") == requested_model:
                        candidates.append(t)
                        
        if candidates:
            # Perform weighted choice among candidates
            try:
                total_weight = sum(int(t.get("weight", 100)) for t in candidates)
            except Exception:
                total_weight = len(candidates) * 100
            if total_weight <= 0:
                matched_target = candidates[0]
            else:
                r = random.randint(1, total_weight)
                upto = 0
                for t in candidates:
                    try:
                        w = int(t.get("weight", 100))
                    except Exception:
                        w = 100
                    if upto + w >= r:
                        matched_target = t
                        break
                    upto += w
                if not matched_target:
                    matched_target = candidates[0]
            
            routing_target = matched_target
            logger.info(f"User requested model '{requested_model}' matches configured rule. Routing to it.")
            
        if not routing_target:
            # Fall back to classified intent routing
            try:
                routing_target = get_routing_target(intent, allowed_rules=allowed_rules)
            except ValueError as e:
                raise HTTPException(
                    status_code=status.HTTP_403_FORBIDDEN,
                    detail=str(e)
                )
    
    # 3. Prompt Compression (LLMLingua on CPU)
    if isinstance(last_user_message, list):
        compressed_prompt = last_user_message
        compression_meta = {
            "compressed": False,
            "original_tokens": 0,
            "compressed_tokens": 0,
            "ratio": 1.0,
            "bypassed": True,
            "reason": "Multimodal message"
        }
    else:
        compressed_prompt, compression_meta = await compress_prompt(last_user_message)
        
    compressed_prompt_text = extract_text_content(compressed_prompt)
    
    # Reassemble request with compressed prompt
    modified_messages = []
    for msg in payload.messages:
        if msg == payload.messages[-1] and msg.role == "user":
            modified_messages.append({"role": "user", "content": compressed_prompt})
        else:
            modified_messages.append({"role": msg.role, "content": msg.content})

    # Prepare Downstream Request details
    downstream_model = routing_target["model"]
    downstream_provider = routing_target["provider"]
    api_key_env = routing_target["api_key_env"]
    actual_api_key = resolve_api_key(api_key_env)

    logger.info(f"Routing request to provider '{downstream_provider}' using model '{downstream_model}'")

    # 4. Streaming SSE response
    # 4. Streaming SSE response
    if payload.stream:
        # Check if running mock/testing mode
        if actual_api_key.startswith("mock-key"):
            async def failover_mock_stream_generator():
                try:
                    # If simulate-failover is present, raise RequestError to trigger failover block
                    if "simulate-failover" in compressed_prompt_text:
                        raise httpx.RequestError("Simulated primary connection failure")
                        
                    content_accumulator = []
                    async for chunk in mock_downstream_llm_stream(compressed_prompt_text, downstream_model, intent):
                        yield chunk
                        if chunk.startswith("data: ") and not chunk.startswith("data: [DONE]"):
                            try:
                                chunk_data = json.loads(chunk[6:])
                                text = chunk_data["choices"][0]["delta"].get("content", "")
                                if text:
                                    content_accumulator.append(text)
                            except Exception:
                                pass
                    
                    full_reply = "".join(content_accumulator)
                    latency_ms = int((time.perf_counter() - start_time) * 1000)
                    rates = await get_model_prices(downstream_model, provider=downstream_provider)
                    prompt_tokens = count_tokens_locally(compressed_prompt_text, downstream_model)
                    completion_tokens = count_tokens_locally(full_reply, downstream_model)
                    cost_usd = (prompt_tokens * rates["input"]) + (completion_tokens * rates["output"])
                    
                    background_tasks.add_task(
                        log_token_usage,
                        api_key_id=api_key_id,
                        user_id=user_id,
                        intent=intent,
                        model=downstream_model,
                        prompt_tokens=prompt_tokens,
                        completion_tokens=completion_tokens,
                        latency_ms=latency_ms,
                        cost_usd=cost_usd
                    )
                    background_tasks.add_task(
                        deduct_api_key_budget,
                        api_key_id=api_key_id,
                        api_key_hash=auth_meta["api_key_hash"],
                        cost=cost_usd
                    )
                    await save_to_semantic_cache(
                        last_user_message,
                        {"choices": [{"message": {"role": "assistant", "content": full_reply}}]},
                        ttl=INTENT_TTL_MAPPING.get(intent, 86400)
                    )
                except Exception as primary_error:
                    logger.warning(f"Mock primary provider failed: {primary_error}. Checking fallback...")
                    
                    fallback_provider = routing_target.get("fallback_provider")
                    fallback_model = routing_target.get("fallback_model")
                    fallback_url = routing_target.get("fallback_url")
                    fallback_key_env = routing_target.get("fallback_api_key_env")
                    
                    if fallback_provider and fallback_model:
                        fallback_api_key = resolve_api_key(fallback_key_env)
                        logger.info(f"Mock failing over to provider '{fallback_provider}' model '{fallback_model}'")
                        
                        yield format_sse_chunk(f"\n[Gateway Failover: Routing request to backup model {fallback_model}...]\n", fallback_model)
                        
                        background_tasks.add_task(
                            trigger_gateway_alert,
                            {
                                "type": "failover",
                                "intent": intent,
                                "primary_model": downstream_model,
                                "fallback_model": fallback_model,
                                "error": str(primary_error)
                            }
                        )
                        
                        # Stream mock response from fallback
                        content_accumulator = []
                        async for chunk in mock_downstream_llm_stream(compressed_prompt_text, fallback_model, intent):
                            yield chunk
                            if chunk.startswith("data: ") and not chunk.startswith("data: [DONE]"):
                                try:
                                    chunk_data = json.loads(chunk[6:])
                                    text = chunk_data["choices"][0]["delta"].get("content", "")
                                    if text:
                                        content_accumulator.append(text)
                                except Exception:
                                    pass
                        
                        full_reply = "".join(content_accumulator)
                        latency_ms = int((time.perf_counter() - start_time) * 1000)
                        rates = await get_model_prices(fallback_model, provider=fallback_provider)
                        prompt_tokens = count_tokens_locally(compressed_prompt_text, fallback_model)
                        completion_tokens = count_tokens_locally(full_reply, fallback_model)
                        cost_usd = (prompt_tokens * rates["input"]) + (completion_tokens * rates["output"])
                        
                        background_tasks.add_task(
                            log_token_usage,
                            api_key_id=api_key_id,
                            user_id=user_id,
                            intent=intent,
                            model=fallback_model,
                            prompt_tokens=prompt_tokens,
                            completion_tokens=completion_tokens,
                            latency_ms=latency_ms,
                            cost_usd=cost_usd
                        )
                        background_tasks.add_task(
                            deduct_api_key_budget,
                            api_key_id=api_key_id,
                            api_key_hash=auth_meta["api_key_hash"],
                            cost=cost_usd
                        )
                        await save_to_semantic_cache(
                            last_user_message,
                            {"choices": [{"message": {"role": "assistant", "content": full_reply}}]},
                            ttl=INTENT_TTL_MAPPING.get(intent, 86400)
                        )
                    else:
                        yield format_sse_chunk(f"Gateway connection error: {str(primary_error)}", downstream_model)
                        yield "data: [DONE]\n\n"

            return StreamingResponse(failover_mock_stream_generator(), media_type="text/event-stream")
        
        else:
            # Format raw message payload for external clients
            messages_list = [{"role": m.role, "content": m.content} for m in payload.messages]
            # Replace the last user message with the compressed version
            for i in range(len(messages_list) - 1, -1, -1):
                if messages_list[i]["role"] == "user":
                    messages_list[i]["content"] = compressed_prompt
                    break
                    
            async def failover_stream_generator():
                try:
                    if downstream_provider == "anthropic":
                        async for chunk in stream_anthropic_downstream(
                            messages=messages_list,
                            model=downstream_model,
                            temperature=payload.temperature or 1.0,
                            max_tokens=payload.max_tokens,
                            api_key=actual_api_key,
                            api_key_id=api_key_id,
                            api_key_hash=auth_meta["api_key_hash"],
                            user_id=user_id,
                            intent=intent,
                            start_time=start_time,
                            last_user_message=last_user_message,
                            compression_meta=compression_meta,
                            background_tasks=background_tasks
                        ):
                            yield chunk
                    else:
                        async for chunk in stream_openai_downstream(
                            messages=messages_list,
                            model=downstream_model,
                            url=routing_target["url"],
                            temperature=payload.temperature or 1.0,
                            max_tokens=payload.max_tokens,
                            api_key=actual_api_key,
                            api_key_id=api_key_id,
                            api_key_hash=auth_meta["api_key_hash"],
                            user_id=user_id,
                            intent=intent,
                            start_time=start_time,
                            last_user_message=last_user_message,
                            compression_meta=compression_meta,
                            background_tasks=background_tasks
                        ):
                            yield chunk
                except Exception as primary_error:
                    logger.warning(f"Primary provider failed: {primary_error}. Checking fallback...")
                    
                    fallback_provider = routing_target.get("fallback_provider")
                    fallback_model = routing_target.get("fallback_model")
                    fallback_url = routing_target.get("fallback_url")
                    fallback_key_env = routing_target.get("fallback_api_key_env")
                    
                    if fallback_provider and fallback_model:
                        fallback_api_key = resolve_api_key(fallback_key_env)
                        logger.info(f"Failing over to provider '{fallback_provider}' model '{fallback_model}'")
                        
                        yield format_sse_chunk(f"\n[Gateway Failover: Routing request to backup model {fallback_model}...]\n", fallback_model)
                        
                        background_tasks.add_task(
                            trigger_gateway_alert,
                            {
                                "type": "failover",
                                "intent": intent,
                                "primary_model": downstream_model,
                                "fallback_model": fallback_model,
                                "error": str(primary_error)
                            }
                        )
                        
                        try:
                            if fallback_provider == "anthropic":
                                async for chunk in stream_anthropic_downstream(
                                    messages=messages_list,
                                    model=fallback_model,
                                    temperature=payload.temperature or 1.0,
                                    max_tokens=payload.max_tokens,
                                    api_key=fallback_api_key,
                                    api_key_id=api_key_id,
                                    api_key_hash=auth_meta["api_key_hash"],
                                    user_id=user_id,
                                    intent=intent,
                                    start_time=time.perf_counter(),
                                    last_user_message=last_user_message,
                                    compression_meta=compression_meta,
                                    background_tasks=background_tasks
                                ):
                                    yield chunk
                            else:
                                async for chunk in stream_openai_downstream(
                                    messages=messages_list,
                                    model=fallback_model,
                                    url=fallback_url or "https://api.openai.com/v1/chat/completions",
                                    temperature=payload.temperature or 1.0,
                                    max_tokens=payload.max_tokens,
                                    api_key=fallback_api_key,
                                    api_key_id=api_key_id,
                                    api_key_hash=auth_meta["api_key_hash"],
                                    user_id=user_id,
                                    intent=intent,
                                    start_time=time.perf_counter(),
                                    last_user_message=last_user_message,
                                    compression_meta=compression_meta,
                                    background_tasks=background_tasks
                                ):
                                    yield chunk
                        except Exception as fallback_error:
                            logger.error(f"Fallback provider also failed: {fallback_error}")
                            yield format_sse_chunk(f"Gateway error: Backup provider also failed.", fallback_model)
                            yield "data: [DONE]\n\n"
                    else:
                        yield format_sse_chunk(f"Gateway connection error: {str(primary_error)}", downstream_model)
                        yield "data: [DONE]\n\n"

            return StreamingResponse(failover_stream_generator(), media_type="text/event-stream")
                
    else:
        # Non-streaming response implementation
        # Format raw message payload for external clients
        messages_list = [{"role": m.role, "content": m.content} for m in payload.messages]
        # Replace the last user message with the compressed version
        for i in range(len(messages_list) - 1, -1, -1):
            if messages_list[i]["role"] == "user":
                messages_list[i]["content"] = compressed_prompt
                break

        # Check if running mock/testing mode
        if actual_api_key.startswith("mock-key"):
            try:
                if "simulate-failover" in compressed_prompt_text:
                    raise httpx.RequestError("Simulated primary connection failure")
                
                # Mock Completion Response:
                mock_reply = f"Hello! This is a non-streamed mock completion from {downstream_model} (classified: {intent})."
                
                rates = await get_model_prices(downstream_model, provider=downstream_provider)
                prompt_tokens = count_tokens_locally(compressed_prompt_text, downstream_model)
                completion_tokens = count_tokens_locally(mock_reply, downstream_model)
                cost_usd = (prompt_tokens * rates["input"]) + (completion_tokens * rates["output"])

                response_json = {
                    "id": "chatcmpl-omniroute",
                    "object": "chat.completion",
                    "created": int(time.time()),
                    "model": downstream_model,
                    "choices": [{
                        "index": 0,
                        "message": {
                            "role": "assistant",
                            "content": mock_reply
                        },
                        "finish_reason": "stop"
                    }],
                    "usage": {
                        "prompt_tokens": prompt_tokens,
                        "completion_tokens": completion_tokens,
                        "total_tokens": prompt_tokens + completion_tokens
                    }
                }
                
                # Save to semantic cache
                await save_to_semantic_cache(last_user_message, response_json, ttl=INTENT_TTL_MAPPING.get(intent, 86400))
                
                # Async DB log
                background_tasks.add_task(
                    log_token_usage,
                    api_key_id=api_key_id,
                    user_id=user_id,
                    intent=intent,
                    model=downstream_model,
                    prompt_tokens=prompt_tokens,
                    completion_tokens=completion_tokens,
                    latency_ms=int((time.perf_counter() - start_time) * 1000),
                    cost_usd=cost_usd
                )
                background_tasks.add_task(
                    deduct_api_key_budget,
                    api_key_id=api_key_id,
                    api_key_hash=auth_meta["api_key_hash"],
                    cost=cost_usd
                )
                
                return JSONResponse(content=response_json)
                
            except Exception as primary_error:
                logger.warning(f"Mock primary provider failed: {primary_error}. Checking fallback...")
                
                fallback_provider = routing_target.get("fallback_provider")
                fallback_model = routing_target.get("fallback_model")
                fallback_url = routing_target.get("fallback_url")
                fallback_key_env = routing_target.get("fallback_api_key_env")
                
                if fallback_provider and fallback_model:
                    fallback_api_key = resolve_api_key(fallback_key_env)
                    logger.info(f"Mock failing over to provider '{fallback_provider}' model '{fallback_model}'")
                    
                    background_tasks.add_task(
                        trigger_gateway_alert,
                        {
                            "type": "failover",
                            "intent": intent,
                            "primary_model": downstream_model,
                            "fallback_model": fallback_model,
                            "error": str(primary_error)
                        }
                    )
                    
                    mock_reply = f"Hello! [Gateway Failover: Routing request to backup model {fallback_model}...] This is a non-streamed mock completion from {fallback_model} (classified: {intent})."
                    
                    rates = await get_model_prices(fallback_model, provider=fallback_provider)
                    prompt_tokens = count_tokens_locally(compressed_prompt_text, fallback_model)
                    completion_tokens = count_tokens_locally(mock_reply, fallback_model)
                    cost_usd = (prompt_tokens * rates["input"]) + (completion_tokens * rates["output"])

                    response_json = {
                        "id": "chatcmpl-omniroute",
                        "object": "chat.completion",
                        "created": int(time.time()),
                        "model": fallback_model,
                        "choices": [{
                            "index": 0,
                            "message": {
                                "role": "assistant",
                                "content": mock_reply
                            },
                            "finish_reason": "stop"
                        }],
                        "usage": {
                            "prompt_tokens": prompt_tokens,
                            "completion_tokens": completion_tokens,
                            "total_tokens": prompt_tokens + completion_tokens
                        }
                    }
                    
                    # Save to semantic cache
                    await save_to_semantic_cache(last_user_message, response_json, ttl=INTENT_TTL_MAPPING.get(intent, 86400))
                    
                    # Async DB log
                    background_tasks.add_task(
                        log_token_usage,
                        api_key_id=api_key_id,
                        user_id=user_id,
                        intent=intent,
                        model=fallback_model,
                        prompt_tokens=prompt_tokens,
                        completion_tokens=completion_tokens,
                        latency_ms=int((time.perf_counter() - start_time) * 1000),
                        cost_usd=cost_usd
                    )
                    background_tasks.add_task(
                        deduct_api_key_budget,
                        api_key_id=api_key_id,
                        api_key_hash=auth_meta["api_key_hash"],
                        cost=cost_usd
                    )
                    
                    return JSONResponse(content=response_json)
                else:
                    raise HTTPException(status_code=502, detail=f"Gateway connection error: {str(primary_error)}")
        
        else:
            # Real Non-streaming request flow
            async def execute_non_stream_request(
                prov: str,
                mdl: str,
                endpoint_url: str,
                key: str
            ) -> Dict[str, Any]:
                if prov == "ollama" or "ollama" in endpoint_url:
                    from services.ollama import ensure_ollama_model
                    await ensure_ollama_model(mdl)
                if prov == "anthropic":
                    return await call_anthropic_downstream(
                        messages=messages_list,
                        model=mdl,
                        temperature=payload.temperature or 1.0,
                        max_tokens=payload.max_tokens,
                        api_key=key
                    )
                else:
                    return await call_openai_downstream(
                        messages=messages_list,
                        model=mdl,
                        url=endpoint_url,
                        temperature=payload.temperature or 1.0,
                        max_tokens=payload.max_tokens,
                        api_key=key
                    )
                    
            try:
                response_json = await execute_non_stream_request(
                    downstream_provider,
                    downstream_model,
                    routing_target["url"],
                    actual_api_key
                )
            except Exception as primary_error:
                logger.warning(f"Primary provider failed in non-streaming: {primary_error}. Checking fallback...")
                
                fallback_provider = routing_target.get("fallback_provider")
                fallback_model = routing_target.get("fallback_model")
                fallback_url = routing_target.get("fallback_url")
                fallback_key_env = routing_target.get("fallback_api_key_env")
                
                if fallback_provider and fallback_model:
                    fallback_api_key = resolve_api_key(fallback_key_env)
                    logger.info(f"Failing over to provider '{fallback_provider}' model '{fallback_model}' in non-streaming")
                    
                    background_tasks.add_task(
                        trigger_gateway_alert,
                        {
                            "type": "failover",
                            "intent": intent,
                            "primary_model": downstream_model,
                            "fallback_model": fallback_model,
                            "error": str(primary_error)
                        }
                    )
                    
                    # Check if fallback key is mock-key
                    if fallback_api_key.startswith("mock-key"):
                        mock_reply = f"Hello! [Gateway Failover: Routing request to backup model {fallback_model}...] This is a non-streamed mock completion from {fallback_model} (classified: {intent})."
                        rates = await get_model_prices(fallback_model, provider=fallback_provider)
                        prompt_tokens = count_tokens_locally(compressed_prompt_text, fallback_model)
                        completion_tokens = count_tokens_locally(mock_reply, fallback_model)
                        cost_usd = (prompt_tokens * rates["input"]) + (completion_tokens * rates["output"])
                        
                        response_json = {
                            "id": "chatcmpl-omniroute",
                            "object": "chat.completion",
                            "created": int(time.time()),
                            "model": fallback_model,
                            "choices": [{
                                "index": 0,
                                "message": {
                                    "role": "assistant",
                                    "content": mock_reply
                                },
                                "finish_reason": "stop"
                            }],
                            "usage": {
                                "prompt_tokens": prompt_tokens,
                                "completion_tokens": completion_tokens,
                                "total_tokens": prompt_tokens + completion_tokens
                            }
                        }
                    else:
                        try:
                            response_json = await execute_non_stream_request(
                                fallback_provider,
                                fallback_model,
                                fallback_url or "https://api.openai.com/v1/chat/completions",
                                fallback_api_key
                            )
                        except Exception as fallback_error:
                            logger.error(f"Fallback provider also failed in non-streaming: {fallback_error}")
                            raise HTTPException(
                                status_code=status.HTTP_502_BAD_GATEWAY,
                                detail=f"Gateway error: Backup provider also failed. Error: {str(fallback_error)}"
                            )
                else:
                    raise HTTPException(
                        status_code=status.HTTP_502_BAD_GATEWAY,
                        detail=f"Gateway connection error: {str(primary_error)}"
                    )

            # Log and Cache final response (whether from primary or fallback)
            latency_ms = int((time.perf_counter() - start_time) * 1000)
            
            final_model = response_json.get("model", downstream_model)
            usage = response_json.get("usage", {})
            prompt_tokens = usage.get("prompt_tokens", 0)
            completion_tokens = usage.get("completion_tokens", 0)
            
            # If tokens are not provided, estimate
            if prompt_tokens == 0:
                prompt_tokens = count_tokens_locally(compressed_prompt_text, final_model)
            if completion_tokens == 0:
                reply_text = response_json["choices"][0]["message"].get("content", "")
                completion_tokens = count_tokens_locally(reply_text, final_model)
                
            rates = await get_model_prices(final_model, provider=downstream_provider, url=routing_target.get("url"))
            cost_usd = (prompt_tokens * rates["input"]) + (completion_tokens * rates["output"])
            
            # Update usage block in the response_json
            response_json["usage"] = {
                "prompt_tokens": prompt_tokens,
                "completion_tokens": completion_tokens,
                "total_tokens": prompt_tokens + completion_tokens
            }

            # Save to semantic cache
            await save_to_semantic_cache(last_user_message, response_json, ttl=INTENT_TTL_MAPPING.get(intent, 86400))
            
            # Async DB log
            background_tasks.add_task(
                log_token_usage,
                api_key_id=api_key_id,
                user_id=user_id,
                intent=intent,
                model=final_model,
                prompt_tokens=prompt_tokens,
                completion_tokens=completion_tokens,
                latency_ms=latency_ms,
                cost_usd=cost_usd
            )
            background_tasks.add_task(
                deduct_api_key_budget,
                api_key_id=api_key_id,
                api_key_hash=auth_meta["api_key_hash"],
                cost=cost_usd
            )
            
            return JSONResponse(content=response_json)
