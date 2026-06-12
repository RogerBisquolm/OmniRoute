import os
import logging
import tempfile
import asyncio
import fasttext
from typing import Tuple, Dict, Any, Optional, List
from config import settings, dynamic_config

logger = logging.getLogger(__name__)

# Global FastText model instance
ft_model = None

# Sample dataset to train a basic intent classifier if no model exists
TRAINING_SAMPLES = [
    # Code intent (English - Set 1)
    "__label__code Write a quicksort algorithm in python",
    "__label__code How to debug a segment fault in C++",
    "__label__code SQL query to select all users with active status",
    "__label__code Implement a binary search tree in Golang",
    "__label__code Fix this syntax error in typescript",
    "__label__code docker-compose configuration for fastapi and redis",
    "__label__code git command to squash commits",
    "__label__code How do I write a web scraper in Node.js?",
    "__label__code write a python script to parse csv data",
    "__label__code what is the difference between interface and abstract class?",
    # Code intent (English - Set 2)
    "__label__code write a quicksort implementation in python",
    "__label__code how do i code a binary search tree in java",
    "__label__code optimize mariadb database query execution plan",
    "__label__code regular expression for email address validation",
    "__label__code implement a doubly linked list in rust",
    "__label__code configure docker-compose for fastapi backend and redis cache",
    "__label__code how to fix typescript compilation errors",
    "__label__code git command for squashing last three commits",
    "__label__code building a custom web scraper with node.js",
    
    # Creative intent (English - Set 1)
    "__label__creative Write a short story about a time traveler who gets stuck in 1920",
    "__label__creative Compose a poem about ocean waves during a thunderstorm",
    "__label__creative Generate 10 catchy names for a futuristic coffee shop",
    "__label__creative Draft a screenplay scene where two spies meet in a museum",
    "__label__creative Brainstorm marketing ideas for an eco-friendly water bottle",
    "__label__creative Write a song about coding late at night",
    "__label__creative help me write a blog post about artificial intelligence in art",
    "__label__creative Create a description for a fantasy game world",
    "__label__creative Write an email script to pitch a business idea",
    # Creative intent (English - Set 2)
    "__label__creative write a story about time travel adventures",
    "__label__creative compose a music sheet in g major scale",
    "__label__creative brainstorm catchy names for a technology startup",
    "__label__creative draft an intro hook for a philosophy podcast",
    "__label__creative write a bedtime story about little wolf cubs",
    "__label__creative write a beautiful poem about autumn leaves",
    "__label__creative creative ideas for a school art project",
    "__label__creative write a short screenplay for a detective investigation scene",
    "__label__creative make up a fantasy story about a mysterious forest and wolves",
    
    # Support intent (English - Set 1)
    "__label__support I forgot my account password, how can I reset it?",
    "__label__support Where can I view and download my billing invoices?",
    "__label__support My subscription payment failed, please help.",
    "__label__support How do I cancel my monthly subscription plan?",
    "__label__support My account is locked, how can I unlock it?",
    "__label__support I need to update my email address on my profile.",
    "__label__support Can I request a refund for my last transaction?",
    "__label__support I am experiencing high latency, is there a server outage?",
    "__label__support Contact support department phone number",
    # Support intent (English - Set 2)
    "__label__support my login session expired help me log in",
    "__label__support support ticket for password reset request",
    "__label__support billing invoice was sent to the wrong email address",
    "__label__support request refund for failed transaction charge",
    "__label__support how can i cancel my monthly premium subscription",
    "__label__support unlock my locked user account",
    "__label__support customer service support hotline phone number",
    "__label__support check if there is an active server outage today",
    
    # General / Chat intent (English - Set 1)
    "__label__general What is the capital city of Switzerland?",
    "__label__general How far is the Earth from the Moon?",
    "__label__general Who wrote the novel Pride and Prejudice?",
    "__label__general Can you explain photosynthesis in simple terms?",
    "__label__general Give me a recipe for chocolate chip cookies.",
    "__label__general What is the speed of light?",
    "__label__general Tell me a funny joke.",
    "__label__general What is the weather like in Tokyo in June?",
    "__label__general How many elements are in the periodic table?",
    "__label__general What are some tips for visiting Switzerland?",
    # General / Chat intent (English - Set 2)
    "__label__general what is the definition of photosynthesis in plants",
    "__label__general distance between planet earth and the moon",
    "__label__general name the capital city of switzerland",
    "__label__general search for a chocolate chip cookies recipe",
    "__label__general tell me a funny dad joke",
    "__label__general what is the current weather forecast for tokyo",
    "__label__general how many chemical elements are in the periodic table",
    "__label__general travel tips for planning a vacation to italy"
]

