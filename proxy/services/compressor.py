import logging
import time
import asyncio
import re
from typing import Dict, Any, Tuple
from config import settings, dynamic_config

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
        logger.error(f"Failed to load LLMLingua compressor: {e}. Prompt compression will fallback to CPU bypass or other rule-based options.")
        compressor = None

def estimate_tokens(text: str) -> int:
    """Helper to estimate tokens using tiktoken cl100k_base or word heuristic."""
    try:
        import tiktoken
        encoding = tiktoken.get_encoding("cl100k_base")
        return len(encoding.encode(text))
    except Exception:
        return int(len(text.split()) * 1.3) + 1

def compress_rtk(text: str) -> str:
    """
    RTK (Rust Token Killer) Log/CLI Filter:
    - Strips ANSI escape/color codes.
    - Strips terminal progress bars.
    - Collapses adjacent identical or highly similar log/stack trace lines.
    - Filters out successful test outcomes (e.g. PASSED, ... ok) and generic package manager resolves.
    """
    if not text:
        return text

    # 1. Strip ANSI escape sequences
    ansi_escape = re.compile(r'\x1B(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~])')
    text = ansi_escape.sub('', text)
    
    # 2. Strip terminal progress bars
    progress_bar_1 = re.compile(r'\[[=#>-]+[ ]*\]\s*\d+%\s*')
    progress_bar_2 = re.compile(r'\d+%\s*\|[█░ 🮆 ]*\|')
    text = progress_bar_1.sub('', text)
    text = progress_bar_2.sub('', text)
    
    # 3. Collapse repeating lines and filter noise
    lines = text.splitlines()
    new_lines = []
    
    last_line = None
    repeat_count = 0
    
    for line in lines:
        cleaned_line = line.strip()
        if not cleaned_line:
            new_lines.append(line)
            continue
            
        # Skip common webpack / dev / test success boilerplate noise
        if re.search(r'::\w+ PASSED', line) or (line.startswith("test ") and line.endswith(" ... ok")):
            continue
            
        # Skip package manager resolved / fetched lines
        if line.startswith("Resolved ") or line.startswith("Fetch ") or line.startswith("Downloading "):
            continue
            
        # Deduplication
        if line == last_line:
            repeat_count += 1
        else:
            if repeat_count > 0:
                new_lines.append(f"... [repeated {repeat_count} more times] ...")
                repeat_count = 0
            new_lines.append(line)
            last_line = line
            
    if repeat_count > 0:
        new_lines.append(f"... [repeated {repeat_count} more times] ...")
        
    return "\n".join(new_lines)

