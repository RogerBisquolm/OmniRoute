import logging
import time
import json
import asyncio
from typing import Dict, Any, Optional
from sqlalchemy import text
from sqlalchemy.ext.asyncio import create_async_engine, AsyncSession, async_sessionmaker
from config import settings

logger = logging.getLogger(__name__)

# Create Async Engines
# pool_size and max_overflow are tuned for high concurrency
master_engine = create_async_engine(
    settings.DB_MASTER_URL,
    pool_size=20,
    max_overflow=10,
    pool_recycle=3600,
    pool_pre_ping=True
)

slave_engine = create_async_engine(
    settings.DB_SLAVE_URL,
    pool_size=20,
    max_overflow=10,
    pool_recycle=3600,
    pool_pre_ping=True
)

# Create Session factories
MasterSessionLocal = async_sessionmaker(master_engine, expire_on_commit=False, class_=AsyncSession)
SlaveSessionLocal = async_sessionmaker(slave_engine, expire_on_commit=False, class_=AsyncSession)

async def init_db_connection():
    """Verify connectivity to master and slave databases on startup."""
    try:
        async with master_engine.connect() as conn:
            await conn.execute(text("SELECT 1"))
        logger.info("Successfully connected to MariaDB Master database.")
    except Exception as e:
        logger.error(f"Failed to connect to MariaDB Master database: {e}")
        raise e

    try:
        async with slave_engine.connect() as conn:
            await conn.execute(text("SELECT 1"))
        logger.info("Successfully connected to MariaDB Slave database replica.")
    except Exception as e:
        logger.error(f"Failed to connect to MariaDB Slave database replica: {e}")
        # We don't raise here, as master could still handle both read/write if slave is starting up.

async def close_db_connection():
    """Dispose of engine pools on shutdown."""
    await master_engine.dispose()
    await slave_engine.dispose()
    logger.info("MariaDB connections closed.")

async def log_token_usage_direct(
    api_key_id: str,
    user_id: Optional[str],
    intent: str,
    model: str,
    prompt_tokens: int,
    completion_tokens: int,
    latency_ms: int,
    cost_usd: float = 0.0
):
    """Log token usage directly to Master DB. Used as fallback if Redis queueing fails."""
    total_tokens = prompt_tokens + completion_tokens
    query = text("""
        INSERT INTO token_logs (api_key_id, user_id, intent, model, prompt_tokens, completion_tokens, total_tokens, latency_ms, cost_usd)
        VALUES (:api_key_id, :user_id, :intent, :model, :prompt_tokens, :completion_tokens, :total_tokens, :latency_ms, :cost_usd)
    """)
    try:
        async with MasterSessionLocal() as session:
            async with session.begin():
                await session.execute(query, {
                    "api_key_id": api_key_id,
                    "user_id": user_id,
                    "intent": intent,
                    "model": model,
                    "prompt_tokens": prompt_tokens,
                    "completion_tokens": completion_tokens,
                    "total_tokens": total_tokens,
                    "latency_ms": latency_ms,
                    "cost_usd": round(cost_usd, 12)
                })
    except Exception as e:
        logger.error(f"Error logging token usage directly to Master DB: {e}")

async def log_token_usage(
    api_key_id: str,
    user_id: Optional[str],
    intent: str,
    model: str,
    prompt_tokens: int,
    completion_tokens: int,
    latency_ms: int,
    cost_usd: float = 0.0
):
    """
    Log token usage and cost by queuing the entry in Redis.
    Falls back to direct DB write if Redis is unavailable.
    """
    from services.cache import redis_client
    
    if not redis_client:
        await log_token_usage_direct(
            api_key_id, user_id, intent, model, prompt_tokens, completion_tokens, latency_ms, cost_usd
        )
        return

    log_entry = {
        "api_key_id": api_key_id,
        "user_id": user_id,
        "intent": intent,
        "model": model,
        "prompt_tokens": prompt_tokens,
        "completion_tokens": completion_tokens,
        "latency_ms": latency_ms,
        "cost_usd": round(cost_usd, 12)
    }
    
    try:
        await redis_client.lpush("gateway:token_logs_queue", json.dumps(log_entry))
        logger.debug(f"Queued token usage log in Redis: {api_key_id} using {model}")
    except Exception as e:
        logger.error(f"Failed to queue token usage in Redis: {e}. Falling back to direct database log.")
        await log_token_usage_direct(
            api_key_id, user_id, intent, model, prompt_tokens, completion_tokens, latency_ms, cost_usd
        )