def auto_train_model(model_path: str, samples: List[str]):
    """Train a simple FastText model on the spot and save it."""
    logger.info("Automatically training a FastText intent model...")
    os.makedirs(os.path.dirname(model_path), exist_ok=True)
    
    with tempfile.NamedTemporaryFile(mode='w', suffix='.txt', delete=False) as temp_file:
        for sample in samples:
            temp_file.write(sample + "\n")
        temp_path = temp_file.name
        
    try:
        # Train a supervised classifier
        model = fasttext.train_supervised(
            input=temp_path,
            epoch=50,
            lr=0.5,
            wordNgrams=2,
            dim=20,
            bucket=50000,
            loss='softmax'
        )
        model.save_model(model_path)
        logger.info(f"FastText intent model trained and saved to: {model_path}")
    finally:
        if os.path.exists(temp_path):
            os.remove(temp_path)

async def init_router(dataset_path: Optional[str] = None):
    """Load or train FastText model on startup or retrain trigger."""
    global ft_model
    model_path = settings.FASTTEXT_MODEL_PATH
    
    # Check if we need to retrain (always retrain on dynamic trigger or if no model exists)
    if not os.path.exists(model_path) or dataset_path is not None or ft_model is not None:
        if dataset_path and os.path.exists(dataset_path):
            logger.info(f"Loading training samples from dataset file: {dataset_path}")
            with open(dataset_path, 'r') as f:
                samples = [line.strip() for line in f if line.strip()]
        else:
            logger.info("Loading training samples from MariaDB database...")
            from services.db import get_training_samples_from_db
            db_samples = await get_training_samples_from_db()
            if db_samples:
                samples = db_samples
                logger.info(f"Loaded {len(samples)} training samples from database.")
            else:
                logger.warning("No training samples found in database. Using static defaults.")
                samples = TRAINING_SAMPLES
        
        # Run training in thread pool to prevent blocking loop
        await asyncio.to_thread(auto_train_model, model_path, samples)
        
    try:
        # FastText loading is sync, wrap in to_thread
        ft_model = await asyncio.to_thread(fasttext.load_model, model_path)
        logger.info("FastText model loaded successfully.")
    except Exception as e:
        logger.error(f"Failed to load FastText model: {e}")
        raise e