def compress_caveman(text: str, intensity: str = "full") -> str:
    """
    Caveman Telegraphic/Semantic Compressor:
    - Lite: Removes conversational pleasantries, greetings, and closing phrases.
    - Full: Performs Lite + strips articles (a, an, the) and auxiliary verbs.
    - Ultra: Performs Full + strips prepositions and pronouns.
    """
    if not text:
        return text

    # 1. Strip pleasantries and conversational filler
    pleasantries = [
        r"\bhello\b[!,.:;?]*", r"\bhi\b[!,.:;?]*", r"\bhey\b[!,.:;?]*", 
        r"\bplease\b[!,.:;?]*", r"\bkindly\b[!,.:;?]*", 
        r"\bthank you\b[!,.:;?]*", r"\bthanks\b[!,.:;?]*", 
        r"\bcould you\b[!,.:;?]*", r"\bwould you\b[!,.:;?]*", r"\bcan you\b[!,.:;?]*",
        r"\bi would like you to\b[!,.:;?]*", r"\bi need you to\b[!,.:;?]*",
        r"\bhelp me to\b[!,.:;?]*", r"\bhelp me\b[!,.:;?]*"
    ]
    
    for pattern in pleasantries:
        text = re.sub(pattern, "", text, flags=re.IGNORECASE)
        
    text = re.sub(r' +', ' ', text).strip()
    text = re.sub(r'^[!,.;? ]+', '', text).strip()
    
    if intensity == "lite":
        return re.sub(r'\n+', '\n', text).strip()
        
    # 2. Full mode: strip articles and common auxiliary verbs
    if intensity in ("full", "ultra"):
        articles = [r"\ba\b", r"\ban\b", r"\bthe\b"]
        for pattern in articles:
            text = re.sub(pattern, "", text, flags=re.IGNORECASE)
            
        aux_verbs = [
            r"\bis\b", r"\bare\b", r"\bwas\b", r"\bwere\b", 
            r"\bbe\b", r"\bbeen\b", r"\bbeing\b",
            r"\bdo\b", r"\bdoes\b", r"\bdid\b",
            r"\bhave\b", r"\bhas\b", r"\bhad\b"
        ]
        for pattern in aux_verbs:
            text = re.sub(pattern, "", text, flags=re.IGNORECASE)
            
    # 3. Ultra mode: strip prepositions and pronouns
    if intensity == "ultra":
        prepositions_pronouns = [
            r"\bto\b", r"\bfor\b", r"\bof\b", r"\bin\b", r"\bon\b", r"\bat\b", r"\bwith\b", r"\bby\b",
            r"\bi\b", r"\bme\b", r"\byou\b", r"\bwe\b", r"\bus\b", r"\bthey\b", r"\bhim\b", r"\bthem\b",
            r"\bhe\b", r"\bshe\b", r"\bit\b",
            r"\bmy\b", r"\byour\b", r"\bour\b", r"\btheir\b", r"\bhis\b", r"\bher\b"
        ]
        for pattern in prepositions_pronouns:
            text = re.sub(pattern, "", text, flags=re.IGNORECASE)
            
    text = re.sub(r' +', ' ', text)
    text = re.sub(r' +([?.!,;])', r'\1', text)
    
    # Clean up blank lines
    lines = [line.strip() for line in text.splitlines()]
    lines = [line for line in lines if line]
    return "\n".join(lines)

