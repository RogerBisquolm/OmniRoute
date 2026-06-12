import unittest
from unittest.mock import AsyncMock, MagicMock, patch
import asyncio
import sys
import os
from fastapi import HTTPException

# Ensure proxy path is in sys.path
use_path = "/Volumes/antigravity/OmniRoute/proxy"
if use_path not in sys.path:
    sys.path.append(use_path)

from services.ollama import parse_ollama_model_name, ensure_ollama_model
from config import settings

class TestOllamaService(unittest.TestCase):
    
    def test_parse_ollama_model_name(self):
        """Verify model name parsing matches expected library path and tag formatting."""
        # Simple name
        path, tag = parse_ollama_model_name("gemma2")
        self.assertEqual(path, "library/gemma2")
        self.assertEqual(tag, "latest")
        
        # Name with tag
        path, tag = parse_ollama_model_name("gemma2:2b")
        self.assertEqual(path, "library/gemma2")
        self.assertEqual(tag, "2b")
        
        # Name with namespace and tag
        path, tag = parse_ollama_model_name("myuser/my-custom-model:v1")
        self.assertEqual(path, "myuser/my-custom-model")
        self.assertEqual(tag, "v1")

    @patch("httpx.AsyncClient.get")
    async def run_ensure_model_exists_locally(self, mock_get):
        """Verify ensure_ollama_model returns immediately if model is already pulled."""
        # Mock tags API response
        mock_response = MagicMock()
        mock_response.status_code = 200
        mock_response.json.return_value = {
            "models": [
                {"name": "gemma2:2b"},
                {"name": "tinyllama:latest"}
            ]
        }
        mock_get.return_value = mock_response
        
        # This should succeed and return immediately (no registry mock needed)
        await ensure_ollama_model("gemma2:2b")
        mock_get.assert_called_once_with("http://ollama:11434/api/tags", timeout=10.0)

    def test_ensure_model_exists_locally(self):
        asyncio.run(self.run_ensure_model_exists_locally())

    @patch("httpx.AsyncClient.post")
    @patch("httpx.AsyncClient.get")
    async def run_dynamic_pull_under_limit(self, mock_get, mock_post):
        """Verify a missing model under the size limit is pulled successfully."""
        # 1. Tags API (model missing)
        mock_tags_res = MagicMock()
        mock_tags_res.status_code = 200
        mock_tags_res.json.return_value = {"models": [{"name": "tinyllama:latest"}]}
        
        # 2. Registry API (1.5 GB total size)
        mock_registry_res = MagicMock()
        mock_registry_res.status_code = 200
        mock_registry_res.json.return_value = {
            "layers": [
                {"size": 1024 * 1024 * 1024},  # 1 GB
                {"size": 512 * 1024 * 1024}    # 0.5 GB
            ]
        }
        
        mock_get.side_effect = [mock_tags_res, mock_registry_res]
        
        # 3. Pull API (success)
        mock_pull_res = MagicMock()
        mock_pull_res.status_code = 200
        mock_post.return_value = mock_pull_res
        
        # Execute ensure_ollama_model for gemma2:2b
        await ensure_ollama_model("gemma2:2b")
        
        # Assert pull API was invoked
        mock_post.assert_called_once_with(
            "http://ollama:11434/api/pull",
            json={"name": "gemma2:2b", "stream": False},
            timeout=600.0
        )

    def test_dynamic_pull_under_limit(self):
        asyncio.run(self.run_dynamic_pull_under_limit())

    @patch("httpx.AsyncClient.get")
    async def run_dynamic_pull_over_limit(self, mock_get):
        """Verify pulling a model exceeding the size limit is blocked and throws 400."""
        # 1. Tags API (model missing)
        mock_tags_res = MagicMock()
        mock_tags_res.status_code = 200
        mock_tags_res.json.return_value = {"models": []}
        
        # 2. Registry API (20 GB total size)
        mock_registry_res = MagicMock()
        mock_registry_res.status_code = 200
        mock_registry_res.json.return_value = {
            "layers": [
                {"size": 20 * 1024 * 1024 * 1024}  # 20 GB
            ]
        }
        
        mock_get.side_effect = [mock_tags_res, mock_registry_res]
        
        # Force setting max limit to 16 GB
        settings.OLLAMA_MAX_MODEL_SIZE_GB = 16.0
        
        with self.assertRaises(HTTPException) as context:
            await ensure_ollama_model("llama3:70b")
            
        self.assertEqual(context.exception.status_code, 400)
        self.assertIn("exceeds the allowed hardware limit", context.exception.detail)

    def test_dynamic_pull_over_limit(self):
        asyncio.run(self.run_dynamic_pull_over_limit())

    @patch("httpx.AsyncClient.get")
    async def run_model_not_found_registry(self, mock_get):
        """Verify registry returning 404 is caught and raises a clean 404 HTTPException."""
        mock_tags_res = MagicMock()
        mock_tags_res.status_code = 200
        mock_tags_res.json.return_value = {"models": []}
        
        mock_registry_res = MagicMock()
        mock_registry_res.status_code = 404
        
        mock_get.side_effect = [mock_tags_res, mock_registry_res]
        
        with self.assertRaises(HTTPException) as context:
            await ensure_ollama_model("non-existent-model-name:latest")
            
        self.assertEqual(context.exception.status_code, 404)
        self.assertIn("was not found in the registry", context.exception.detail)

    def test_model_not_found_registry(self):
        asyncio.run(self.run_model_not_found_registry())

if __name__ == '__main__':
    unittest.main()