async def classify_intent(prompt: str) -> str:
    """
    Classify the incoming prompt's intent.
    Returns: intent label ('code', 'creative', 'support', 'general')
    """
    # Clean prompt text: single line, lowercase, strip
    cleaned_prompt = prompt.replace("\n", " ").lower().strip()

    # 0. Check Redis intent cache
    import hashlib
    from services.cache import redis_client
    
    redis_key = None
    if redis_client:
        try:
            prompt_hash = hashlib.sha256(cleaned_prompt.encode('utf-8')).hexdigest()
            redis_key = f"intent:cache:{prompt_hash}"
            cached_intent_bytes = await redis_client.get(redis_key)
            if cached_intent_bytes:
                cached_intent = cached_intent_bytes.decode("utf-8")
                logger.info(f"Intent cache HIT: '{cached_intent}' for prompt hash {prompt_hash[:10]}...")
                return cached_intent
        except Exception as e:
            logger.warning(f"Failed to check intent cache: {e}")

    # Code keyword detection logic
    # Exact word match list
    exact_code_keywords = {
        "js", "git", "sql", "css", "php", "class", "script",
        "code", "function", "program", "develop"
    }
    
    # Substring / compound word match list (matched as part of any word)
    compound_code_keywords = {
        "html", "bootstrap", "python", "javascript", "programming",
        "algorithm", "database", "mysql",
        "mariadb", "postgres", "sqlite", "query", "docker", "codeblock", "syntax"
    }

    import re
    words = re.findall(r'[a-zA-Z0-9]+', cleaned_prompt)

    is_code = False
    for word in words:
        if word in exact_code_keywords:
            is_code = True
            break
        for kw in compound_code_keywords:
            if kw in word:
                is_code = True
                break
        if is_code:
            break

    if is_code:
        logger.info("Keyword override: detected code-related term in prompt. Forcing 'code' intent.")
        intent = "code"
        if redis_client and redis_key:
            try:
                await redis_client.setex(redis_key, 3600, intent)
                logger.debug(f"Cached intent '{intent}' in Redis (TTL 1h)")
            except Exception as e:
                logger.warning(f"Failed to cache intent: {e}")
        return intent

    if not ft_model:
        logger.warning("FastText model not initialized. Defaulting to 'general' intent.")
        intent = "general"
        if redis_client and redis_key:
            try:
                await redis_client.setex(redis_key, 3600, intent)
            except Exception:
                pass
        return intent
        
    # FastText predict is sync, run in thread pool
    labels, probabilities = await asyncio.to_thread(ft_model.predict, cleaned_prompt, k=1)
    
    intent = "general"
    if labels:
        # Label returned is like '__label__code'
        intent = labels[0].replace("__label__", "")
        confidence = probabilities[0]
        logger.info(f"FastText classified intent as '{intent}' with confidence {confidence:.4f}")
        
    if redis_client and redis_key:
        try:
            await redis_client.setex(redis_key, 3600, intent)
            logger.debug(f"Cached intent '{intent}' in Redis (TTL 1h)")
        except Exception as e:
            logger.warning(f"Failed to cache intent: {e}")
            
    return intent

def get_routing_target(intent: str, allowed_rules: Optional[List[int]] = None) -> Dict[str, Any]:
    """
    Look up the routing target using dynamic routing rules and select one
    target using weighted random choice, filtering by allowed rules if provided.
    """
    import random
    rules = dynamic_config.routing_rules
    targets = rules.get(intent)
    
    if not targets:
        targets = rules.get("general")
        
    if not targets:
        # Static hardcoded fallback
        return {
            "provider": "openai",
            "model": "gpt-4o-mini",
            "url": "https://api.openai.com/v1/chat/completions",
            "api_key_env": "OPENAI_API_KEY",
            "fallback_provider": "google",
            "fallback_model": "gemini-1.5-flash",
            "fallback_url": "https://generativelanguage.googleapis.com/v1beta/openai/chat/completions",
            "fallback_api_key_env": "GEMINI_API_KEY",
            "weight": 100
        }
        
    # If it is a dictionary (safety fallback), wrap in a list
    if isinstance(targets, dict):
        targets = [targets]
        
    # Filter targets by allowed rules if specified
    if allowed_rules is not None:
        # We only filter out rules that have an ID and whose ID is not in allowed_rules
        filtered_targets = [t for t in targets if t.get("id") is None or t.get("id") in allowed_rules]
        
        # If all targets for this intent are blocked, fallback to general intent (if allowed)
        if not filtered_targets:
            logger.warning(f"All targets for intent '{intent}' are disabled for this API key. Trying 'general' intent fallback...")
            general_targets = rules.get("general") or []
            if isinstance(general_targets, dict):
                general_targets = [general_targets]
            filtered_targets = [t for t in general_targets if t.get("id") is None or t.get("id") in allowed_rules]
            
        if filtered_targets:
            targets = filtered_targets
        else:
            logger.error(f"No allowed routing targets for this API key (intent: {intent}).")
            raise ValueError("No allowed routing rules for this API key.")
        
    # Perform weighted selection
    try:
        total_weight = sum(int(t.get("weight", 100)) for t in targets)
    except Exception:
        total_weight = len(targets) * 100
        
    if total_weight <= 0:
        return targets[0]
        
    r = random.randint(1, total_weight)
    upto = 0
    for t in targets:
        try:
            w = int(t.get("weight", 100))
        except Exception:
            w = 100
        if upto + w >= r:
            return t
        upto += w
        
    return targets[0]
