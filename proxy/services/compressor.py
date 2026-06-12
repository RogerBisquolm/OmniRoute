import logging
import time
import asyncio
from typing import Dict, Any, Tuple
from config import settings

logger = logging.getLogger(__name__)

# Global LLMLingua PromptCompressor instance
compressor = None

async def init_compressor():
    """
    Initialize the LLMLingua prompt compressor on CPU.
    Wraps the initialization in a try-except to ensure the server starts
    even if the model weights fail to download or load.
    """
    global compressor
    model_name = settings.LLMLINGUA_MODEL_PATH
    
    logger.info(f"Loading LLMLingua model '{model_name}' on CPU...")
    start_time = time.perf_counter()
    try:
        # Import dynamically to prevent import issues on light environments
        from llmlingua import PromptCompressor
        
        # LLMLingua loading is synchronous and model-heavy. Run in a thread pool.
        def load():
            return PromptCompressor(
                model_name=model_name,
                device_map="cpu",
                use_llmlingua2=True
            )
            
        compressor = await asyncio.to_thread(load)
        logger.info(f"LLMLingua model loaded successfully in {time.perf_counter() - start_time:.2f}s.")
    except Exception as e:
        logger.error(f"Failed to load LLMLingua compressor: {e}. Prompt compression will be bypassed.")
        compressor = None

async def compress_prompt(prompt: str, target_ratio: float = 0.7) -> Tuple[str, Dict[str, Any]]:
    """
    Compress the prompt using LLMLingua.
    If the compressor is not initialized, returns the original prompt.
    Returns: (compressed_prompt_text, metadata_dict)
    """
    if not compressor:
        # Bypassed
        return prompt, {
            "compressed": False,
            "original_tokens": len(prompt.split()), # crude approximation for bypass
            "compressed_tokens": len(prompt.split()),
            "ratio": 1.0,
            "bypassed": True,
            "reason": "Compressor not loaded"
        }

    # Short-circuit compression for short prompts to save CPU cycles
    if len(prompt) < 150 or len(prompt.split()) < 30:
        return prompt, {
            "compressed": False,
            "original_tokens": len(prompt.split()),
            "compressed_tokens": len(prompt.split()),
            "ratio": 1.0,
            "bypassed": True,
            "reason": "Prompt too short for compression"
        }

    start_time = time.perf_counter()
    try:
        # LLMLingua inference is CPU-bound, run in thread pool
        def run_compression():
            # compress_prompt takes context, instruction, rate, etc.
            # We compress the entire prompt as the context
            return compressor.compress_prompt(
                context=[prompt],
                rate=target_ratio,
                instruction="",
                force_tokens=["\n", "?", "!", ".", ":", "-"]
            )

        result = await asyncio.to_thread(run_compression)
        
        compressed_text = result.get("compressed_prompt", prompt)
        original_tokens = result.get("origin_tokens", 0)
        compressed_tokens = result.get("compressed_tokens", 0)
        actual_ratio = compressed_tokens / original_tokens if original_tokens > 0 else 1.0
        
        latency = (time.perf_counter() - start_time) * 1000
        logger.info(f"Prompt compressed in {latency:.2f}ms. Tokens: {original_tokens} -> {compressed_tokens} (Ratio: {actual_ratio:.2f})")
        
        return compressed_text, {
            "compressed": True,
            "original_tokens": original_tokens,
            "compressed_tokens": compressed_tokens,
            "ratio": actual_ratio,
            "latency_ms": latency,
            "bypassed": False
        }
    except Exception as e:
        logger.error(f"Error during prompt compression: {e}. Bypassing compression.")
        return prompt, {
            "compressed": False,
            "original_tokens": len(prompt.split()),
            "compressed_tokens": len(prompt.split()),
            "ratio": 1.0,
            "bypassed": True,
            "reason": f"Inference failed: {str(e)}"
        }
