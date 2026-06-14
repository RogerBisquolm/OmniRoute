import unittest
from unittest.mock import AsyncMock, MagicMock, patch
import asyncio
import sys

# Ensure proxy path is in sys.path
use_path = "/Volumes/antigravity/OmniRoute/proxy"
if use_path not in sys.path:
    sys.path.append(use_path)

import services.compressor as compressor
from config import dynamic_config

class TestPromptCompressor(unittest.TestCase):
    
    def setUp(self):
        self.orig_compressor = compressor.compressor
        self.orig_method = dynamic_config.compressor_method
        self.orig_ratio = dynamic_config.compressor_ratio
        self.orig_caveman = dynamic_config.compressor_caveman_intensity
        
        compressor.compressor = MagicMock()
        
    def tearDown(self):
        compressor.compressor = self.orig_compressor
        dynamic_config.compressor_method = self.orig_method
        dynamic_config.compressor_ratio = self.orig_ratio
        dynamic_config.compressor_caveman_intensity = self.orig_caveman

    def test_compress_short_prompt_bypass(self):
        """Verify that short prompts bypass compression in llmlingua mode and return immediately."""
        async def run_test():
            dynamic_config.compressor_method = "llmlingua"
            short_prompt = "Explain quantum computing in one sentence."
            compressed, meta = await compressor.compress_prompt(short_prompt)
            
            self.assertEqual(compressed, short_prompt)
            self.assertTrue(meta["bypassed"])
            self.assertEqual(meta["reason"], "Prompt too short for compression")
            compressor.compressor.compress_prompt.assert_not_called()
            
        asyncio.run(run_test())

    def test_compress_long_prompt_execution(self):
        """Verify that long prompts execute the compressor model in llmlingua mode."""
        async def run_test():
            dynamic_config.compressor_method = "llmlingua"
            long_prompt = " ".join(["word"] * 40) + " This is a very long prompt designed to trigger the compressor model instead of bypassing it because it exceeds the minimum character and word threshold limits defined in the short-circuit code path."
            
            mock_res = {
                "compressed_prompt": "compressed output",
                "origin_tokens": 100,
                "compressed_tokens": 70
            }
            compressor.compressor.compress_prompt = MagicMock(return_value=mock_res)
            
            compressed, meta = await compressor.compress_prompt(long_prompt)
            
            self.assertEqual(compressed, "compressed output")
            self.assertFalse(meta["bypassed"])
            self.assertEqual(meta["original_tokens"], 100)
            self.assertEqual(meta["compressed_tokens"], 70)
            compressor.compressor.compress_prompt.assert_called_once()
            
        asyncio.run(run_test())

    def test_rtk_compression_logic(self):
        """Verify RTK logic strips ANSI escape codes, progress bars, and collapses repeating log lines."""
        raw_text = "\x1B[31mError:\x1B[0m compilation failed.\n[=====>    ] 50%\nConnection timeout.\nConnection timeout.\nConnection timeout."
        expected_cleaned = "Error: compilation failed.\nConnection timeout.\n... [repeated 2 more times] ..."
        
        cleaned = compressor.compress_rtk(raw_text)
        self.assertEqual(cleaned, expected_cleaned)

    def test_caveman_compression_logic(self):
        """Verify Caveman logic strips pleasantries, articles, and prepositions depending on intensity."""
        text = "Hello! Please explain the quantum computing for me."
        
        # Lite mode (pleasantries removed)
        cleaned_lite = compressor.compress_caveman(text, intensity="lite")
        self.assertEqual(cleaned_lite, "explain the quantum computing for me.")
        
        # Full mode (pleasantries + articles + auxiliary verbs removed)
        cleaned_full = compressor.compress_caveman(text, intensity="full")
        self.assertEqual(cleaned_full, "explain quantum computing for me.")
        
        # Ultra mode (pleasantries + articles + prepositions + pronouns removed)
        cleaned_ultra = compressor.compress_caveman(text, intensity="ultra")
        self.assertEqual(cleaned_ultra, "explain quantum computing.")

    def test_compress_prompt_disabled_and_rtk(self):
        """Verify prompt compression disabled mode and RTK mode routing in compress_prompt."""
        async def run_test():
            prompt = "\x1B[32mSuccess!\x1B[0m Command executed successfully."
            
            # Disabled
            dynamic_config.compressor_method = "disabled"
            compressed_disabled, meta_disabled = await compressor.compress_prompt(prompt)
            self.assertEqual(compressed_disabled, prompt)
            self.assertTrue(meta_disabled["bypassed"])
            
            # RTK mode
            dynamic_config.compressor_method = "rtk"
            compressed_rtk, meta_rtk = await compressor.compress_prompt(prompt)
            self.assertEqual(compressed_rtk, "Success! Command executed successfully.")
            self.assertFalse(meta_rtk["bypassed"])
            self.assertEqual(meta_rtk["method"], "rtk")
            
        asyncio.run(run_test())

    def test_stacked_compression(self):
        """Verify stacked mode runs both RTK and Caveman filters sequentially."""
        async def run_test():
            dynamic_config.compressor_method = "stacked"
            prompt = "\x1B[33mWarning:\x1B[0m Hello! Could you check the logs for the database error?"
            
            # Full intensity
            dynamic_config.compressor_caveman_intensity = "full"
            compressed_full, meta_full = await compressor.compress_prompt(prompt)
            self.assertIn("Warning: check logs for database error?", compressed_full)
            self.assertEqual(meta_full["method"], "stacked_full")
            
            # Ultra intensity
            dynamic_config.compressor_caveman_intensity = "ultra"
            compressed_ultra, meta_ultra = await compressor.compress_prompt(prompt)
            self.assertIn("Warning: check logs database error?", compressed_ultra)
            self.assertEqual(meta_ultra["method"], "stacked_ultra")
            
        asyncio.run(run_test())

if __name__ == '__main__':
    unittest.main()
