import logging
import httpx
from typing import Tuple, List
from fastapi import HTTPException
from config import settings
from services.http_client import get_http_client

logger = logging.getLogger(__name__)

def parse_ollama_model_name(model_name: str) -> Tuple[str, str]:
    """Parse Ollama model name into library namespace/model name and tag."""
    if ":" in model_name:
        name_part, tag = model_name.split(":", 1)
    else:
        name_part, tag = model_name, "latest"
    
    if "/" in name_part:
        model_path = name_part
    else:
        model_path = f"library/{name_part}"
        
    return model_path, tag

async def ensure_ollama_model(model_name: str) -> None:
    """
    Ensure the requested Ollama model is available locally.
    Checks local tags; if missing, verifies size in registry and pulls it.
    """
    ollama_base_url = "http://ollama:11434"
    
    client = get_http_client()
    try:
            response = await client.get(f"{ollama_base_url}/api/tags", timeout=10.0)
            if response.status_code == 200:
                data = response.json()
                local_models = [m["name"] for m in data.get("models", [])]
                
                # Check for exact name match or implicit ':latest' match
                tag_matches = [model_name]
                if ":" not in model_name:
                    tag_matches.append(f"{model_name}:latest")
                    
                if any(match in local_models for match in tag_matches):
                    logger.debug(f"Ollama model '{model_name}' is already installed locally.")
                    return
            else:
                logger.warning(f"Ollama tags endpoint returned non-200 status: {response.status_code}")
    except Exception as e:
        logger.error(f"Failed to communicate with local Ollama container tags API: {e}")
        # If the local container is unreachable, we will let the downstream call try and fail normally,
        # but here we log a warning.
        raise HTTPException(
            status_code=502,
            detail=f"Failed to connect to local Ollama container at {ollama_base_url}: {e}"
        )

    # 2. Model not found locally -> check size on official Ollama registry
    model_path, tag = parse_ollama_model_name(model_name)
    registry_url = f"https://registry.ollama.ai/v2/{model_path}/manifests/{tag}"
    logger.info(f"Ollama model '{model_name}' not found locally. Fetching manifest from registry: {registry_url}")
    
    client = get_http_client()
    try:
            response = await client.get(registry_url, timeout=15.0)
            if response.status_code == 404:
                logger.warning(f"Ollama model '{model_name}' was not found in the Ollama registry (404).")
                raise HTTPException(
                    status_code=404,
                    detail=f"Ollama model '{model_name}' was not found in the registry."
                )
            elif response.status_code != 200:
                logger.error(f"Ollama registry returned status {response.status_code} for manifest lookup: {response.text}")
                raise HTTPException(
                    status_code=502,
                    detail=f"Failed to fetch model metadata from Ollama registry. Registry returned status {response.status_code}."
                )
            
            manifest = response.json()
            layers = manifest.get("layers", [])
            total_bytes = sum(layer.get("size", 0) for layer in layers)
            size_gb = total_bytes / (1024 * 1024 * 1024)
            
            logger.info(f"Ollama registry manifest resolved: model '{model_name}' total size is {size_gb:.2f} GB.")
            
            # Enforce size limit
            max_size_gb = getattr(settings, "OLLAMA_MAX_MODEL_SIZE_GB", 16.0)
            if size_gb > max_size_gb:
                err_msg = f"Ollama model '{model_name}' size ({size_gb:.2f} GB) exceeds the allowed hardware limit of {max_size_gb} GB."
                logger.error(err_msg)
                raise HTTPException(
                    status_code=400,
                    detail=err_msg
                )
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Failed to connect or parse Ollama registry manifest for '{model_name}': {e}")
        raise HTTPException(
            status_code=502,
            detail=f"Failed to verify Ollama model size from registry: {e}"
        )

    # 3. Size is within limit -> Automatically pull the model
    pull_url = f"{ollama_base_url}/api/pull"
    logger.info(f"Automatically pulling Ollama model '{model_name}' ({size_gb:.2f} GB)...")
    
    client = get_http_client()
    try:
            response = await client.post(
                pull_url,
                json={"name": model_name, "stream": False},
                timeout=600.0
            )
            if response.status_code != 200:
                logger.error(f"Failed to pull model '{model_name}' via Ollama API: {response.text}")
                raise HTTPException(
                    status_code=502,
                    detail=f"Failed to pull model '{model_name}' via Ollama: {response.text}"
                )
            
            logger.info(f"Successfully pulled Ollama model '{model_name}'.")
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error while pulling Ollama model '{model_name}': {e}")
        raise HTTPException(
            status_code=502,
            detail=f"Error occurred while pulling Ollama model '{model_name}': {e}"
        )