async def compress_prompt(prompt: str, target_ratio: float = None) -> Tuple[str, Dict[str, Any]]:
    """
    Compress the prompt using the configured strategy in dynamic_config.
    Supports: llmlingua, rtk, caveman, stacked (rtk+caveman), rtk+llmlingua, disabled.
    Returns: (compressed_prompt_text, metadata_dict)
    """
    method = getattr(dynamic_config, "compressor_method", "llmlingua").lower()
    
    if target_ratio is None:
        target_ratio = getattr(dynamic_config, "compressor_ratio", 0.70)

    # If disabled, bypass immediately
    if method == "disabled":
        tokens = estimate_tokens(prompt)
        return prompt, {
            "compressed": False,
            "original_tokens": tokens,
            "compressed_tokens": tokens,
            "ratio": 1.0,
            "bypassed": True,
            "reason": "Compression disabled"
        }

    # Check LLMLingua-heavy compressor checks
    if method in ("llmlingua", "rtk+llmlingua"):
        if not compressor:
            tokens = estimate_tokens(prompt)
            return prompt, {
                "compressed": False,
                "original_tokens": tokens,
                "compressed_tokens": tokens,
                "ratio": 1.0,
                "bypassed": True,
                "reason": "Compressor not loaded"
            }
            
        if len(prompt) < 150 or len(prompt.split()) < 30:
            tokens = estimate_tokens(prompt)
            return prompt, {
                "compressed": False,
                "original_tokens": tokens,
                "compressed_tokens": tokens,
                "ratio": 1.0,
                "bypassed": True,
                "reason": "Prompt too short for compression"
            }

    start_time = time.perf_counter()
    original_tokens = estimate_tokens(prompt)

    try:
        compressed_text = prompt
        metadata = {}

        if method == "rtk":
            # Pure RTK
            compressed_text = compress_rtk(prompt)
            compressed_tokens = estimate_tokens(compressed_text)
            actual_ratio = compressed_tokens / original_tokens if original_tokens > 0 else 1.0
            
            latency = (time.perf_counter() - start_time) * 1000
            logger.info(f"Prompt compressed using RTK in {latency:.2f}ms. Tokens: {original_tokens} -> {compressed_tokens} (Ratio: {actual_ratio:.2f})")
            
            return compressed_text, {
                "compressed": True,
                "original_tokens": original_tokens,
                "compressed_tokens": compressed_tokens,
                "ratio": actual_ratio,
                "latency_ms": latency,
                "bypassed": False,
                "method": "rtk"
            }

        elif method == "caveman":
            # Pure Caveman
            intensity = getattr(dynamic_config, "compressor_caveman_intensity", "full").lower()
            compressed_text = compress_caveman(prompt, intensity=intensity)
            compressed_tokens = estimate_tokens(compressed_text)
            actual_ratio = compressed_tokens / original_tokens if original_tokens > 0 else 1.0
            
            latency = (time.perf_counter() - start_time) * 1000
            logger.info(f"Prompt compressed using Caveman ({intensity}) in {latency:.2f}ms. Tokens: {original_tokens} -> {compressed_tokens} (Ratio: {actual_ratio:.2f})")
            
            return compressed_text, {
                "compressed": True,
                "original_tokens": original_tokens,
                "compressed_tokens": compressed_tokens,
                "ratio": actual_ratio,
                "latency_ms": latency,
                "bypassed": False,
                "method": f"caveman_{intensity}"
            }

        elif method == "stacked":
            # RTK + Caveman
            intensity = getattr(dynamic_config, "compressor_caveman_intensity", "full").lower()
            temp_text = compress_rtk(prompt)
            compressed_text = compress_caveman(temp_text, intensity=intensity)
            compressed_tokens = estimate_tokens(compressed_text)
            actual_ratio = compressed_tokens / original_tokens if original_tokens > 0 else 1.0
            
            latency = (time.perf_counter() - start_time) * 1000
            logger.info(f"Prompt compressed using Stacked (RTK + Caveman) in {latency:.2f}ms. Tokens: {original_tokens} -> {compressed_tokens} (Ratio: {actual_ratio:.2f})")
            
            return compressed_text, {
                "compressed": True,
                "original_tokens": original_tokens,
                "compressed_tokens": compressed_tokens,
                "ratio": actual_ratio,
                "latency_ms": latency,
                "bypassed": False,
                "method": f"stacked_{intensity}"
            }

        elif method == "rtk+llmlingua":
            # Run RTK first, then run LLMLingua-2
            prompt = compress_rtk(prompt)
            original_tokens = estimate_tokens(prompt) # update to RTK output tokens
            # Fall through to LLMLingua

        # LLMLingua-2 flow (either pure or pre-filtered by RTK)
        if not compressor:
            # Fallback if model not loaded
            compressed_tokens = estimate_tokens(prompt)
            return prompt, {
                "compressed": False,
                "original_tokens": compressed_tokens,
                "compressed_tokens": compressed_tokens,
                "ratio": 1.0,
                "bypassed": True,
                "reason": "LLMLingua model not loaded",
                "method": method
            }

        def run_compression():
            return compressor.compress_prompt(
                context=[prompt],
                rate=target_ratio,
                instruction="",
                force_tokens=["\n", "?", "!", ".", ":", "-"]
            )

        result = await asyncio.to_thread(run_compression)
        
        compressed_text = result.get("compressed_prompt", prompt)
        original_tokens = result.get("origin_tokens", original_tokens)
        compressed_tokens = result.get("compressed_tokens", 0)
        actual_ratio = compressed_tokens / original_tokens if original_tokens > 0 else 1.0
        
        latency = (time.perf_counter() - start_time) * 1000
        logger.info(f"Prompt compressed using LLMLingua ({method}) in {latency:.2f}ms. Tokens: {original_tokens} -> {compressed_tokens} (Ratio: {actual_ratio:.2f})")
        
        return compressed_text, {
            "compressed": True,
            "original_tokens": original_tokens,
            "compressed_tokens": compressed_tokens,
            "ratio": actual_ratio,
            "latency_ms": latency,
            "bypassed": False,
            "method": method
        }

    except Exception as e:
        logger.error(f"Error during prompt compression with method {method}: {e}. Bypassing compression.")
        tokens = estimate_tokens(prompt)
        return prompt, {
            "compressed": False,
            "original_tokens": tokens,
            "compressed_tokens": tokens,
            "ratio": 1.0,
            "bypassed": True,
            "reason": f"Inference failed: {str(e)}",
            "method": method
        }