async def token_logs_batch_writer():
    """
    Background loop that periodically drains the Redis log queue and bulk-inserts into MariaDB.
    """
    logger.info("Token logs batch writer background task started.")
    from services.cache import redis_client
    
    while True:
        try:
            await asyncio.sleep(5.0)  # Batch write every 5 seconds
            
            if not redis_client:
                continue
                
            # Peek at up to 100 oldest logs (from the tail)
            raw_logs = await redis_client.lrange("gateway:token_logs_queue", -100, -1)

            if not raw_logs:
                continue

            parsed_logs = []
            for item in raw_logs:
                try:
                    item_str = item.decode("utf-8") if isinstance(item, bytes) else item
                    log_data = json.loads(item_str)
                    log_data["total_tokens"] = log_data["prompt_tokens"] + log_data["completion_tokens"]
                    log_data["cost_usd"] = round(float(log_data.get("cost_usd", 0.0)), 12)
                    parsed_logs.append(log_data)
                except Exception as parse_err:
                    logger.error(f"Failed to parse log item: {parse_err}")

            if not parsed_logs:
                # If all logs in this batch are corrupted/unparseable, remove them to prevent deadlocks
                async with redis_client.pipeline(transaction=True) as pipe:
                    for _ in range(len(raw_logs)):
                        pipe.rpop("gateway:token_logs_queue")
                    await pipe.execute()
                continue

            query = text("""
                INSERT INTO token_logs (api_key_id, user_id, intent, model, prompt_tokens, completion_tokens, total_tokens, latency_ms, cost_usd)
                VALUES (:api_key_id, :user_id, :intent, :model, :prompt_tokens, :completion_tokens, :total_tokens, :latency_ms, :cost_usd)
            """)
            
            db_success = False
            try:
                async with MasterSessionLocal() as session:
                    async with session.begin():
                        await session.execute(query, parsed_logs)
                db_success = True
            except Exception as db_err:
                logger.error(f"Failed to batch insert token logs to database: {db_err}. Logs will remain in Redis to retry.")
                
            if db_success:
                async with redis_client.pipeline(transaction=True) as pipe:
                    for _ in range(len(raw_logs)):
                        pipe.rpop("gateway:token_logs_queue")
                    await pipe.execute()
                logger.info(f"Successfully batch-inserted {len(parsed_logs)} token logs to MariaDB Master.")
            
        except asyncio.CancelledError:
            logger.info("Shutting down batch writer... draining remaining logs.")
            await drain_remaining_logs()
            break
        except Exception as e:
            logger.error(f"Error in token_logs_batch_writer: {e}")

async def drain_remaining_logs():
    """Drain all remaining logs from Redis and save them to MariaDB on shutdown."""
    from services.cache import redis_client
    if not redis_client:
        return
    try:
        raw_logs = await redis_client.lrange("gateway:token_logs_queue", 0, -1)
        await redis_client.delete("gateway:token_logs_queue")
        if not raw_logs:
            return
            
        parsed_logs = []
        for item in raw_logs:
            try:
                item_str = item.decode("utf-8") if isinstance(item, bytes) else item
                log_data = json.loads(item_str)
                log_data["total_tokens"] = log_data["prompt_tokens"] + log_data["completion_tokens"]
                log_data["cost_usd"] = round(float(log_data.get("cost_usd", 0.0)), 12)
                parsed_logs.append(log_data)
            except Exception:
                pass
                
        if parsed_logs:
            query = text("""
                INSERT INTO token_logs (api_key_id, user_id, intent, model, prompt_tokens, completion_tokens, total_tokens, latency_ms, cost_usd)
                VALUES (:api_key_id, :user_id, :intent, :model, :prompt_tokens, :completion_tokens, :total_tokens, :latency_ms, :cost_usd)
            """)
            async with MasterSessionLocal() as session:
                async with session.begin():
                    await session.execute(query, parsed_logs)
            logger.info(f"Drained and bulk-inserted {len(parsed_logs)} remaining token logs to MariaDB Master.")
    except Exception as e:
        logger.error(f"Failed to drain token logs: {e}")

async def trigger_budget_alert_direct(key_name: str, remaining: float, total: float):
    """
    Directly trigger budget alert notification.
    """
    from services.http_client import get_http_client
    url = "http://laravel/api/alerts"
    payload = {
        "type": "low_budget",
        "api_key_name": key_name,
        "remaining_budget": remaining,
        "total_budget": total
    }
    try:
        client = get_http_client()
        await client.post(url, json=payload, timeout=5.0)
    except Exception as e:
        logger.error(f"Failed to post budget alert: {e}")

