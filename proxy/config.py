import json
import logging
from typing import Dict, Any
from pydantic_settings import BaseSettings

logger = logging.getLogger(__name__)

class Settings(BaseSettings):
    APP_ENV: str = "local"
    
    # Redis
    REDIS_URL: str = "redis://redis:6379/0"
    CONFIG_PUBSUB_CHANNEL: str = "gateway_config_updates"
    
    # Databases
    DB_MASTER_URL: str = "mysql+asyncmy://gateway_user:gateway_password@mariadb-master:3306/gateway_db"
    DB_SLAVE_URL: str = "mysql+asyncmy://gateway_user:gateway_password@mariadb-slave:3306/gateway_db"
    
    # Model Paths & Names
    FASTTEXT_MODEL_PATH: str = "/app/models/intent_model.bin"
    LLMLINGUA_MODEL_PATH: str = "microsoft/llmlingua-2-bert-base-multilingual-cased-meetingbank"
    EMBEDDING_MODEL_NAME: str = "all-MiniLM-L6-v2"
    CROSS_ENCODER_MODEL_NAME: str = "cross-encoder/ms-marco-MiniLM-L-2-v2"
    
    # External API Keys (Defaults can be overridden)
    OPENAI_API_KEY: str = "mock-key"
    ANTHROPIC_API_KEY: str = "mock-key"
    GEMINI_API_KEY: str = "mock-key"
    OLLAMA_API_KEY: str = "mock-key"
    OLLAMA_MAX_MODEL_SIZE_GB: float = 16.0
    
    class Config:
        env_file = ".env"
        extra = "ignore"

# Global settings instance
settings = Settings()

# Dynamic Routing Rules - loaded in memory and updatable via Redis Pub/Sub
# Maps classified intent to LLM model name, provider, and external API endpoint
DEFAULT_ROUTING_RULES = {
    "code": [
        {
            "provider": "anthropic",
            "model": "claude-3-5-sonnet-20240620",
            "url": "https://api.anthropic.com/v1/messages",
            "api_key_env": "ANTHROPIC_API_KEY",
            "fallback_provider": "openai",
            "fallback_model": "gpt-4o-mini",
            "fallback_url": "https://api.openai.com/v1/chat/completions",
            "fallback_api_key_env": "OPENAI_API_KEY",
            "weight": 70
        },
        {
            "provider": "openai",
            "model": "gpt-4o",
            "url": "https://api.openai.com/v1/chat/completions",
            "api_key_env": "OPENAI_API_KEY",
            "fallback_provider": "google",
            "fallback_model": "gemini-1.5-flash",
            "fallback_url": "https://generativelanguage.googleapis.com/v1beta/openai/chat/completions",
            "fallback_api_key_env": "GEMINI_API_KEY",
            "weight": 30
        }
    ],
    "creative": [
        {
            "provider": "anthropic",
            "model": "claude-3-5-sonnet-20240620",
            "url": "https://api.anthropic.com/v1/messages",
            "api_key_env": "ANTHROPIC_API_KEY",
            "fallback_provider": "openai",
            "fallback_model": "gpt-4o-mini",
            "fallback_url": "https://api.openai.com/v1/chat/completions",
            "fallback_api_key_env": "OPENAI_API_KEY",
            "weight": 50
        },
        {
            "provider": "openai",
            "model": "gpt-4o-mini",
            "url": "https://api.openai.com/v1/chat/completions",
            "api_key_env": "OPENAI_API_KEY",
            "fallback_provider": "google",
            "fallback_model": "gemini-1.5-flash",
            "fallback_url": "https://generativelanguage.googleapis.com/v1beta/openai/chat/completions",
            "fallback_api_key_env": "GEMINI_API_KEY",
            "weight": 50
        }
    ],
    "support": [
        {
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
    ],
    "general": [
        {
            "provider": "google",
            "model": "gemini-1.5-flash",
            "url": "https://generativelanguage.googleapis.com/v1beta/openai/chat/completions",
            "api_key_env": "GEMINI_API_KEY",
            "fallback_provider": "openai",
            "fallback_model": "gpt-4o-mini",
            "fallback_url": "https://api.openai.com/v1/chat/completions",
            "fallback_api_key_env": "OPENAI_API_KEY",
            "weight": 100
        }
    ]
}

class DynamicConfig:
    def __init__(self):
        self.routing_rules: Dict[str, Any] = DEFAULT_ROUTING_RULES.copy()
        self.rephrase_enabled: bool = False
        self.rephrase_provider: str = "ollama"
        self.rephrase_model: str = "phi3"
        self.cache_threshold: float = 0.10
        
    def update_rules(self, raw_message: str):
        try:
            data = json.loads(raw_message)
            if "routing_rules" in data:
                self.routing_rules = data["routing_rules"]
                logger.info(f"Updated dynamic routing rules successfully: {self.routing_rules}")
        except Exception as e:
            logger.error(f"Failed to parse config update message: {e}")

# Global dynamic config instance
dynamic_config = DynamicConfig()
