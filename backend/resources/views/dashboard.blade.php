<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OmniRoute Control Center</title>
    <!-- Outfit Font -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-color: #0b0c10;
            --card-bg: rgba(22, 26, 37, 0.65);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --accent-primary: #6366f1; /* Indigo */
            --accent-secondary: #06b6d4; /* Cyan */
            --accent-success: #10b981; /* Emerald */
            --accent-warning: #f59e0b; /* Amber */
            --accent-danger: #ef4444; /* Rose */
            --glass-glow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Outfit', sans-serif;
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.1) transparent;
        }

        /* Custom scrollbars for WebKit */
        ::-webkit-scrollbar {
            width: 4px;
            height: 4px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-primary);
            height: 100vh;
            overflow: hidden;
            background-image: radial-gradient(circle at 10% 20%, rgba(99, 102, 241, 0.05) 0%, transparent 40%),
                              radial-gradient(circle at 90% 80%, rgba(6, 118, 212, 0.05) 0%, transparent 40%);
            background-attachment: fixed;
            padding: 0.6rem 1rem;
            display: flex;
            flex-direction: column;
        }

        .container {
            max-width: 1600px;
            width: 100%;
            height: 100%;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
            min-height: 0;
        }

        /* Header block */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }

        .logo-box h1 {
            font-size: 1.4rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--text-primary) 30%, var(--accent-primary) 70%, var(--accent-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
        }

        .logo-box p {
            color: var(--text-secondary);
            font-size: 0.8rem;
            margin-top: 0.1rem;
        }

        .status-badge {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid var(--accent-success);
            color: var(--accent-success);
            padding: 0.3rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            animation: pulse-border 2s infinite;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            background-color: var(--accent-success);
            border-radius: 50%;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.8rem;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(16px);
            border-radius: 10px;
            padding: 0.5rem 0.8rem;
            box-shadow: var(--glass-glow);
            transition: transform 0.3s ease, border-color 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: rgba(99, 102, 241, 0.3);
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0.1rem 0;
            color: var(--text-primary);
        }

        .stat-value.cost {
            color: var(--accent-secondary);
        }

        .stat-desc {
            font-size: 0.65rem;
            color: var(--text-secondary);
        }

        /* Main Grid: 3-column layout */
        .main-grid {
            display: grid;
            grid-template-columns: 1.15fr 1fr 1fr;
            gap: 0.8rem;
            flex: 1;
            min-height: 0;
        }

        @media (max-width: 1200px) {
            .main-grid {
                grid-template-columns: 1.2fr 1fr;
            }
        }

        @media (max-width: 800px) {
            .main-grid {
                grid-template-columns: 1fr;
                overflow-y: auto;
            }
        }

        .column-wrapper {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
            min-height: 0;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(16px);
            border-radius: 12px;
            padding: 0.8rem 1rem;
            box-shadow: var(--glass-glow);
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            min-height: 0;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Charts section */
        .charts-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }

        .chart-box {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.03);
            border-radius: 8px;
            padding: 0.2rem;
            height: 130px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Tables & Lists */
        .table-wrapper {
            overflow-y: auto;
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            flex: 1;
            min-height: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.8rem;
        }

        th {
            background-color: rgba(255, 255, 255, 0.03);
            color: var(--text-secondary);
            font-weight: 500;
            padding: 0.4rem 0.35rem;
            border-bottom: 1px solid var(--border-color);
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
            backdrop-filter: blur(10px);
        }

        td {
            padding: 0.4rem 0.35rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background-color: rgba(255, 255, 255, 0.02);
        }

        /* Forms in dialogs */
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.8rem;
        }

        .form-row-model-weight {
            display: grid;
            grid-template-columns: 1.8fr 1fr;
            gap: 0.8rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            min-width: 0;
        }

        label {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        input, select {
            min-width: 0;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.4rem 0.6rem;
            color: var(--text-primary);
            font-size: 0.8rem;
            outline: none;
            transition: border-color 0.3s ease, background 0.3s ease;
        }

        input:focus, select:focus {
            border-color: var(--accent-primary);
            background: rgba(255, 255, 255, 0.08);
        }

        button {
            background: linear-gradient(135deg, var(--accent-primary), #4f46e5);
            border: none;
            color: white;
            font-weight: 600;
            padding: 0.4rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: opacity 0.2s ease, transform 0.1s ease;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        button:hover {
            opacity: 0.9;
        }

        button:active {
            transform: scale(0.98);
        }

        .action-col {
            text-align: right !important;
            white-space: nowrap;
            width: 95px;
        }

        .security-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.8rem;
            flex: 1;
            min-height: 0;
        }

        @media (min-width: 600px) {
            .security-grid {
                grid-template-columns: 1fr 1.2fr;
            }
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .badge-active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent-success);
        }

        .badge-inactive {
            background: rgba(239, 68, 68, 0.1);
            color: var(--accent-danger);
        }

        .badge-intent {
            background: rgba(99, 102, 241, 0.1);
            color: var(--accent-primary);
        }

        .badge-model {
            background: rgba(6, 182, 212, 0.1);
            color: var(--accent-secondary);
        }

        /* Toast notifications */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: rgba(22, 26, 37, 0.95);
            border: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            z-index: 1000;
            transform: translateY(200%);
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .toast.show {
            transform: translateY(0);
        }

        /* Dialog Modals */
        dialog {
            border: 1px solid var(--border-color);
            background: rgba(11, 12, 16, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            color: var(--text-primary);
            padding: 1.2rem;
            margin: auto;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(99, 102, 241, 0.2);
            outline: none;
            transform: scale(0.9);
            opacity: 0;
            transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.2s ease, display 0.2s ease allow-discrete;
        }
        dialog[open] {
            transform: scale(1);
            opacity: 1;
        }
        @starting-style {
            dialog[open] {
                transform: scale(0.9);
                opacity: 0;
            }
        }
        dialog::backdrop {
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
            opacity: 0;
            transition: opacity 0.2s ease, display 0.2s ease allow-discrete;
        }
        dialog[open]::backdrop {
            opacity: 1;
        }
        @starting-style {
            dialog[open]::backdrop {
                opacity: 0;
            }
        }
        .dialog-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }
        .dialog-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        .close-dialog-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.4rem;
            cursor: pointer;
            box-shadow: none;
            padding: 0;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
        }
        .close-dialog-btn:hover {
            color: var(--accent-danger);
        }
        .close-dialog-btn:focus,
        .close-dialog-btn:focus-visible {
            outline: none;
            box-shadow: none;
        }

        @keyframes pulse-border {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            70% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .logout-btn:hover {
            background: rgba(239, 68, 68, 0.25) !important;
            border-color: var(--accent-danger) !important;
            box-shadow: 0 0 12px rgba(239, 68, 68, 0.25);
            transform: translateY(-1px);
        }
        .logout-btn:active {
            transform: translateY(0);
        }

        /* Custom premium toggle switch styling */
        .switch {
            position: relative;
            display: inline-block;
            width: 38px;
            height: 20px;
            flex-shrink: 0;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(255, 255, 255, 0.1);
            transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 20px;
            border: 1px solid var(--border-color);
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 2px;
            bottom: 2px;
            background-color: var(--text-secondary);
            transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: rgba(99, 102, 241, 0.25);
            border-color: var(--accent-primary);
        }
        input:checked + .slider:before {
            transform: translateX(18px);
            background-color: var(--accent-primary);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header Section -->
        <header>
            <div class="logo-box">
                <h1>OmniRoute Control Center</h1>
                <p>Enterprise AI Gateway Management Dashboard</p>
            </div>
            <div style="display: flex; align-items: center; gap: 0.8rem;">
                <div class="status-badge">
                    <span class="status-dot"></span>
                    <span>Gateway Active (Live Polling)</span>
                </div>
                <form action="{{ route('logout') }}" method="POST" style="margin: 0; display: inline-flex;">
                    @csrf
                    <button type="submit" class="logout-btn" style="padding: 0.35rem 0.8rem; font-size: 0.75rem; background: rgba(239, 68, 68, 0.12); color: var(--accent-danger); border: 1px solid rgba(239, 68, 68, 0.4); cursor: pointer; border-radius: 9999px; font-weight: 500; display: flex; align-items: center; gap: 0.3rem; transition: all 0.2s; outline: none;">
                        <span>🚪</span> Logout
                    </button>
                </form>
            </div>
        </header>

        <!-- Stats Section -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Requests</div>
                <div class="stat-value" id="stat-requests">0</div>
                <div class="stat-desc">Throughput processed by gateway</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Cost (USD)</div>
                <div class="stat-value cost" id="stat-cost">$0.000000</div>
                <div class="stat-desc">Accumulated Downstream Costs</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Tokens Used</div>
                <div class="stat-value" id="stat-tokens">0</div>
                <div class="stat-desc">Input + Output token sums</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Average Latency</div>
                <div class="stat-value" id="stat-latency">0 ms</div>
                <div class="stat-desc">Gateway request turnaround loop</div>
            </div>
        </div>

        <!-- Main Dashboard View -->
        <div class="main-grid">
            <!-- Column 1: Live Telemetry & Audit Logs -->
            <div class="column-wrapper">
                <!-- Live Telemetry Card -->
                <div class="card" style="flex: 0 0 auto;">
                    <div class="card-header">
                        <div class="card-title">Live Telemetry Analysis</div>
                    </div>
                    <div class="charts-container">
                        <div class="chart-box">
                            <canvas id="intentsChart"></canvas>
                        </div>
                        <div class="chart-box">
                            <canvas id="modelsChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Audit Log Card -->
                <div class="card" style="flex: 1; min-height: 0;">
                    <div class="card-header">
                        <div class="card-title">Token Audit Log</div>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Key ID</th>
                                    <th>Intent</th>
                                    <th>Model</th>
                                    <th>Tokens</th>
                                    <th>Cost (USD)</th>
                                    <th>Latency</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody id="logs-table-body">
                                <tr>
                                    <td colspan="8" style="text-align: center; color: var(--text-secondary);">Loading logs...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Column 2: API Keys & Cache/Security Control -->
            <div class="column-wrapper">
                <!-- API Keys Configuration Card -->
                <div class="card" style="flex: 1; min-height: 0;">
                    <div class="card-header">
                        <div class="card-title">API Keys Configuration</div>
                        <button type="button" id="open-add-key-btn" style="padding: 0.25rem 0.4rem; font-size: 0.7rem; box-shadow: none; white-space: nowrap;">+ Key</button>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Type</th>
                                    <th>Budget Left</th>
                                    <th class="action-col">Action</th>
                                </tr>
                            </thead>
                            <tbody id="keys-table-body">
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--text-secondary);">Loading keys...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Security & Cache Control Card -->
                <div class="card" style="flex: 1.2; min-height: 0;">
                    <div class="card-header">
                        <div class="card-title">Security & Cache Control</div>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.6rem; flex: 1; min-height: 0;">
                        <!-- Semantic Cache Row -->
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                            <div style="flex: 1;">
                                <h3 style="font-size: 0.8rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.15rem;">Semantic Cache</h3>
                                <p style="font-size: 0.72rem; color: var(--text-secondary); line-height: 1.3;">
                                    Caches prompts locally via dense vector embeddings. Clearing forces fresh downstream calls.
                                </p>
                            </div>
                            <button type="button" id="clear-cache-btn" style="background: linear-gradient(135deg, var(--accent-danger), #b91c1c); box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); padding: 0.35rem 0.7rem; font-size: 0.72rem; white-space: nowrap; flex-shrink: 0; box-sizing: border-box;">
                                Clear Cache
                            </button>
                        </div>

                        <!-- Semantic Cache Sensitivity Section -->
                        <div style="display: flex; flex-direction: column; gap: 0.4rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.6rem; margin-top: 0.2rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
                                <div style="flex: 1;">
                                    <h3 style="font-size: 0.8rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.15rem;">Semantic Cache Sensitivity</h3>
                                    <p style="font-size: 0.72rem; color: var(--text-secondary); line-height: 1.3;">
                                        Cosine distance threshold. Lower values require more precise word matches.
                                    </p>
                                </div>
                                <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.2rem; min-width: 150px;">
                                    <div style="display: flex; align-items: center; gap: 0.4rem;">
                                        <input type="range" id="cache-threshold" min="0.01" max="0.50" step="0.01" value="0.10" style="width: 100px; accent-color: var(--accent-primary); cursor: pointer; background: transparent;">
                                        <span id="cache-threshold-val" style="font-size: 0.75rem; font-weight: 700; color: var(--accent-primary); font-family: monospace; width: 32px; text-align: right;">0.10</span>
                                    </div>
                                    <span id="cache-threshold-desc" style="font-size: 0.6rem; color: var(--text-secondary); font-weight: 600;">Very strict</span>
                                </div>
                            </div>
                        </div>

                        <!-- Semantic Cache Rephrasing Section -->
                        <div style="display: flex; flex-direction: column; gap: 0.6rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.8rem; margin-top: 0.4rem;">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
                                <div style="flex: 1;">
                                    <h3 style="font-size: 0.8rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.15rem;">Semantic Cache Rephrasing</h3>
                                    <p style="font-size: 0.72rem; color: var(--text-secondary); line-height: 1.3;">
                                        Rephrase cached answers dynamically using local Ollama or external API models.
                                    </p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" id="rephrase-toggle">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <!-- Collapse container for rephraser settings -->
                            <div id="rephrase-settings" style="display: none; flex-direction: column; gap: 0.5rem; background: rgba(255, 255, 255, 0.02); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.5rem; margin-top: 0.2rem;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                                    <div class="form-group">
                                        <label for="rephrase-provider" style="font-size: 0.65rem;">Provider</label>
                                        <select id="rephrase-provider" style="padding: 0.3rem 0.5rem; font-size: 0.75rem; background: rgba(0, 0, 0, 0.2); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary);">
                                            <option value="ollama">Ollama (Local)</option>
                                            <option value="openai">OpenAI</option>
                                            <option value="google">Google Gemini</option>
                                            <option value="anthropic">Anthropic Claude</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="rephrase-model" style="font-size: 0.65rem;">Model Name</label>
                                        <input type="text" id="rephrase-model" placeholder="e.g. phi3, gpt-4o-mini" style="padding: 0.3rem 0.5rem; font-size: 0.75rem; background: rgba(0, 0, 0, 0.2); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary);">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Safety Guardrails Row -->
                        <div style="display: flex; flex-direction: column; gap: 0.4rem; min-height: 0; flex: 1;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <h3 style="font-size: 0.8rem; font-weight: 600; color: var(--text-primary);">Safety Guardrails</h3>
                                <button type="button" id="open-add-keyword-btn" style="padding: 0.25rem 0.5rem; font-size: 0.72rem; box-shadow: none; white-space: nowrap;">+ Pattern</button>
                            </div>
                            <div class="table-wrapper" style="flex: 1; min-height: 0; max-height: 90px;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Unsafe Keyword Pattern</th>
                                            <th class="action-col">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="keywords-table-body">
                                        <tr>
                                            <td colspan="2" style="text-align: center; color: var(--text-secondary);">Loading guardrail keywords...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Local Ollama Models Section -->
                        <div style="display: flex; flex-direction: column; gap: 0.4rem; border-top: 1px solid var(--border-color); padding-top: 0.6rem; margin-top: 0.4rem; min-height: 0; flex: 1;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <h3 style="font-size: 0.8rem; font-weight: 600; color: var(--text-primary);">Local Ollama Models</h3>
                                <div style="display: flex; gap: 0.3rem; align-items: center;">
                                    <input type="text" id="ollama-model-input" placeholder="e.g. phi3" style="width: 80px; padding: 0.2rem 0.3rem; font-size: 0.7rem; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary);">
                                    <button type="button" id="ollama-pull-btn" style="padding: 0.2rem 0.4rem; font-size: 0.7rem; box-shadow: none; white-space: nowrap;">Pull</button>
                                </div>
                            </div>
                            <div class="table-wrapper" style="flex: 1; min-height: 0; max-height: 90px; overflow-y: auto;">
                                <table style="width: 100%;">
                                    <thead>
                                        <tr>
                                            <th>Model Name</th>
                                            <th>Size</th>
                                            <th class="action-col">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="ollama-models-table-body">
                                        <tr>
                                            <td colspan="3" style="text-align: center; color: var(--text-secondary);">Loading Ollama models...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Prompt Compression Settings Card -->
                <div class="card" style="flex: 0 0 auto; margin-top: 0.8rem;">
                    <div class="card-header">
                        <div class="card-title">Prompt Compression Control</div>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.6rem;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                            <div style="flex: 1;">
                                <h3 style="font-size: 0.8rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.15rem;">Compression Strategy</h3>
                                <p style="font-size: 0.72rem; color: var(--text-secondary); line-height: 1.3;">
                                    Select optimization technique for incoming prompts to minimize token usage.
                                </p>
                            </div>
                            <select id="compressor-method" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: rgba(0, 0, 0, 0.2); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary); width: 150px; cursor: pointer;">
                                <option value="disabled">Disabled</option>
                                <option value="llmlingua">LLMLingua-2 (CPU)</option>
                                <option value="rtk">RTK (Log/CLI Filter)</option>
                                <option value="caveman">Caveman (Telegraphic)</option>
                                <option value="stacked">Stacked (RTK + Caveman)</option>
                                <option value="rtk+llmlingua">RTK + LLMLingua-2</option>
                            </select>
                        </div>

                        <!-- Target Ratio (Slider for LLMLingua modes) -->
                        <div id="compressor-ratio-container" style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-top: 0.2rem;">
                            <div style="flex: 1;">
                                <h3 style="font-size: 0.8rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.15rem;">LLMLingua Target Ratio</h3>
                                <p style="font-size: 0.72rem; color: var(--text-secondary); line-height: 1.3;">
                                    Target proportion of tokens to retain. Lower keeps fewer tokens.
                                </p>
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.4rem; min-width: 150px; justify-content: flex-end;">
                                <input type="range" id="compressor-ratio" min="0.10" max="0.90" step="0.05" value="0.70" style="width: 100px; accent-color: var(--accent-primary); cursor: pointer; background: transparent;">
                                <span id="compressor-ratio-val" style="font-size: 0.75rem; font-weight: 700; color: var(--accent-primary); font-family: monospace; width: 32px; text-align: right;">0.70</span>
                            </div>
                        </div>

                        <!-- Caveman Intensity (Dropdown for Caveman modes) -->
                        <div id="compressor-caveman-container" style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-top: 0.2rem;">
                            <div style="flex: 1;">
                                <h3 style="font-size: 0.8rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.15rem;">Caveman Intensity</h3>
                                <p style="font-size: 0.72rem; color: var(--text-secondary); line-height: 1.3;">
                                    Lite strips filler; Full strips articles & aux verbs; Ultra strips pronouns & prepositions.
                                </p>
                            </div>
                            <select id="compressor-caveman-intensity" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; background: rgba(0, 0, 0, 0.2); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary); width: 150px; cursor: pointer;">
                                <option value="lite">Lite (Clean)</option>
                                <option value="full">Full (Telegraphic)</option>
                                <option value="ultra">Ultra (Keywords Only)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Column 3: Active Weighted Routing Rules -->
            <div class="column-wrapper">
                <!-- Active Weighted Routing Card -->
                <div class="card" style="flex: 1; min-height: 0;">
                    <div class="card-header">
                        <div class="card-title">Active Weighted Routing</div>
                        <button type="button" id="open-add-rule-btn" style="padding: 0.25rem 0.4rem; font-size: 0.7rem; box-shadow: none; white-space: nowrap;">+ Route</button>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Intent</th>
                                    <th>Model</th>
                                    <th>Weight</th>
                                    <th class="action-col">Action</th>
                                </tr>
                            </thead>
                            <tbody id="rules-table-body">
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--text-secondary);">Loading routing rules...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- AI Classifier Training Card -->
                <div class="card" style="flex: 1.2; min-height: 0; display: flex; flex-direction: column;">
                    <div class="card-header">
                        <div class="card-title">AI Classifier Training</div>
                        <button type="button" id="retrain-classifier-btn" style="background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary)); padding: 0.25rem 0.5rem; font-size: 0.7rem; box-shadow: 0 2px 8px rgba(99, 102, 241, 0.25); white-space: nowrap;">Retrain Model</button>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem; flex: 1; min-height: 0;">
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <input type="text" id="classifier-search" placeholder="Search training prompts..." style="padding: 0.35rem 0.5rem; font-size: 0.75rem; background: rgba(255, 255, 255, 0.04); border: 1px solid var(--border-color); border-radius: 6px; flex: 1;" />
                        </div>
                        <div class="table-wrapper" style="flex: 1; min-height: 0; overflow-y: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Intent</th>
                                        <th>Training Prompt</th>
                                        <th class="action-col" style="width: 100px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="classifier-table-body">
                                    <tr>
                                        <td colspan="3" style="text-align: center; color: var(--text-secondary);">Loading training samples...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Add Sample Form -->
                        <form id="add-classifier-sample-form" style="display: flex; gap: 0.4rem; align-items: center; border-top: 1px solid var(--border-color); padding-top: 0.5rem; margin-top: auto;">
                            <input type="hidden" id="edit-sample-id" value="" />
                            <select id="new-sample-intent" style="width: 90px; padding: 0.35rem; font-size: 0.75rem; background: rgba(255, 255, 255, 0.04); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary);" required>
                                <option value="general">general</option>
                                <option value="code">code</option>
                                <option value="creative">creative</option>
                                <option value="support">support</option>
                            </select>
                            <input type="text" id="new-sample-text" placeholder="Add custom prompt sample..." required style="flex: 1; padding: 0.35rem 0.5rem; font-size: 0.75rem; background: rgba(255, 255, 255, 0.04); border: 1px solid var(--border-color); border-radius: 6px;" />
                            <button type="submit" id="sample-submit-btn" style="padding: 0.35rem 0.6rem; font-size: 0.75rem; box-shadow: none; white-space: nowrap;">+ Add</button>
                            <button type="button" id="sample-cancel-btn" style="display: none; padding: 0.35rem 0.6rem; font-size: 0.75rem; background: rgba(239, 68, 68, 0.15); color: var(--accent-danger); border: 1px solid var(--accent-danger); box-shadow: none; white-space: nowrap; cursor: pointer;">Cancel</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Dialog 1: Add Key Dialog -->
    <dialog id="add-key-dialog">
        <div class="dialog-header">
            <div class="dialog-title">Generate API Key</div>
            <button type="button" class="close-dialog-btn" onclick="document.getElementById('add-key-dialog').close()">&times;</button>
        </div>
        <form id="key-form" style="display: flex; flex-direction: column; gap: 0.8rem;">
            <div class="form-group">
                <label for="key-name">Key Name</label>
                <input type="text" id="key-name" placeholder="Key name (e.g., Support Team)" required>
            </div>
            <div class="form-group">
                <label for="key-budget">USD Budget Limit ($)</label>
                <input type="number" id="key-budget" step="0.1" value="10.0" required>
            </div>
            <div class="form-group">
                <label for="key-budget-type">Budget Type</label>
                <select id="key-budget-type" required>
                    <option value="one_time">One-time Budget</option>
                    <option value="monthly">Monthly Reset (Refills on the 1st of every month)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Allowed AI Routings</label>
                <div id="allowed-routings-list" style="display: flex; flex-direction: column; gap: 0.4rem; max-height: 150px; overflow-y: auto; background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.5rem;">
                    <!-- Checkboxes populated dynamically -->
                </div>
            </div>
            <button type="submit" style="margin-top: 0.5rem; width: 100%;">Generate Token</button>
        </form>
    </dialog>

    <!-- Dialog 2: Add/Edit Routing Rule Dialog -->
    <dialog id="route-rule-dialog">
        <div class="dialog-header">
            <div class="dialog-title" id="rule-dialog-title">Add Weighted Route</div>
            <button type="button" class="close-dialog-btn" onclick="resetRuleForm(); document.getElementById('route-rule-dialog').close()">&times;</button>
        </div>
        <form id="rule-form" style="display: flex; flex-direction: column; gap: 0.8rem;">
            <input type="hidden" id="edit-rule-id" value="">
            <div class="form-row">
                <div class="form-group">
                    <label for="rule-intent">Intent Category</label>
                    <select id="rule-intent" required>
                        <option value="code">Code</option>
                        <option value="creative">Creative</option>
                        <option value="support">Support</option>
                        <option value="general">General</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="rule-provider">Provider</label>
                    <select id="rule-provider" required>
                        <option value="openai">OpenAI</option>
                        <option value="anthropic">Anthropic</option>
                        <option value="google">Google</option>
                        <option value="ollama">Ollama</option>
                    </select>
                </div>
            </div>
            <div class="form-row-model-weight">
                <div class="form-group">
                    <label for="rule-model">Model Name</label>
                    <input type="text" id="rule-model" placeholder="e.g. gpt-4o-mini" required>
                </div>
                <div class="form-group">
                    <label for="rule-weight">Weight (%)</label>
                    <input type="number" id="rule-weight" min="0" max="100" value="100" required>
                </div>
            </div>
            <div class="form-group">
                <label for="rule-url">API Endpoint URL</label>
                <input type="text" id="rule-url" placeholder="https://..." required>
            </div>
            <div class="form-group">
                <label for="rule-env">API Key Env Variable</label>
                <input type="text" id="rule-env" placeholder="e.g. OPENAI_API_KEY" required>
            </div>
            
            <!-- Collapsible Fallback Options -->
            <details style="margin-top: 0.2rem; cursor: pointer;">
                <summary style="font-size: 0.8rem; font-weight: 600; color: var(--accent-primary); text-transform: uppercase; letter-spacing: 0.5px; user-select: none;">
                    Show Fallback Settings (Optional)
                </summary>
                <div style="display: flex; flex-direction: column; gap: 0.8rem; margin-top: 0.8rem; padding-left: 0.5rem; border-left: 2px solid var(--border-color);">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="rule-fallback-provider">Fallback Provider</label>
                            <select id="rule-fallback-provider">
                                <option value="">None</option>
                                <option value="openai">OpenAI</option>
                                <option value="anthropic">Anthropic</option>
                                <option value="google">Google</option>
                                <option value="ollama">Ollama</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="rule-fallback-model">Fallback Model</label>
                            <input type="text" id="rule-fallback-model" placeholder="e.g. gpt-4o-mini">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="rule-fallback-url">Fallback URL</label>
                        <input type="text" id="rule-fallback-url" placeholder="https://...">
                    </div>
                    <div class="form-group">
                        <label for="rule-fallback-env">Fallback Env Variable</label>
                        <input type="text" id="rule-fallback-env" placeholder="e.g. OPENAI_API_KEY">
                    </div>
                </div>
            </details>

            <div style="display: flex; gap: 0.5rem; margin-top: 0.4rem;">
                <button type="submit" id="rule-submit-btn" style="flex: 1;">Save Route Config</button>
                <button type="button" id="rule-cancel-btn" style="background: rgba(255, 255, 255, 0.08); color: var(--text-primary); border: 1px solid var(--border-color); box-shadow: none;">Cancel</button>
            </div>
        </form>
    </dialog>

    <!-- Dialog 3: Add Guardrail Keyword Dialog -->
    <dialog id="add-keyword-dialog">
        <div class="dialog-header">
            <div class="dialog-title">Add Safety Guardrail Pattern</div>
            <button type="button" class="close-dialog-btn" onclick="document.getElementById('add-keyword-dialog').close()">&times;</button>
        </div>
        <form id="keyword-form" style="display: flex; flex-direction: column; gap: 0.8rem;">
            <div class="form-group">
                <label for="new-keyword">Unsafe Keyword Pattern</label>
                <input type="text" id="new-keyword" placeholder="e.g. system instructions" required>
            </div>
            <button type="submit" style="margin-top: 0.5rem; width: 100%;">Add Keyword Pattern</button>
        </form>
    </dialog>

    <!-- Dialog 4: View API Key Dialog -->
    <dialog id="view-key-dialog">
        <div class="dialog-header">
            <div class="dialog-title">API Key Details</div>
            <button type="button" class="close-dialog-btn" onclick="document.getElementById('view-key-dialog').close()">&times;</button>
        </div>
        <form id="edit-key-form" style="display: flex; flex-direction: column; gap: 0.8rem;">
            <input type="hidden" id="view-key-id" value="">
            <div class="form-group">
                <label>Key Name</label>
                <div id="view-key-name-display" style="font-weight: 600; font-size: 0.95rem; color: var(--text-primary); padding: 0.2rem 0;"></div>
            </div>
            <div style="display: flex; gap: 0.8rem;">
                <div class="form-group" style="flex: 1;">
                    <label for="view-key-budget">USD Budget Limit ($)</label>
                    <input type="number" id="view-key-budget" step="0.1" required>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="view-key-remaining-budget">Remaining Budget ($)</label>
                    <input type="number" id="view-key-remaining-budget" step="0.0001" required>
                </div>
            </div>
            <div class="form-group">
                <label for="view-key-budget-type">Budget Type</label>
                <select id="view-key-budget-type" required>
                    <option value="one_time">One-time Budget</option>
                    <option value="monthly">Monthly Reset (Refills on the 1st of every month)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Allowed AI Routings</label>
                <div id="view-key-allowed-rules-edit-list" style="display: flex; flex-direction: column; gap: 0.4rem; max-height: 150px; overflow-y: auto; background: rgba(255, 255, 255, 0.03); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.5rem;">
                    <!-- Checkboxes populated dynamically -->
                </div>
            </div>
            <div class="form-group">
                <label>API Key Token</label>
                <div style="display: flex; gap: 0.5rem; align-items: center; margin-top: 0.2rem;">
                    <input type="text" id="view-key-token-input" readonly style="flex: 1; font-family: monospace; font-size: 0.85rem; background: rgba(255, 255, 255, 0.08); border-color: rgba(99, 102, 241, 0.4); padding: 0.45rem 0.6rem;" />
                    <button type="button" id="copy-key-btn" style="white-space: nowrap; flex-shrink: 0; padding: 0.45rem 1rem;">Copy</button>
                </div>
            </div>
            <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem;">
                <button type="submit" style="flex: 1;">Save Changes</button>
                <button type="button" onclick="document.getElementById('view-key-dialog').close()" style="background: rgba(255, 255, 255, 0.08); color: var(--text-primary); border: 1px solid var(--border-color); box-shadow: none; padding: 0.45rem 1rem;">Close</button>
            </div>
        </form>
    </dialog>

    <!-- Toast Notification Block -->
    <div id="toast" class="toast">
        <span id="toast-icon">✨</span>
        <span id="toast-text">Action completed successfully</span>
    </div>

    <script>
        // Global variables for ChartJS objects
        let intentsChartObj = null;
        let modelsChartObj = null;

        // Initialize Charts
        function initCharts() {
            const ctxIntents = document.getElementById('intentsChart').getContext('2d');
            intentsChartObj = new Chart(ctxIntents, {
                type: 'doughnut',
                data: {
                    labels: ['code', 'creative', 'support', 'general'],
                    datasets: [{
                        data: [0, 0, 0, 0],
                        backgroundColor: ['#6366f1', '#a855f7', '#06b6d4', '#10b981'],
                        borderWidth: 1,
                        borderColor: '#1f2937'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        title: { display: true, text: 'Intent Distribution', color: '#f3f4f6', font: { family: 'Outfit', size: 10, weight: '600' } }
                    }
                }
            });

            const ctxModels = document.getElementById('modelsChart').getContext('2d');
            modelsChartObj = new Chart(ctxModels, {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Requests',
                        data: [],
                        backgroundColor: '#6366f1',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#9ca3af', font: { family: 'Outfit', size: 8 } } },
                        y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#9ca3af', font: { family: 'Outfit', size: 8 } } }
                    },
                    plugins: {
                        legend: { display: false },
                        title: { display: true, text: 'Active Downstream Models', color: '#f3f4f6', font: { family: 'Outfit', size: 10, weight: '600' } }
                    }
                }
            });
        }

        // Live stats polling
        async function fetchDashboardStats() {
            try {
                const response = await fetch(`/api/dashboard/metrics?_=${Date.now()}`);
                if (!response.ok) throw new Error("Stats fetch failed");
                const data = await response.json();

                // 1. Update Core counters
                document.getElementById('stat-requests').innerText = data.summary.total_requests.toLocaleString();
                document.getElementById('stat-cost').innerText = '$' + data.summary.total_cost_usd.toFixed(6);
                document.getElementById('stat-tokens').innerText = data.summary.total_tokens_used.toLocaleString();
                document.getElementById('stat-latency').innerText = data.summary.avg_latency_ms + ' ms';

                // 2. Update Intents Chart
                const intentData = [0, 0, 0, 0];
                data.intents.forEach(item => {
                    const idx = ['code', 'creative', 'support', 'general'].indexOf(item.intent);
                    if (idx !== -1) intentData[idx] = item.count;
                });
                intentsChartObj.data.datasets[0].data = intentData;
                intentsChartObj.update();

                // 3. Update Models Chart
                const modelNames = data.models.map(m => m.model);
                const modelRequests = data.models.map(m => m.count);
                modelsChartObj.data.labels = modelNames;
                modelsChartObj.data.datasets[0].data = modelRequests;
                modelsChartObj.update();

                // 4. Update Audit logs table
                const logsTbody = document.getElementById('logs-table-body');
                if (data.recent_logs.length === 0) {
                    logsTbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-secondary);">No logs registered yet.</td></tr>`;
                } else {
                    logsTbody.innerHTML = data.recent_logs.map(log => {
                        const date = new Date(log.created_at).toLocaleTimeString();
                        return `
                            <tr>
                                <td>#${log.id}</td>
                                <td><span style="font-family: monospace; font-size: 0.8rem; background: rgba(255,255,255,0.05); padding: 0.2rem 0.4rem; border-radius: 4px;">key_${log.api_key_id}</span></td>
                                <td><span class="badge badge-intent">${log.intent}</span></td>
                                <td><span class="badge badge-model">${log.model}</span></td>
                                <td>${log.total_tokens}</td>
                                <td><span style="color: var(--accent-secondary); font-weight: 500;">$${parseFloat(log.cost_usd || 0).toFixed(6)}</span></td>
                                <td>${log.latency_ms} ms</td>
                                <td>${date}</td>
                            </tr>
                        `;
                    }).join('');
                }

                // 5. Update API keys configuration
                const keysTbody = document.getElementById('keys-table-body');
                if (data.api_keys.length === 0) {
                    keysTbody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-secondary);">No keys active. Create one below.</td></tr>`;
                } else {
                    keysTbody.innerHTML = data.api_keys.map(key => {
                        const statusClass = key.status === 'active' ? 'badge-active' : 'badge-inactive';
                        const budgetTypeText = key.budget_type === 'monthly' ? 'monthly' : 'one-time';
                        const typeBadgeColor = key.budget_type === 'monthly' ? 'rgba(99, 102, 241, 0.15)' : 'rgba(255, 255, 255, 0.05)';
                        const typeTextColor = key.budget_type === 'monthly' ? 'var(--accent-primary)' : 'var(--text-secondary)';
                        const typeBorderColor = key.budget_type === 'monthly' ? 'rgba(99, 102, 241, 0.4)' : 'var(--border-color)';
                        return `
                            <tr>
                                <td style="font-weight: 500;">
                                    <span class="view-key-trigger" 
                                        data-key-id="${key.id}" 
                                        data-key-name="${key.name}" 
                                        data-plain-key="${key.plain_key || ''}" 
                                        data-allowed-rules='${JSON.stringify(key.allowed_rules || [])}' 
                                        data-total-budget="${key.total_budget}"
                                        data-budget-type="${key.budget_type || 'one_time'}"
                                        data-remaining-budget="${key.remaining_budget}"
                                        style="cursor: pointer; color: var(--accent-primary); border-bottom: 1px dashed rgba(99, 102, 241, 0.4); display: inline-flex; align-items: center; gap: 0.25rem;" 
                                        title="Click to view API Key">
                                        ${key.name}
                                    </span>
                                </td>
                                <td><span class="badge ${statusClass}">${key.status}</span></td>
                                <td>
                                    <span class="badge" style="background: ${typeBadgeColor}; color: ${typeTextColor}; border: 1px solid ${typeBorderColor}; text-transform: uppercase; font-size: 0.65rem; font-weight: 600; padding: 0.15rem 0.4rem; border-radius: 4px;">
                                        ${budgetTypeText}
                                    </span>
                                </td>
                                <td>
                                    <span style="color: var(--accent-success); font-weight: 600;">$${parseFloat(key.remaining_budget).toFixed(4)}</span> 
                                    <span style="font-size: 0.75rem; color: var(--text-secondary);">/ $${parseFloat(key.total_budget).toFixed(2)}</span>
                                </td>
                                <td class="action-col">
                                    <button type="button" class="delete-key-btn" data-key-id="${key.id}" style="padding: 0.15rem 0.4rem; font-size: 0.75rem; background: rgba(239, 68, 68, 0.15); color: var(--accent-danger); border: 1px solid var(--accent-danger); box-shadow: none;">Delete</button>
                                </td>
                            </tr>
                        `;
                    }).join('');

                    // Add click listeners to view key triggers
                    document.querySelectorAll('.view-key-trigger').forEach(trigger => {
                        trigger.addEventListener('click', (e) => {
                            const id = trigger.getAttribute('data-key-id');
                            const name = trigger.getAttribute('data-key-name');
                            const plainKey = trigger.getAttribute('data-plain-key');
                            const allowedRules = trigger.getAttribute('data-allowed-rules');
                            const totalBudget = trigger.getAttribute('data-total-budget');
                            const budgetType = trigger.getAttribute('data-budget-type');
                            const remainingBudget = trigger.getAttribute('data-remaining-budget');
                            showViewKeyDialog(id, name, plainKey, allowedRules, totalBudget, budgetType, remainingBudget);
                        });
                    });

                    // Add click listeners to delete buttons
                    document.querySelectorAll('.delete-key-btn').forEach(btn => {
                        btn.addEventListener('click', async (e) => {
                            const id = btn.getAttribute('data-key-id');
                            await deleteApiKey(id);
                        });
                    });
                }

                // 6. Update routing rules configuration
                window.globalRoutingRules = data.routing_rules;
                const rulesTbody = document.getElementById('rules-table-body');
                if (data.routing_rules.length === 0) {
                    rulesTbody.innerHTML = `<tr><td colspan="4" style="text-align: center; color: var(--text-secondary);">No rules seeded.</td></tr>`;
                } else {
                    rulesTbody.innerHTML = data.routing_rules.map(rule => {
                        return `
                            <tr>
                                <td><span class="badge badge-intent">${rule.intent}</span></td>
                                <td><span style="font-family: monospace; font-size: 0.75rem; color: var(--accent-secondary); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100px; display: inline-block;" title="${rule.model}">${rule.model}</span></td>
                                <td><span style="color: var(--accent-warning); font-weight: 600;">${rule.weight}%</span></td>
                                <td class="action-col">
                                    <button type="button" class="edit-rule-btn" data-rule-id="${rule.id}" style="padding: 0.15rem 0.4rem; font-size: 0.75rem; background: rgba(99, 102, 241, 0.15); color: var(--accent-primary); border: 1px solid var(--accent-primary); box-shadow: none; margin-right: 0.2rem;">Edit</button>
                                    <button type="button" class="delete-rule-btn" data-rule-id="${rule.id}" style="padding: 0.15rem 0.4rem; font-size: 0.75rem; background: rgba(239, 68, 68, 0.15); color: var(--accent-danger); border: 1px solid var(--accent-danger); box-shadow: none;">Delete</button>
                                </td>
                            </tr>
                        `;
                    }).join('');

                    // Add click listeners to edit buttons
                    document.querySelectorAll('.edit-rule-btn').forEach(btn => {
                        btn.addEventListener('click', (e) => {
                            const id = e.target.getAttribute('data-rule-id');
                            loadRoutingRuleForEditing(id);
                        });
                    });

                    // Add click listeners to delete buttons
                    document.querySelectorAll('.delete-rule-btn').forEach(btn => {
                        btn.addEventListener('click', async (e) => {
                            const id = e.target.getAttribute('data-rule-id');
                            await deleteRoutingRule(id);
                        });
                    });
                }

            } catch (err) {
                console.error("Dashboard metrics poll failed:", err);
            }
        }

        // Toast Helper
        function showToast(text, icon = '✨') {
            const toast = document.getElementById('toast');
            document.getElementById('toast-icon').innerText = icon;
            document.getElementById('toast-text').innerText = text;
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
        }

        // Show API Key view modal
        function showViewKeyDialog(id, name, plainKey, allowedRulesJson, totalBudget, budgetType, remainingBudget) {
            document.getElementById('view-key-id').value = id;
            document.getElementById('view-key-name-display').innerText = name;
            const tokenInput = document.getElementById('view-key-token-input');
            tokenInput.value = plainKey || 'No plain key stored (legacy key)';

            // Populate budget fields
            document.getElementById('view-key-budget').value = totalBudget ? parseFloat(totalBudget).toFixed(2) : '10.00';
            document.getElementById('view-key-remaining-budget').value = remainingBudget ? parseFloat(remainingBudget).toFixed(4) : '10.0000';
            document.getElementById('view-key-budget-type').value = budgetType || 'one_time';
            
            // Populate allowed rules checkboxes in View/Edit Dialog
            const allowedRules = JSON.parse(allowedRulesJson || '[]');
            const container = document.getElementById('view-key-allowed-rules-edit-list');
            if (container) {
                const rules = window.globalRoutingRules || [];
                if (rules.length === 0) {
                    container.innerHTML = `<span style="font-size: 0.75rem; color: var(--text-secondary);">No active routings available.</span>`;
                } else {
                    container.innerHTML = rules.map(rule => {
                        const isChecked = allowedRules.includes(rule.id) ? 'checked' : '';
                        return `
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; text-transform: none; font-size: 0.8rem; font-weight: normal; color: var(--text-primary); margin-bottom: 0.2rem;">
                                <input type="checkbox" name="edit_allowed_rules" value="${rule.id}" ${isChecked} style="width: auto; margin: 0; cursor: pointer;">
                                <span class="badge badge-intent" style="padding: 0.1rem 0.3rem; font-size: 0.65rem;">${rule.intent}</span>
                                <span style="font-family: monospace; color: var(--accent-secondary); font-size: 0.75rem;">${rule.model}</span>
                                <span style="color: var(--text-secondary); font-size: 0.7rem;">(${rule.provider})</span>
                            </label>
                        `;
                    }).join('');
                }
            }
            
            // Reset Copy button text/style
            const copyBtn = document.getElementById('copy-key-btn');
            copyBtn.innerText = 'Copy';
            copyBtn.style.background = 'linear-gradient(135deg, var(--accent-primary), #4f46e5)';

            document.getElementById('view-key-dialog').showModal();
        }

        // Populate allowed routings checkbox list dynamically
        function populateAllowedRoutingsCheckboxList() {
            const container = document.getElementById('allowed-routings-list');
            if (!container) return;
            
            const rules = window.globalRoutingRules || [];
            if (rules.length === 0) {
                container.innerHTML = `<span style="font-size: 0.75rem; color: var(--text-secondary);">No active routings available.</span>`;
                return;
            }
            
            container.innerHTML = rules.map(rule => {
                return `
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; text-transform: none; font-size: 0.8rem; font-weight: normal; color: var(--text-primary); margin-bottom: 0.2rem;">
                        <input type="checkbox" name="allowed_rules" value="${rule.id}" checked style="width: auto; margin: 0; cursor: pointer;">
                        <span class="badge badge-intent" style="padding: 0.1rem 0.3rem; font-size: 0.65rem;">${rule.intent}</span>
                        <span style="font-family: monospace; color: var(--accent-secondary); font-size: 0.75rem;">${rule.model}</span>
                        <span style="color: var(--text-secondary); font-size: 0.7rem;">(${rule.provider})</span>
                    </label>
                `;
            }).join('');
        }

        // Key Form handler
        document.getElementById('key-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const name = document.getElementById('key-name').value;
            const budget = parseFloat(document.getElementById('key-budget').value);
            const budgetType = document.getElementById('key-budget-type').value;
            
            // Gather selected checkboxes
            const selectedRules = [];
            document.querySelectorAll('input[name="allowed_rules"]:checked').forEach(cb => {
                selectedRules.push(parseInt(cb.value));
            });

            try {
                const res = await fetch('/api/keys', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ name: name, total_budget: budget, budget_type: budgetType, allowed_rules: selectedRules })
                });
                if (!res.ok) throw new Error("Key creation failed");
                const data = await res.json();
                
                document.getElementById('key-name').value = '';
                document.getElementById('add-key-dialog').close();
                
                // Show view key dialog immediately
                showViewKeyDialog(data.details.id, data.details.name, data.plain_key, JSON.stringify(data.details.allowed_rules || []), data.details.total_budget, data.details.budget_type, data.details.remaining_budget);
                showToast("API Key created!", '🔑');
                fetchDashboardStats();
            } catch (err) {
                showToast("Failed to create key", '❌');
            }
        });

        // Rule Form handler
        document.getElementById('rule-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const editId = document.getElementById('edit-rule-id').value;
            const intent = document.getElementById('rule-intent').value;
            const provider = document.getElementById('rule-provider').value;
            const model = document.getElementById('rule-model').value;
            
            let url = document.getElementById('rule-url').value.trim();
            if (url && !/^https?:\/\//i.test(url)) {
                url = 'http://' + url;
            }
            
            const env = document.getElementById('rule-env').value;
            const weight = parseInt(document.getElementById('rule-weight').value);
            
            const fallback_provider = document.getElementById('rule-fallback-provider').value;
            const fallback_model = document.getElementById('rule-fallback-model').value;
            
            let fallback_url = document.getElementById('rule-fallback-url').value.trim();
            if (fallback_url && !/^https?:\/\//i.test(fallback_url)) {
                fallback_url = 'http://' + fallback_url;
            }
            
            const fallback_env = document.getElementById('rule-fallback-env').value;

            const requestUrl = editId ? `/api/rules/${editId}` : '/api/rules';
            const requestMethod = editId ? 'PUT' : 'POST';

            try {
                const res = await fetch(requestUrl, {
                    method: requestMethod,
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        intent: intent,
                        provider: provider,
                        model: model,
                        url: url,
                        api_key_env: env,
                        weight: weight,
                        fallback_provider: fallback_provider || null,
                        fallback_model: fallback_model || null,
                        fallback_url: fallback_url || null,
                        fallback_api_key_env: fallback_env || null,
                    })
                });
                if (!res.ok) {
                    const errData = await res.json().catch(() => ({}));
                    let errMsg = editId ? "Failed to update routing rule" : "Failed to save routing rule";
                    if (errData.errors) {
                        const firstErrorKey = Object.keys(errData.errors)[0];
                        if (firstErrorKey && errData.errors[firstErrorKey].length > 0) {
                            errMsg = errData.errors[firstErrorKey][0];
                        }
                    } else if (errData.message) {
                        errMsg = errData.message;
                    }
                    throw new Error(errMsg);
                }
                showToast(editId ? "Weighted route updated and synced to Gateway!" : "Weighted route saved and synced to Gateway!", '🔀');
                
                resetRuleForm();
                document.getElementById('route-rule-dialog').close();
                fetchDashboardStats();
            } catch (err) {
                showToast(err.message, '❌');
            }
        });

        // Fetch Guardrail Keywords
        async function fetchKeywords() {
            try {
                const response = await fetch(`/api/guardrails/keywords?_=${Date.now()}`);
                if (!response.ok) throw new Error("Keywords fetch failed");
                const keywords = await response.json();
                
                const tbody = document.getElementById('keywords-table-body');
                if (keywords.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="2" style="text-align: center; color: var(--text-secondary);">No custom keywords registered.</td></tr>`;
                } else {
                    tbody.innerHTML = keywords.map(kw => {
                        return `
                            <tr>
                                <td style="font-family: monospace; font-size: 0.85rem; color: var(--accent-danger); font-weight: 500;">${kw}</td>
                                <td class="action-col">
                                    <button type="button" class="delete-kw-btn" data-keyword="${kw}" style="padding: 0.15rem 0.4rem; font-size: 0.75rem; background: rgba(239, 68, 68, 0.15); color: var(--accent-danger); border: 1px solid var(--accent-danger); box-shadow: none;">Delete</button>
                                </td>
                            </tr>
                        `;
                    }).join('');

                    // Add click listeners to delete buttons
                    document.querySelectorAll('.delete-kw-btn').forEach(btn => {
                        btn.addEventListener('click', async (e) => {
                            const keyword = e.target.getAttribute('data-keyword');
                            await deleteKeyword(keyword);
                        });
                    });
                }
            } catch (err) {
                console.error("Failed to load keywords:", err);
            }
        }

        // Add Guardrail Keyword
        async function addKeyword(keyword) {
            try {
                const response = await fetch('/api/guardrails/keywords', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ keyword: keyword })
                });
                if (!response.ok) throw new Error("Add keyword failed");
                showToast("Guardrail keyword added successfully!", '🛡️');
                document.getElementById('new-keyword').value = '';
                fetchKeywords();
            } catch (err) {
                showToast("Failed to add keyword", '❌');
            }
        }

        // Delete Guardrail Keyword
        async function deleteKeyword(keyword) {
            try {
                const response = await fetch('/api/guardrails/keywords', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ keyword: keyword })
                });
                if (!response.ok) throw new Error("Delete keyword failed");
                showToast("Guardrail keyword removed!", '🗑️');
                fetchKeywords();
            } catch (err) {
                showToast("Failed to delete keyword", '❌');
            }
        }

        // Delete Routing Rule
        async function deleteRoutingRule(id) {
            try {
                const response = await fetch(`/api/rules/${id}`, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error("Delete rule failed");
                showToast("Routing rule deleted and synced to gateway!", '🗑️');
                fetchDashboardStats();
            } catch (err) {
                showToast("Failed to delete routing rule", '❌');
            }
        }

        // Delete API Key
        async function deleteApiKey(id) {
            try {
                const response = await fetch(`/api/keys/${id}`, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error("Delete API key failed");
                showToast("API Key deleted and purged from cache!", '🗑️');
                fetchDashboardStats();
            } catch (err) {
                showToast("Failed to delete API key", '❌');
            }
        }

        // Load Routing Rule into edit form
        function loadRoutingRuleForEditing(id) {
            if (!window.globalRoutingRules) return;
            const rule = window.globalRoutingRules.find(r => r.id == id);
            if (!rule) return;

            document.getElementById('edit-rule-id').value = rule.id;
            document.getElementById('rule-intent').value = rule.intent;
            document.getElementById('rule-provider').value = rule.provider;
            document.getElementById('rule-model').value = rule.model;
            document.getElementById('rule-url').value = rule.url;
            document.getElementById('rule-env').value = rule.api_key_env;
            document.getElementById('rule-weight').value = rule.weight;
            
            document.getElementById('rule-fallback-provider').value = rule.fallback_provider || '';
            document.getElementById('rule-fallback-model').value = rule.fallback_model || '';
            document.getElementById('rule-fallback-url').value = rule.fallback_url || '';
            document.getElementById('rule-fallback-env').value = rule.fallback_api_key_env || '';

            // Update button texts/actions for edit mode
            document.getElementById('rule-dialog-title').innerText = "Edit Weighted Route";
            document.getElementById('rule-submit-btn').innerText = "Update Route Config";
            document.getElementById('rule-cancel-btn').style.display = "block";

            // Open dialog
            document.getElementById('route-rule-dialog').showModal();

            // Focus on the form input
            document.getElementById('rule-model').focus();
            showToast("Routing rule loaded! Update to apply changes.", '🔀');
        }

        // Reset Routing Rule form
        function resetRuleForm() {
            document.getElementById('edit-rule-id').value = "";
            document.getElementById('rule-model').value = '';
            document.getElementById('rule-url').value = '';
            document.getElementById('rule-env').value = '';
            document.getElementById('rule-weight').value = 100;
            document.getElementById('rule-fallback-provider').value = '';
            document.getElementById('rule-fallback-model').value = '';
            document.getElementById('rule-fallback-url').value = '';
            document.getElementById('rule-fallback-env').value = '';

            document.getElementById('rule-dialog-title').innerText = "Add Weighted Route";
            document.getElementById('rule-submit-btn').innerText = "Save Route Config";
            document.getElementById('rule-cancel-btn').style.display = "none";
        }

        // Classifier Samples
        window.globalClassifierSamples = [];

        async function fetchClassifierSamples() {
            try {
                const response = await fetch(`/api/classifier/samples?_=${Date.now()}`);
                if (!response.ok) throw new Error("Failed to fetch classifier samples");
                const data = await response.json();
                window.globalClassifierSamples = data;
                renderClassifierSamples();
            } catch (err) {
                console.error(err);
                const container = document.getElementById('classifier-table-body');
                if (container) {
                    container.innerHTML = `<tr><td colspan="3" style="text-align: center; color: var(--accent-danger);">Failed to load samples.</td></tr>`;
                }
            }
        }

        function renderClassifierSamples(filterText = '') {
            const container = document.getElementById('classifier-table-body');
            if (!container) return;

            const filtered = window.globalClassifierSamples.filter(sample => {
                const query = filterText.toLowerCase();
                return sample.intent.toLowerCase().includes(query) || sample.sample_text.toLowerCase().includes(query);
            });

            if (filtered.length === 0) {
                container.innerHTML = `<tr><td colspan="3" style="text-align: center; color: var(--text-secondary);">No training samples found.</td></tr>`;
                return;
            }

            container.innerHTML = filtered.map(sample => {
                return `
                    <tr>
                        <td><span class="badge badge-intent">${sample.intent}</span></td>
                        <td style="font-size: 0.75rem; white-space: normal; word-break: break-word;">${escapeHtml(sample.sample_text)}</td>
                        <td class="action-col">
                            <button type="button" class="edit-sample-btn" data-sample-id="${sample.id}" style="padding: 0.15rem 0.35rem; font-size: 0.7rem; background: rgba(99, 102, 241, 0.15); color: var(--accent-primary); border: 1px solid var(--accent-primary); box-shadow: none; margin-right: 0.2rem; cursor: pointer;">Edit</button>
                            <button type="button" class="delete-sample-btn" data-sample-id="${sample.id}" style="padding: 0.15rem 0.35rem; font-size: 0.7rem; background: rgba(239, 68, 68, 0.15); color: var(--accent-danger); border: 1px solid var(--accent-danger); box-shadow: none; cursor: pointer;">Delete</button>
                        </td>
                    </tr>
                `;
            }).join('');

            // Bind edit click handlers
            container.querySelectorAll('.edit-sample-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const id = btn.getAttribute('data-sample-id');
                    loadSampleForEditing(id);
                });
            });

            // Bind delete click handlers
            container.querySelectorAll('.delete-sample-btn').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    const id = btn.getAttribute('data-sample-id');
                    await deleteClassifierSample(id);
                });
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.innerText = text;
            return div.innerHTML;
        }

        function loadSampleForEditing(id) {
            const sample = window.globalClassifierSamples.find(s => s.id == id);
            if (!sample) return;

            document.getElementById('edit-sample-id').value = sample.id;
            document.getElementById('new-sample-intent').value = sample.intent;
            document.getElementById('new-sample-text').value = sample.sample_text;
            
            document.getElementById('sample-submit-btn').innerText = "Save";
            document.getElementById('sample-cancel-btn').style.display = "inline-block";
            document.getElementById('new-sample-text').focus();
        }

        function resetSampleForm() {
            document.getElementById('edit-sample-id').value = '';
            document.getElementById('new-sample-text').value = '';
            document.getElementById('new-sample-intent').selectedIndex = 0;
            
            document.getElementById('sample-submit-btn').innerText = "+ Add";
            document.getElementById('sample-cancel-btn').style.display = "none";
        }

        async function updateClassifierSample(id, intent, text) {
            try {
                const response = await fetch(`/api/classifier/samples/${id}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ intent, sample_text: text })
                });
                if (!response.ok) throw new Error("Failed to update sample");
                showToast("Training sample updated!", '🧠');
                resetSampleForm();
                await fetchClassifierSamples();
            } catch (err) {
                showToast("Failed to update training sample", '❌');
            }
        }

        async function addClassifierSample(intent, text) {
            try {
                const response = await fetch('/api/classifier/samples', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ intent, sample_text: text })
                });
                if (!response.ok) throw new Error("Failed to add sample");
                showToast("Training sample added!", '🧠');
                
                // Clear input
                document.getElementById('new-sample-text').value = '';
                await fetchClassifierSamples();
            } catch (err) {
                showToast("Failed to add training sample", '❌');
            }
        }

        async function deleteClassifierSample(id) {
            try {
                const response = await fetch(`/api/classifier/samples/${id}`, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) throw new Error("Failed to delete sample");
                showToast("Training sample deleted", '🗑️');
                await fetchClassifierSamples();
            } catch (err) {
                showToast("Failed to delete training sample", '❌');
            }
        }

        async function retrainClassifier() {
            const btn = document.getElementById('retrain-classifier-btn');
            const originalText = btn.innerText;
            btn.innerText = "Training...";
            btn.disabled = true;
            btn.style.opacity = 0.7;

            try {
                const response = await fetch('/api/classifier/retrain', { method: 'POST' });
                if (!response.ok) throw new Error("Retrain request failed");
                showToast("Model retraining broadcasted to gateway!", '🚀');
            } catch (err) {
                showToast("Failed to trigger retraining", '❌');
            } finally {
                setTimeout(() => {
                    btn.innerText = originalText;
                    btn.disabled = false;
                    btn.style.opacity = 1;
                }, 2000);
            }
        }

        // Clear Semantic Cache
        async function clearSemanticCache() {
            try {
                const response = await fetch('/api/cache/clear', { method: 'POST' });
                if (!response.ok) throw new Error("Cache clear failed");
                const result = await response.json();
                showToast(`Semantic Cache cleared! Removed ${result.count} entries.`, '🧹');
            } catch (err) {
                showToast("Failed to clear cache", '❌');
            }
        }

        function updateThresholdDescription(val) {
            const desc = document.getElementById('cache-threshold-desc');
            const v = parseFloat(val);
            if (v <= 0.12) {
                desc.textContent = "Very strict (identical wording)";
                desc.style.color = "#ef4444"; // red-ish
            } else if (v <= 0.22) {
                desc.textContent = "Moderately strict (minor variations)";
                desc.style.color = "#f59e0b"; // amber/orange
            } else if (v <= 0.32) {
                desc.textContent = "Recommended / Tolerant (synonymous)";
                desc.style.color = "#10b981"; // emerald green
            } else {
                desc.textContent = "Very tolerant (risk of false matches)";
                desc.style.color = "#a855f7"; // purple
            }
        }

        // Fetch Compressor Configuration
        async function fetchCompressorConfig() {
            try {
                const response = await fetch(`/api/compressor/config?_=${Date.now()}`);
                if (!response.ok) throw new Error("Compressor config fetch failed");
                const config = await response.json();
                
                const methodSelect = document.getElementById('compressor-method');
                const ratioInput = document.getElementById('compressor-ratio');
                const ratioVal = document.getElementById('compressor-ratio-val');
                const intensitySelect = document.getElementById('compressor-caveman-intensity');

                methodSelect.value = config.method;
                ratioInput.value = config.ratio;
                ratioVal.textContent = parseFloat(config.ratio).toFixed(2);
                intensitySelect.value = config.caveman_intensity;

                updateCompressorUI(config.method);
            } catch (err) {
                console.error("Failed to load compressor config:", err);
            }
        }

        // Save Compressor Configuration
        async function saveCompressorConfig() {
            const methodSelect = document.getElementById('compressor-method');
            const ratioInput = document.getElementById('compressor-ratio');
            const intensitySelect = document.getElementById('compressor-caveman-intensity');

            updateCompressorUI(methodSelect.value);

            try {
                const response = await fetch('/api/compressor/config', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        method: methodSelect.value,
                        ratio: parseFloat(ratioInput.value),
                        caveman_intensity: intensitySelect.value
                    })
                });
                if (!response.ok) throw new Error("Save compressor config failed");
                showToast("Compressor settings updated and synced to gateway!", '🔄');
            } catch (err) {
                showToast("Failed to save compressor settings", '❌');
            }
        }

        // Update Compressor UI visibility based on method
        function updateCompressorUI(method) {
            const ratioContainer = document.getElementById('compressor-ratio-container');
            const cavemanContainer = document.getElementById('compressor-caveman-container');

            const hasRatio = ['llmlingua', 'rtk+llmlingua'].includes(method);
            const hasCaveman = ['caveman', 'stacked'].includes(method);

            ratioContainer.style.display = hasRatio ? 'flex' : 'none';
            cavemanContainer.style.display = hasCaveman ? 'flex' : 'none';
        }

        // Fetch Rephrase Configuration
        async function fetchRephraseConfig() {
            try {
                const response = await fetch(`/api/rephrase/config?_=${Date.now()}`);
                if (!response.ok) throw new Error("Rephrase config fetch failed");
                const config = await response.json();
                
                const toggle = document.getElementById('rephrase-toggle');
                const providerSelect = document.getElementById('rephrase-provider');
                const modelInput = document.getElementById('rephrase-model');
                const settingsDiv = document.getElementById('rephrase-settings');
                const thresholdInput = document.getElementById('cache-threshold');
                const thresholdVal = document.getElementById('cache-threshold-val');

                toggle.checked = config.enabled;
                providerSelect.value = config.provider;
                modelInput.value = config.model;

                settingsDiv.style.display = config.enabled ? 'flex' : 'none';

                if (config.threshold !== undefined) {
                    thresholdInput.value = config.threshold;
                    thresholdVal.textContent = parseFloat(config.threshold).toFixed(2);
                    updateThresholdDescription(config.threshold);
                }
            } catch (err) {
                console.error("Failed to load rephrase config:", err);
            }
        }

        // Save Rephrase Configuration
        async function saveRephraseConfig() {
            const toggle = document.getElementById('rephrase-toggle');
            const providerSelect = document.getElementById('rephrase-provider');
            const modelInput = document.getElementById('rephrase-model');
            const settingsDiv = document.getElementById('rephrase-settings');
            const thresholdInput = document.getElementById('cache-threshold');

            settingsDiv.style.display = toggle.checked ? 'flex' : 'none';

            try {
                const response = await fetch('/api/rephrase/config', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        enabled: toggle.checked,
                        provider: providerSelect.value,
                        model: modelInput.value,
                        threshold: parseFloat(thresholdInput.value)
                    })
                });
                if (!response.ok) throw new Error("Save rephrase config failed");
                showToast("Rephrase settings updated and synced to gateway!", '🔄');
            } catch (err) {
                showToast("Failed to save rephrase settings", '❌');
            }
        }

        // Fetch local Ollama models list
        async function fetchOllamaModels() {
            try {
                const response = await fetch(`/api/ollama/models?_=${Date.now()}`);
                const tbody = document.getElementById('ollama-models-table-body');
                if (!response.ok) {
                    tbody.innerHTML = `<tr><td colspan="3" style="text-align: center; color: var(--accent-danger);">Ollama container offline</td></tr>`;
                    return;
                }
                const models = await response.json();
                if (models.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="3" style="text-align: center; color: var(--text-secondary);">No models pulled yet.</td></tr>`;
                } else {
                    tbody.innerHTML = models.map(m => `
                        <tr>
                            <td><span class="badge badge-model">${m.name}</span></td>
                            <td>${m.size}</td>
                            <td class="action-col">
                                <button type="button" onclick="deleteOllamaModel('${m.name}')" style="background: rgba(239, 68, 68, 0.1); color: var(--accent-danger); border: 1px solid rgba(239, 68, 68, 0.3); padding: 0.15rem 0.35rem; font-size: 0.65rem; box-shadow: none; cursor: pointer;">Delete</button>
                            </td>
                        </tr>
                    `).join('');
                }
            } catch (err) {
                console.error("Failed to fetch Ollama models:", err);
            }
        }

        // Pull model from registry
        async function pullOllamaModel() {
            const input = document.getElementById('ollama-model-input');
            const btn = document.getElementById('ollama-pull-btn');
            const modelName = input.value.trim();
            if (!modelName) {
                showToast("Please enter a model name", '⚠️');
                return;
            }
            
            btn.disabled = true;
            btn.innerText = "Pulling...";
            showToast(`Pulling model '${modelName}'... This might take a minute.`, '📥');
            
            try {
                const response = await fetch('/api/ollama/pull', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ model: modelName })
                });
                
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || "Failed to pull model");
                
                showToast(`Model '${modelName}' pulled successfully!`, '✅');
                input.value = '';
                await fetchOllamaModels();
            } catch (err) {
                showToast(err.message || "Failed to pull model", '❌');
            } finally {
                btn.disabled = false;
                btn.innerText = "Pull";
            }
        }

        // Delete model from Ollama
        window.deleteOllamaModel = async function(modelName) {
            if (!confirm(`Are you sure you want to delete model '${modelName}'?`)) return;
            try {
                const response = await fetch('/api/ollama/delete', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ model: modelName })
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.error || "Failed to delete model");
                showToast(`Model '${modelName}' deleted successfully`, '🗑️');
                await fetchOllamaModels();
            } catch (err) {
                showToast(err.message || "Failed to delete model", '❌');
            }
        }

        // Init hook
        window.addEventListener('DOMContentLoaded', () => {
            initCharts();
            fetchDashboardStats();
            fetchKeywords();
            fetchClassifierSamples();
            fetchRephraseConfig();
            fetchCompressorConfig();
            fetchOllamaModels();
            
            // Bind Ollama pull button click
            document.getElementById('ollama-pull-btn').addEventListener('click', pullOllamaModel);
            
            // Poll stats every 3 seconds for active UI updates
            setInterval(fetchDashboardStats, 3000);
            // Poll keywords every 10 seconds to sync
            setInterval(fetchKeywords, 10000);
            // Poll classifier samples every 30 seconds
            setInterval(fetchClassifierSamples, 30000);

            // Bind Cache clear click
            document.getElementById('clear-cache-btn').addEventListener('click', clearSemanticCache);

            // Bind Rephrase configuration inputs
            document.getElementById('rephrase-toggle').addEventListener('change', saveRephraseConfig);
            document.getElementById('rephrase-provider').addEventListener('change', saveRephraseConfig);
            document.getElementById('rephrase-model').addEventListener('change', saveRephraseConfig);
            document.getElementById('rephrase-model').addEventListener('blur', saveRephraseConfig);

            // Bind Cache Threshold slider
            const thresholdSlider = document.getElementById('cache-threshold');
            const thresholdVal = document.getElementById('cache-threshold-val');
            thresholdSlider.addEventListener('input', (e) => {
                const val = parseFloat(e.target.value).toFixed(2);
                thresholdVal.textContent = val;
                updateThresholdDescription(val);
            });
            thresholdSlider.addEventListener('change', saveRephraseConfig);

            // Bind Compressor configuration inputs
            document.getElementById('compressor-method').addEventListener('change', saveCompressorConfig);
            document.getElementById('compressor-caveman-intensity').addEventListener('change', saveCompressorConfig);

            const ratioSlider = document.getElementById('compressor-ratio');
            const ratioVal = document.getElementById('compressor-ratio-val');
            ratioSlider.addEventListener('input', (e) => {
                ratioVal.textContent = parseFloat(e.target.value).toFixed(2);
            });
            ratioSlider.addEventListener('change', saveCompressorConfig);

            // Bind Classifier Search input
            document.getElementById('classifier-search').addEventListener('input', (e) => {
                renderClassifierSamples(e.target.value);
            });

            // Bind Add Sample Form submit
            document.getElementById('add-classifier-sample-form').addEventListener('submit', (e) => {
                e.preventDefault();
                const id = document.getElementById('edit-sample-id').value;
                const intent = document.getElementById('new-sample-intent').value;
                const text = document.getElementById('new-sample-text').value;
                if (id) {
                    updateClassifierSample(id, intent, text);
                } else {
                    addClassifierSample(intent, text);
                }
            });

            // Bind Cancel edit click for classifier sample
            document.getElementById('sample-cancel-btn').addEventListener('click', resetSampleForm);

            // Bind Retrain button click
            document.getElementById('retrain-classifier-btn').addEventListener('click', retrainClassifier);

            // Bind Cancel edit click
            document.getElementById('rule-cancel-btn').addEventListener('click', () => {
                resetRuleForm();
                document.getElementById('route-rule-dialog').close();
            });

            // Bind Copy button click
            document.getElementById('copy-key-btn').addEventListener('click', () => {
                const tokenInput = document.getElementById('view-key-token-input');
                const val = tokenInput.value;
                if (val && val !== 'No plain key stored (legacy key)') {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(val).then(() => {
                            const copyBtn = document.getElementById('copy-key-btn');
                            copyBtn.innerText = 'Copied!';
                            copyBtn.style.background = 'linear-gradient(135deg, var(--accent-success), #059669)';
                            showToast('API Key copied to clipboard!', '📋');
                        }).catch(err => {
                            fallbackCopy(tokenInput);
                        });
                    } else {
                        fallbackCopy(tokenInput);
                    }
                } else {
                    showToast('Nothing to copy', '⚠️');
                }
            });

            function fallbackCopy(inputElement) {
                try {
                    inputElement.select();
                    inputElement.setSelectionRange(0, 99999);
                    document.execCommand('copy');
                    const copyBtn = document.getElementById('copy-key-btn');
                    copyBtn.innerText = 'Copied!';
                    copyBtn.style.background = 'linear-gradient(135deg, var(--accent-success), #059669)';
                    showToast('API Key copied to clipboard (fallback)!', '📋');
                } catch (err) {
                    console.error('Fallback copy failed: ', err);
                    showToast('Failed to copy to clipboard', '❌');
                }
            }

            // Bind Keyword Form submit
            document.getElementById('keyword-form').addEventListener('submit', (e) => {
                e.preventDefault();
                const kw = document.getElementById('new-keyword').value;
                addKeyword(kw);
                document.getElementById('add-keyword-dialog').close();
            });

            // Bind Edit Key Form submit
            document.getElementById('edit-key-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const id = document.getElementById('view-key-id').value;
                const totalBudget = parseFloat(document.getElementById('view-key-budget').value);
                const remainingBudget = parseFloat(document.getElementById('view-key-remaining-budget').value);
                const budgetType = document.getElementById('view-key-budget-type').value;
                
                // Gather selected checkboxes
                const selectedRules = [];
                document.querySelectorAll('input[name="edit_allowed_rules"]:checked').forEach(cb => {
                    selectedRules.push(parseInt(cb.value));
                });

                try {
                    const res = await fetch(`/api/keys/${id}`, {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ 
                            allowed_rules: selectedRules,
                            total_budget: totalBudget,
                            remaining_budget: remainingBudget,
                            budget_type: budgetType
                        })
                    });
                    if (!res.ok) throw new Error("Key update failed");
                    
                    document.getElementById('view-key-dialog').close();
                    showToast("API Key updated!", '🔑');
                    fetchDashboardStats();
                } catch (err) {
                    showToast("Failed to update API key", '❌');
                }
            });

            // Bind trigger buttons for modals
            document.getElementById('open-add-key-btn').addEventListener('click', () => {
                populateAllowedRoutingsCheckboxList();
                document.getElementById('add-key-dialog').showModal();
            });
            document.getElementById('open-add-rule-btn').addEventListener('click', () => {
                resetRuleForm();
                document.getElementById('route-rule-dialog').showModal();
            });
            document.getElementById('open-add-keyword-btn').addEventListener('click', () => {
                document.getElementById('add-keyword-dialog').showModal();
            });

            // Prefill rule defaults when provider changes
            document.getElementById('rule-provider').addEventListener('change', (e) => {
                const prov = e.target.value;
                const urlInput = document.getElementById('rule-url');
                const envInput = document.getElementById('rule-env');
                
                const defaultUrls = {
                    openai: "https://api.openai.com/v1/chat/completions",
                    anthropic: "https://api.anthropic.com/v1/messages",
                    google: "https://generativelanguage.googleapis.com/v1beta/openai/chat/completions",
                    ollama: "http://host.docker.internal:11434/v1/chat/completions"
                };
                const defaultEnvs = {
                    openai: "OPENAI_API_KEY",
                    anthropic: "ANTHROPIC_API_KEY",
                    google: "GEMINI_API_KEY",
                    ollama: "OLLAMA_API_KEY"
                };
                
                if (defaultUrls[prov]) {
                    urlInput.value = defaultUrls[prov];
                }
                if (defaultEnvs[prov]) {
                    envInput.value = defaultEnvs[prov];
                }
            });

            // Prefill fallback rule defaults when fallback provider changes
            document.getElementById('rule-fallback-provider').addEventListener('change', (e) => {
                const prov = e.target.value;
                const urlInput = document.getElementById('rule-fallback-url');
                const envInput = document.getElementById('rule-fallback-env');
                
                const defaultUrls = {
                    openai: "https://api.openai.com/v1/chat/completions",
                    anthropic: "https://api.anthropic.com/v1/messages",
                    google: "https://generativelanguage.googleapis.com/v1beta/openai/chat/completions",
                    ollama: "http://host.docker.internal:11434/v1/chat/completions"
                };
                const defaultEnvs = {
                    openai: "OPENAI_API_KEY",
                    anthropic: "ANTHROPIC_API_KEY",
                    google: "GEMINI_API_KEY",
                    ollama: "OLLAMA_API_KEY"
                };
                
                if (prov && defaultUrls[prov]) {
                    urlInput.value = defaultUrls[prov];
                } else if (!prov) {
                    urlInput.value = "";
                }
                if (prov && defaultEnvs[prov]) {
                    envInput.value = defaultEnvs[prov];
                } else if (!prov) {
                    envInput.value = "";
                }
            });

            // Click outside backdrop to close dialogs
            document.querySelectorAll('dialog').forEach(dialog => {
                dialog.addEventListener('click', (e) => {
                    const rect = dialog.getBoundingClientRect();
                    if (e.clientX < rect.left || e.clientX > rect.right ||
                        e.clientY < rect.top || e.clientY > rect.bottom) {
                        dialog.close();
                        if (dialog.id === 'route-rule-dialog') {
                            resetRuleForm();
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>