async def deduct_api_key_budget(api_key_id: str, api_key_hash: str, cost: float):
    """
    Deduct cost from the API key's remaining budget in MariaDB and update Redis Cache.
    """
    if cost <= 0.0:
        return
        
    rounded_cost = round(cost, 4)
    if rounded_cost > 0.0:
        db_query = text("""
            UPDATE api_keys 
            SET remaining_budget = remaining_budget - :cost 
            WHERE id = :api_key_id
        """)
        
        try:
            async with MasterSessionLocal() as session:
                async with session.begin():
                    await session.execute(db_query, {
                        "api_key_id": api_key_id,
                        "cost": rounded_cost
                    })
            logger.debug(f"Deducted ${rounded_cost:.4f} from API key {api_key_id} budget in DB.")
        except Exception as e:
            logger.error(f"Failed to deduct budget from DB for key {api_key_id}: {e}")
        
    # Update Redis cache & check budget thresholds for alerts
    from services.cache import redis_client
    import json
    if redis_client and api_key_hash:
        redis_key = f"auth:key:{api_key_hash}"
        try:
            cached_data = await redis_client.get(redis_key)
            if cached_data:
                key_meta = json.loads(cached_data)
                remaining = max(0.0, float(key_meta.get("remaining_budget", 0.0)) - cost)
                total = float(key_meta.get("total_budget", 0.0))
                name = key_meta.get("api_key_name", "Unknown Key")
                
                key_meta["remaining_budget"] = remaining
                await redis_client.setex(redis_key, 60, json.dumps(key_meta))
                logger.debug(f"Updated Redis budget cache for key {api_key_id}: remaining ${key_meta['remaining_budget']:.6f}")
                
                # Check low budget threshold (<10%) and send rate-limited alert
                if total > 0.0 and (remaining / total) < 0.10:
                    alert_sent_key = f"auth:alert_sent:{api_key_id}"
                    already_sent = await redis_client.get(alert_sent_key)
                    if not already_sent:
                        # Mark as sent for 1 hour
                        await redis_client.setex(alert_sent_key, 3600, "1")
                        import asyncio
                        asyncio.create_task(trigger_budget_alert_direct(name, remaining, total))
                        logger.info(f"Budget threshold alert queued for API key {api_key_id} ({name})")
        except Exception as e:
            logger.warning(f"Failed to update Redis budget cache / check threshold: {e}")

async def validate_api_key_from_db(api_key_hash: str) -> Optional[Dict[str, Any]]:
    """
    Validate the api key hash against the Slave database replica.
    Returns a dict with key metadata (user_id, api_key_id, active, remaining_budget, allowed_rules) or None if invalid.
    """
    query = text("""
        SELECT id, user_id, status, remaining_budget, total_budget, name, allowed_rules 
        FROM api_keys 
        WHERE key_hash = :key_hash LIMIT 1
    """)
    
    try:
        async with SlaveSessionLocal() as session:
            result = await session.execute(query, {"key_hash": api_key_hash})
            row = result.fetchone()
            if row:
                allowed_rules = None
                if row[6]:
                    try:
                        allowed_rules = json.loads(row[6])
                    except Exception as json_err:
                        logger.warning(f"Failed to parse allowed_rules JSON: {json_err}")
                return {
                    "api_key_id": str(row[0]),
                    "user_id": str(row[1]) if row[1] else None,
                    "active": row[2] == "active" or row[2] == 1,
                    "remaining_budget": float(row[3]),
                    "total_budget": float(row[4]),
                    "api_key_name": str(row[5]),
                    "allowed_rules": allowed_rules
                }
    except Exception as e:
        logger.warning(f"Database api_keys validation failed (table might not exist yet): {e}. Falling back to mock verification.")
        
    # Mock fallback for initial testing:
    # If the database is empty or tables aren't created yet, accept any key starting with "sk-omni-"
    if api_key_hash.startswith("sk-omni-"):
        return {
            "api_key_id": f"key_mock_{api_key_hash[-6:]}",
            "user_id": "user_mock_123",
            "active": True,
            "remaining_budget": 999999.0,
            "total_budget": 999999.0,
            "api_key_name": "Mock Active Key",
            "allowed_rules": None
        }
    return None

async def load_routing_rules_from_db() -> Optional[Dict[str, Any]]:
    """
    Load dynamic routing rules from the database to ensure we have the IDs populated on gateway start.
    """
    query = text("""
        SELECT id, intent, provider, model, url, api_key_env, weight, 
               fallback_provider, fallback_model, fallback_url, fallback_api_key_env 
        FROM routing_rules
    """)
    try:
        async with SlaveSessionLocal() as session:
            result = await session.execute(query)
            rows = result.fetchall()
            if rows:
                rules = {}
                for row in rows:
                    rule_id, intent, provider, model, url, api_key_env, weight, fb_p, fb_m, fb_u, fb_e = row
                    rule = {
                        "id": int(rule_id),
                        "provider": provider,
                        "model": model,
                        "url": url,
                        "api_key_env": api_key_env,
                        "weight": int(weight) if weight is not None else 100,
                        "fallback_provider": fb_p,
                        "fallback_model": fb_m,
                        "fallback_url": fb_u,
                        "fallback_api_key_env": fb_e
                    }
                    if intent not in rules:
                        rules[intent] = []
                    rules[intent].append(rule)
                return rules
    except Exception as e:
        logger.error(f"Failed to load routing rules from DB: {e}")
    return None

async def get_training_samples_from_db() -> Optional[list]:
    """
    Fetch all training samples from the classifier_samples table in MariaDB.
    Returns a list of formatted strings e.g. ["__label__code Write a quicksort in python", ...]
    """
    query = text("SELECT intent, sample_text FROM classifier_samples")
    try:
        async with SlaveSessionLocal() as session:
            result = await session.execute(query)
            rows = result.fetchall()
            if rows:
                return [f"__label__{row[0]} {row[1]}" for row in rows]
    except Exception as e:
        logger.error(f"Failed to fetch training samples from DB: {e}")
    return None


