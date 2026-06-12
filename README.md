# OmniRoute AI Gateway & Management Portal

OmniRoute is an intelligent, high-performance, and resilient AI API Gateway and Management Dashboard. It acts as a reverse proxy for downstream Large Language Models (LLMs) from OpenAI, Anthropic, Google, and local Ollama instances. It optimizes latencies, CPU utilization, and costs through semantic caching, dynamic routing, intelligent prompt compression, and an asynchronous, fail-safe logging system.

---

## 🏗️ System Architecture

OmniRoute is designed as a multi-container application:

*   **API Gateway (FastAPI)**: The high-performance proxy that validates incoming API requests, classifies user intent, checks the semantic cache, and routes requests to downstream providers.
*   **Web GUI & Management Backend (Laravel)**: The administrative dashboard for managing API keys, budgets, routing rules, classifier training samples, and local Ollama models.
*   **Caching & State Layer (Redis Stack)**: Stores dense vector embeddings for Vector Similarity Search (VSS) semantic caching and acts as a FIFO log buffering queue.
*   **Database Layer (MariaDB Master/Slave)**: Persistently manages API keys, logs, budgets, and routing configurations in a master-slave replication setup.
*   **Local AI Service (Ollama)**: Runs local open-source LLMs used for semantic cache rephrasing or cost-neutral text generation.

---

## ⚡ Core Features & Optimizations

### 1. Global Connection Pooling
To reduce TCP and TLS handshake overhead, all external and internal API calls (OpenAI, Anthropic, Google, Ollama, Laravel) share a globally managed asynchronous HTTP client (`httpx.AsyncClient`). This prevents socket exhaustion under heavy concurrent traffic.

### 2. Semantic Caching with Rule Validation
A hybrid, two-stage semantic caching system prevents false cache hits while keeping CPU usage minimal:
1.  **VSS Candidate Lookup**: Redis performs a Vector Similarity Search (KNN 3) to retrieve the top 3 semantically closest prompts.
2.  **Fast Path Rule Validation**: 
    *   *Digit Alignment*: If prompt digit sequences differ (e.g., `user 123` vs `user 456`), the candidate is immediately rejected.
    *   *Logical Toggles*: Words like `enable`/`disable`, `on`/`off`, or `not` must match. Mismatches trigger a cache miss.
3.  **Cross-Encoder Reranking**: Only remaining candidate prompts are scored by a local CPU-based MS-Marco Cross-Encoder model (threshold probability `>= 0.85`).
4.  **Rephrasing**: Upon a cache hit, a local Ollama instance rephrases the cached answer grammatically to match the tone and structure of the new query.

### 3. Dynamic Cache TTL per Intent
Cache expiration times (TTL) in Redis are set dynamically based on user intent:
*   `code`: **7 days** (code templates and syntax structure are highly stable).
*   `general` & `creative`: **24 hours** (moderate stability).
*   `support`: **4 hours** (support queries and system status change frequently).

### 4. Asynchronous, Fail-Safe Logging (FIFO)
Token logs are not written directly to the database to prevent blocking HTTP workers. Instead:
*   Logs are queued at the tail of a Redis list (`gateway:token_logs_queue`).
*   A background task drains the oldest 100 entries every 5 seconds and inserts them in bulk into MariaDB.
*   **Transactional Security**: Logs are only removed from Redis (via transaction pipeline `rpop`) after MariaDB confirms a successful insert. If the database goes down, logs remain queued in Redis to prevent data loss.

### 5. Prompt Compression with Short-Circuit
Longer prompts are compressed using LLMLingua-2 on CPU to save token costs on external APIs.
*   **Bypass for Short Prompts**: Prompts with less than 150 characters or less than 30 words immediately bypass the CPU-heavy compressor. This saves 5–30 ms of gateway latency for greetings or short questions.

### 6. Cost-Neutral Ollama Token Tracking
Since Ollama models run locally on private hardware, they do not incur API costs. The gateway tracks and displays token usage statistics for Ollama requests but records their database cost as exactly **$0.00**.

