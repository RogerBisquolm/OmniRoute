<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlertController extends Controller
{
    /**
     * Receive alert requests from the Gateway and dispatch webhooks.
     */
    public function trigger(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:failover,low_budget',
            // Failover fields
            'intent' => 'nullable|string',
            'primary_model' => 'nullable|string',
            'fallback_model' => 'nullable|string',
            'error' => 'nullable|string',
            // Low budget fields
            'api_key_name' => 'nullable|string',
            'remaining_budget' => 'nullable|numeric',
            'total_budget' => 'nullable|numeric',
        ]);

        $type = $request->input('type');
        $slackUrl = env('SLACK_WEBHOOK_URL');
        $discordUrl = env('DISCORD_WEBHOOK_URL');

        if (!$slackUrl && !$discordUrl) {
            Log::info("Alert received but no Slack or Discord webhooks configured.");
            return response()->json(['message' => 'Alert received, but no webhooks configured.'], 200);
        }

        // Format message text
        $messageText = "";
        if ($type === 'failover') {
            $intent = $request->input('intent');
            $primary = $request->input('primary_model');
            $fallback = $request->input('fallback_model');
            $error = $request->input('error');
            
            $messageText = "🚨 *OmniRoute Gateway Alert: Downstream Failover* 🚨\n" .
                           "• *Intent Category*: `{$intent}`\n" .
                           "• *Failed Model*: `{$primary}`\n" .
                           "• *Failover Target*: `{$fallback}`\n" .
                           "• *Error Trigger*: `{$error}`\n" .
                           "• *Status*: Request automatically routed to backup model.";
        } else if ($type === 'low_budget') {
            $keyName = $request->input('api_key_name');
            $remaining = number_format($request->input('remaining_budget'), 4);
            $total = number_format($request->input('total_budget'), 4);
            
            $messageText = "⚠️ *OmniRoute Gateway Alert: Budget Threshold Warning* ⚠️\n" .
                           "• *API Key*: `{$keyName}`\n" .
                           "• *Remaining Balance*: `\${$remaining}`\n" .
                           "• *Total Allocated Budget*: `\${$total}`\n" .
                           "• *Status*: Remaining budget has dropped below 10%. Please top up.";
        }

        // Send to Slack (supports Markdown blocks or text)
        if ($slackUrl) {
            try {
                Http::timeout(10)->post($slackUrl, [
                    'text' => $messageText
                ]);
                Log::info("Successfully sent Alert webhook to Slack.");
            } catch (\Exception $e) {
                Log::error("Failed to send Alert webhook to Slack: " . $e->getMessage());
            }
        }

        // Send to Discord
        if ($discordUrl) {
            try {
                // Map slack Markdown style (* for bold) to discord style (** for bold)
                $discordMessage = str_replace('*', '**', $messageText);
                Http::timeout(10)->post($discordUrl, [
                    'content' => $discordMessage
                ]);
                Log::info("Successfully sent Alert webhook to Discord.");
            } catch (\Exception $e) {
                Log::error("Failed to send Alert webhook to Discord: " . $e->getMessage());
            }
        }

        return response()->json(['message' => 'Alert processed and dispatched successfully.'], 200);
    }
}
