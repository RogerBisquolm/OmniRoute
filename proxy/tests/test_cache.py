import unittest
from unittest.mock import AsyncMock, MagicMock, patch
import asyncio
import sys
import os
import json
import numpy as np

# Ensure proxy path is in sys.path
use_path = "/Volumes/antigravity/OmniRoute/proxy"
if use_path not in sys.path:
    sys.path.append(use_path)

import services.cache as cache

class TestSemanticCache(unittest.TestCase):
    
    def setUp(self):
        # Setup mocks for global dependencies in services.cache
        self.orig_redis = cache.redis_client
        self.orig_embedding = cache.embedding_model
        self.orig_cross = cache.cross_encoder_model
        
        cache.redis_client = MagicMock()
        cache.embedding_model = MagicMock()
        cache.cross_encoder_model = MagicMock()
        
        # Mock embedding function
        cache.get_embedding = AsyncMock(return_value=np.zeros(384, dtype=np.float32))

    def tearDown(self):
        # Restore original objects
        cache.redis_client = self.orig_redis
        cache.embedding_model = self.orig_embedding
        cache.cross_encoder_model = self.orig_cross

    def test_digit_mismatch(self):
        """
        Verify that prompts with different numbers/digits are rejected by the digit check.
        And the Cross-Encoder should NOT be invoked (short-circuit).
        """
        async def run_test():
            # Mock candidate document
            mock_doc = MagicMock()
            mock_doc.score = "0.05"
            mock_doc.prompt = "Show user status for ID 123"
            mock_doc.response = json.dumps({"choices": [{"message": {"content": "User 123 data"}}]})
            
            mock_results = MagicMock()
            mock_results.docs = [mock_doc]
            
            mock_ft = MagicMock()
            mock_ft.search = AsyncMock(return_value=mock_results)
            cache.redis_client.ft = MagicMock(return_value=mock_ft)
            
            # Query has digit '456', candidate has '123'
            res = await cache.check_semantic_cache("Show user status for ID 456", threshold=0.1)
            
            # Should be a cache miss
            self.assertIsNone(res)
            # Cross-encoder predict should not be called
            cache.cross_encoder_model.predict.assert_not_called()

        asyncio.run(run_test())

    def test_toggle_mismatch(self):
        """
        Verify that prompts with mismatching logical toggles (enable vs disable) are rejected.
        """
        async def run_test():
            # Mock candidate document
            mock_doc = MagicMock()
            mock_doc.score = "0.05"
            mock_doc.prompt = "How to enable firewall on linux"
            mock_doc.response = json.dumps({"choices": [{"message": {"content": "Firewall rules"}}]})
            
            mock_results = MagicMock()
            mock_results.docs = [mock_doc]
            
            mock_ft = MagicMock()
            mock_ft.search = AsyncMock(return_value=mock_results)
            cache.redis_client.ft = MagicMock(return_value=mock_ft)
            
            # Query has 'disable', candidate has 'enable'
            res = await cache.check_semantic_cache("How to disable firewall on linux", threshold=0.1)
            
            self.assertIsNone(res)
            cache.cross_encoder_model.predict.assert_not_called()

        asyncio.run(run_test())

    def test_cross_encoder_match(self):
        """
        Verify that when digits/toggles match and Cross-Encoder score probability is >= 0.85,
        we get a successful cache HIT.
        """
        async def run_test():
            mock_doc = MagicMock()
            mock_doc.score = "0.05"
            mock_doc.prompt = "What is the capital of France?"
            mock_doc.response = json.dumps({"choices": [{"message": {"content": "Paris"}}]})
            
            mock_results = MagicMock()
            mock_results.docs = [mock_doc]
            
            mock_ft = MagicMock()
            mock_ft.search = AsyncMock(return_value=mock_results)
            cache.redis_client.ft = MagicMock(return_value=mock_ft)
            
            # Mock CrossEncoder to return a high score logit (e.g. 2.0 -> sigmoid(2.0) = 0.88 >= 0.85)
            cache.cross_encoder_model.predict = MagicMock(return_value=2.0)
            
            res = await cache.check_semantic_cache("What is the capital city of France?", threshold=0.1)
            
            self.assertIsNotNone(res)
            self.assertEqual(res["choices"][0]["message"]["content"], "Paris")
            cache.cross_encoder_model.predict.assert_called_once()

        asyncio.run(run_test())

    def test_cross_encoder_mismatch(self):
        """
        Verify that when Cross-Encoder score probability is < 0.85, the hit is rejected.
        """
        async def run_test():
            mock_doc = MagicMock()
            mock_doc.score = "0.05"
            mock_doc.prompt = "Tell me about Google CEO"
            mock_doc.response = json.dumps({"choices": [{"message": {"content": "Sundar Pichai"}}]})
            
            mock_results = MagicMock()
            mock_results.docs = [mock_doc]
            
            mock_ft = MagicMock()
            mock_ft.search = AsyncMock(return_value=mock_results)
            cache.redis_client.ft = MagicMock(return_value=mock_ft)
            
            # Mock CrossEncoder to return a low score logit (e.g. 0.0 -> sigmoid(0.0) = 0.5 < 0.85)
            cache.cross_encoder_model.predict = MagicMock(return_value=0.0)
            
            res = await cache.check_semantic_cache("Tell me about Microsoft CEO", threshold=0.1)
            
            self.assertIsNone(res)
            cache.cross_encoder_model.predict.assert_called_once()

        asyncio.run(run_test())

    def test_fallback_no_cross_encoder(self):
        """
        Verify that if the Cross-Encoder model is not loaded/available, the system
        gracefully falls back to standard VSS cosine distance threshold matching.
        """
        async def run_test():
            # Set cross_encoder_model to None to trigger fallback
            cache.cross_encoder_model = None
            
            mock_doc = MagicMock()
            mock_doc.score = "0.05"  # less than threshold 0.10 -> HIT
            mock_doc.prompt = "Hello"
            mock_doc.response = json.dumps({"choices": [{"message": {"content": "Hi there"}}]})
            
            mock_results = MagicMock()
            mock_results.docs = [mock_doc]
            
            mock_ft = MagicMock()
            mock_ft.search = AsyncMock(return_value=mock_results)
            cache.redis_client.ft = MagicMock(return_value=mock_ft)
            
            res = await cache.check_semantic_cache("Hello", threshold=0.1)
            
            self.assertIsNotNone(res)
            self.assertEqual(res["choices"][0]["message"]["content"], "Hi there")

        asyncio.run(run_test())

    def test_delete_similar_cache_entry(self):
        """
        Verify that delete_similar_cache_entry successfully finds a matching cache entry
        and invokes Redis client delete on it.
        """
        async def run_test():
            mock_doc = MagicMock()
            mock_doc.id = "cache:prompt_hash_123"
            mock_doc.score = "0.05"
            mock_doc.prompt = "What is the capital of France?"
            
            mock_results = MagicMock()
            mock_results.docs = [mock_doc]
            
            mock_ft = MagicMock()
            mock_ft.search = AsyncMock(return_value=mock_results)
            cache.redis_client.ft = MagicMock(return_value=mock_ft)
            
            # Mock CrossEncoder match
            cache.cross_encoder_model.predict = MagicMock(return_value=2.0)
            
            # Mock Redis delete
            cache.redis_client.delete = AsyncMock()
            
            # Call deletion
            deleted = await cache.delete_similar_cache_entry("What is the capital city of France?", threshold=0.1)
            
            self.assertTrue(deleted)
            cache.redis_client.delete.assert_called_once_with("cache:prompt_hash_123")
            
        asyncio.run(run_test())

    def test_save_to_semantic_cache_ttl(self):
        """
        Verify that save_to_semantic_cache saves the prompt details in Redis
        and calls expire with the passed TTL.
        """
        async def run_test():
            cache.redis_client.hset = AsyncMock()
            cache.redis_client.expire = AsyncMock()
            
            prompt = "Test cache TTL prompt"
            response_data = {"choices": [{"message": {"content": "TTL response"}}]}
            ttl = 14400
            
            await cache.save_to_semantic_cache(prompt, response_data, ttl=ttl)
            
            cache.redis_client.hset.assert_called_once()
            cache.redis_client.expire.assert_called_once()
            expire_args, expire_kwargs = cache.redis_client.expire.call_args
            self.assertEqual(expire_args[1], ttl)
            
        asyncio.run(run_test())

if __name__ == '__main__':
    unittest.main()