### 7. Automatic Model Pulling & Size Restrictions
When a client requests a model that is not installed locally:
*   The gateway queries the official Ollama registry manifest.
*   If the model file size exceeds the hardware limit (default: **16.0 GB**), the request is rejected with HTTP 400.
*   Otherwise, the model is pulled automatically, and the response is served once the download finishes.

### 8. Dynamic Cache Bypass (`/nocache`)
If a prompt contains keywords like `/nocache`, `"nicht vom cache"`, or `"force refresh"`:
*   The control keyword is stripped from the prompt to avoid confusing the downstream model.
*   Similar cached entries are deleted from Redis Stack.
*   The request bypasses the cache, queries the provider directly, and saves the fresh reply.

### 9. Consecutive Role Merging (Anthropic Compliance)
Anthropic's Messages API enforces alternating roles (`user`, `assistant`, `user`).
*   The gateway's translation engine automatically merges consecutive messages of the same role (e.g., `user` followed by another `user` prompt) into a single string separated by double newlines (`\n\n`), preventing downstream API validation crashes.

---

## 🛠️ Configuration & Environment Variables (`.env`)

Copy `.env.example` to `.env` and configure your settings:

| Variable | Description | Default Value |
| :--- | :--- | :--- |
| `APP_ENV` | Environment context | `local` or `production` |
| `PROXY_PORT` | External port for the FastAPI Gateway | `8000` |
| `LARAVEL_PORT` | External port for the Laravel Dashboard | `8080` |
| `GUI_PASSWORD` | Password to access the Laravel Web GUI | `password` |
| `OPENAI_API_KEY` | OpenAI API Key | `sk-...` |
| `ANTHROPIC_API_KEY` | Anthropic API Key | `sk-ant-...` |
| `GEMINI_API_KEY` | Google Gemini API Key | `AIzaSy...` |
| `OLLAMA_MAX_MODEL_SIZE_GB` | File size limit for local models | `16.0` |

---

## 🚀 Deployment & Start

### A. Development Mode (Local Code Mounted, Debug Active)
Use this setup for local development. Changes to PHP files are reflected instantly.

```bash
# Start containers in development mode
docker-compose up -d

# Stop containers
docker-compose down
```

### B. Production Mode (Hardened Live Environment)
This configuration closes all internal ports (Redis, databases, Ollama) to the host, mounts no local host directories, restricts CPU/memory resources for all services, and disables debug page outputs.

```bash
# Start hardened production setup
docker-compose -f docker-compose.prod.yml up -d

# Stop production setup
docker-compose -f docker-compose.prod.yml down
```

---

## 🧪 Running Automated Tests

The gateway includes **27 automated unit and integration tests** verifying endpoints, streaming converters, cache rules, database queueing, Ollama size limits, and compressor bypasses.

Run the test suite inside the running FastAPI container:

```bash
docker exec omniroute_proxy python -m unittest discover -s tests
```

**Expected output:**
```text
Ran 27 tests in 1.708s

OK
```

---

## 📂 Project Directory Structure

*   [`/proxy`](proxy): FastAPI Gateway code.
    *   [`/proxy/services/cache.py`](proxy/services/cache.py): VSS, Cross-Encoder, and semantic cache operations.
    *   [`/proxy/services/db.py`](proxy/services/db.py): Database pooling, FIFO bulk-logging, and budget updates.
    *   [`/proxy/services/compressor.py`](proxy/services/compressor.py): Prompt compression and short-circuit bypass.
    *   [`/proxy/services/translator.py`](proxy/services/translator.py): Payload translator and role merging for Anthropic.
*   [`/backend`](backend): PHP/Laravel Web Dashboard.
*   [`/docker`](docker): DB schemas, replication configs, and setup scripts.

---

## 📄 License & Copyright

This project is licensed under the terms of the **MIT License**.

Copyright (c) 2026 **Roger Bisquolm** ([info@rute4.ch](mailto:info@rute4.ch))

For details, see the [`LICENSE`](LICENSE) file in the root directory.
