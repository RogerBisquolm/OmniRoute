import unittest
from unittest.mock import AsyncMock, MagicMock, patch
import asyncio
import sys

# Ensure proxy path is in sys.path
use_path = "/Volumes/antigravity/OmniRoute/proxy"
if use_path not in sys.path:
    sys.path.append(use_path)

import services.compressor as compressor

class TestPromptCompressor(unittest.TestCase):
    
    def setUp(self):
        self.orig_compressor = compressor.compressor
        compressor.compressor = MagicMock()
        
    def tearDown(self):
        compressor.compressor = self.orig_compressor

    def test_compress_short_prompt_bypass(self):
        """Verify that short prompts bypass compression and return immediately."""
        async def run_test():
            short_prompt = "Explain quantum computing in one sentence."
            # Call compression
            compressed, meta = await compressor.compress_prompt(short_prompt)
            
            # Assertions
            self.assertEqual(compressed, short_prompt)
            self.assertTrue(meta["bypassed"])
            self.assertEqual(meta["reason"], "Prompt too short for compression")
            # Compressor model should not be called
            compressor.compressor.compress_prompt.assert_not_called()
            
        asyncio.run(run_test())

    def test_compress_long_prompt_execution(self):
        """Verify that long prompts execute the compressor model."""
        async def run_test():
            # Create a long prompt > 150 chars and > 30 words
            long_prompt = " ".join(["word"] * 40) + " This is a very long prompt designed to trigger the compressor model instead of bypassing it because it exceeds the minimum character and word threshold limits defined in the short-circuit code path."
            
            # Mock compressor output
            mock_res = {
                "compressed_prompt": "compressed output",
                "origin_tokens": 100,
                "compressed_tokens": 70
            }
            compressor.compressor.compress_prompt = MagicMock(return_value=mock_res)
            
            # Call compression
            compressed, meta = await compressor.compress_prompt(long_prompt)
            
            # Assertions
            self.assertEqual(compressed, "compressed output")
            self.assertFalse(meta["bypassed"])
            self.assertEqual(meta["original_tokens"], 100)
            self.assertEqual(meta["compressed_tokens"], 70)
            compressor.compressor.compress_prompt.assert_called_once()
            
        asyncio.run(run_test())

if __name__ == '__main__':
    unittest.main()
