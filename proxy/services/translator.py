import json
import time
import logging
from typing import Dict, Any, List, Optional, Tuple

logger = logging.getLogger(__name__)

def openai_to_anthropic_payload(
    messages: List[Dict[str, str]], 
    model: str,
    temperature: float = 1.0, 
    max_tokens: Optional[int] = None
) -> Dict[str, Any]:
    """
    Translate an OpenAI chat completions payload to Anthropic messages payload.
    - System messages must be extracted from 'messages' and sent as a top-level 'system' string.
    - Roles must be mapped (OpenAI's 'user'/'assistant' match Anthropic's).
    - 'max_tokens' is required by Anthropic; defaults to 4096 if omitted.
    """
    system_messages = []
    anthropic_messages = []
    
    for msg in messages:
        role = msg.get("role")
        content = msg.get("content", "")
        
        if role == "system":
            system_messages.append(content)
        else:
            mapped_role = role if role in ("user", "assistant") else "user"
            mapped_content = content if role in ("user", "assistant") else f"[{role}]: {content}"
            
            # Merge consecutive messages of the same role to comply with Anthropic validation
            if anthropic_messages and anthropic_messages[-1]["role"] == mapped_role:
                prev_content = anthropic_messages[-1]["content"]
                if isinstance(prev_content, str) and isinstance(mapped_content, str):
                    anthropic_messages[-1]["content"] = prev_content + "\n\n" + mapped_content
                else:
                    anthropic_messages.append({
                        "role": mapped_role,
                        "content": mapped_content
                    })
            else:
                anthropic_messages.append({
                    "role": mapped_role,
                    "content": mapped_content
                })

    payload: Dict[str, Any] = {
        "model": model,
        "messages": anthropic_messages,
        "max_tokens": max_tokens if max_tokens is not None else 4096,
        "temperature": temperature,
        "stream": True
    }
    
    if system_messages:
        # Merge system messages with newline separator
        payload["system"] = "\n".join(system_messages)
        
    return payload

class AnthropicStreamState:
    """Tracks state and usage statistics during Anthropic stream translation."""
    def __init__(self):
        self.input_tokens: int = 0
        self.output_tokens: int = 0
        self.accumulated_text: str = ""
        self.finish_reason: Optional[str] = None

    def feed_line(self, line: str, model_name: str) -> Tuple[Optional[str], Optional[str]]:
        """
        Process a single raw Anthropic stream line.
        Returns: (event_name, parsed_openai_sse_chunk_str)
        """
        if not line or not line.startswith("data: "):
            return None, None
            
        data_str = line[6:].strip()
        if data_str == "[DONE]":
            return "done", "data: [DONE]\n\n"
            
        try:
            data = json.loads(data_str)
            event_type = data.get("type")
            
            if event_type == "message_start":
                # Extract input tokens
                usage = data.get("message", {}).get("usage", {})
                self.input_tokens = usage.get("input_tokens", 0)
                return "message_start", None
                
            elif event_type == "content_block_delta":
                # Extract text delta
                text_delta = data.get("delta", {}).get("text", "")
                self.accumulated_text += text_delta
                
                # Format to OpenAI chunk
                openai_chunk = {
                    "id": "chatcmpl-omniroute",
                    "object": "chat.completion.chunk",
                    "created": int(time.time()),
                    "model": model_name,
                    "choices": [{
                        "index": 0,
                        "delta": {"content": text_delta},
                        "finish_reason": None
                    }]
                }
                return "content_block_delta", f"data: {json.dumps(openai_chunk)}\n\n"
                
            elif event_type == "message_delta":
                # Extract output tokens and stop reason
                usage = data.get("usage", {})
                if "output_tokens" in usage:
                    self.output_tokens = usage.get("output_tokens", 0)
                    
                stop_reason = data.get("delta", {}).get("stop_reason")
                if stop_reason:
                    # Map Anthropic stop reasons to OpenAI's
                    if stop_reason == "end_turn":
                        self.finish_reason = "stop"
                    elif stop_reason == "max_tokens":
                        self.finish_reason = "length"
                    else:
                        self.finish_reason = stop_reason
                        
                    # Format to OpenAI finish chunk
                    openai_chunk = {
                        "id": "chatcmpl-omniroute",
                        "object": "chat.completion.chunk",
                        "created": int(time.time()),
                        "model": model_name,
                        "choices": [{
                            "index": 0,
                            "delta": {},
                            "finish_reason": self.finish_reason
                        }]
                    }
                    return "message_delta", f"data: {json.dumps(openai_chunk)}\n\n"
                return "message_delta", None
                
            elif event_type == "message_stop":
                return "message_stop", "data: [DONE]\n\n"
                
        except Exception as e:
            logger.error(f"Error parsing Anthropic stream chunk: {e} | Raw line: {line}")
            
        return "ignored", None
