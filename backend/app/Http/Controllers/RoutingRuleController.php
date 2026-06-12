<?php

namespace App\Http\Controllers;

use App\Models\RoutingRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\Rule;

class RoutingRuleController extends Controller
{
    /**
     * Display a listing of routing rules.
     */
    public function index()
    {
        return response()->json(RoutingRule::onWriteConnection()->get());
    }

    /**
     * Store or update a routing rule, then broadcast the change via Redis Pub/Sub.
     */
    public function store(Request $request)
    {
        // Pre-validation cleanup: Prepend http:// to URLs if missing a scheme
        if ($request->has('url') && $request->input('url')) {
            $url = trim($request->input('url'));
            if (!preg_match('/^https?:\/\//i', $url)) {
                $request->merge(['url' => 'http://' . $url]);
            }
        }
        if ($request->has('fallback_url') && $request->input('fallback_url')) {
            $fallbackUrl = trim($request->input('fallback_url'));
            if (!preg_match('/^https?:\/\//i', $fallbackUrl)) {
                $request->merge(['fallback_url' => 'http://' . $fallbackUrl]);
            }
        }

        $request->validate([
            'intent' => [
                'required',
                'string',
                'max:100',
                Rule::unique('routing_rules')->where(function ($query) use ($request) {
                    return $query->where('model', $request->input('model'));
                })
            ],
            'provider' => 'required|string|max:50',
            'model' => 'required|string|max:100',
            'url' => ['required', 'string', 'regex:/^https?:\/\/\S+$/', 'max:255'],
            'api_key_env' => 'required|string|max:512',
            'fallback_provider' => 'nullable|string|max:50',
            'fallback_model' => 'nullable|string|max:100',
            'fallback_url' => ['nullable', 'string', 'regex:/^https?:\/\/\S+$/', 'max:255'],
            'fallback_api_key_env' => 'nullable|string|max:512',
            'weight' => 'nullable|integer|min:0',
        ], [
            'intent.unique' => 'A routing rule for this intent and model combination already exists.',
            'url.regex' => 'The primary API endpoint URL must be valid and start with http:// or https://',
            'fallback_url.regex' => 'The fallback API endpoint URL must start with http:// or https://',
        ]);

        $rule = RoutingRule::create([
            'intent' => $request->input('intent'),
            'provider' => $request->input('provider'),
            'model' => $request->input('model'),
            'url' => $request->input('url'),
            'api_key_env' => $request->input('api_key_env'),
            'fallback_provider' => $request->input('fallback_provider'),
            'fallback_model' => $request->input('fallback_model'),
            'fallback_url' => $request->input('fallback_url'),
            'fallback_api_key_env' => $request->input('fallback_api_key_env'),
            'weight' => (int) $request->input('weight', 100),
        ]);

        // Sync rules list to Redis config channel
        $this->syncRulesToGateway();

        return response()->json([
            'message' => 'Routing rule saved successfully and synced to gateway.',
            'rule' => $rule
        ], 200);
    }

    /**
     * Remove the specified routing rule and update gateway configurations.
     */
    public function destroy(string $id)
    {
        $rule = RoutingRule::findOrFail($id);
        $rule->delete();

        // Sync rules list to Redis config channel
        $this->syncRulesToGateway();

        return response()->json([
            'message' => 'Routing rule deleted successfully and synced to gateway.'
        ]);
    }

    /**
     * Update the specified routing rule and publish updates.
     */
    public function update(Request $request, string $id)
    {
        // Pre-validation cleanup: Prepend http:// to URLs if missing a scheme
        if ($request->has('url') && $request->input('url')) {
            $url = trim($request->input('url'));
            if (!preg_match('/^https?:\/\//i', $url)) {
                $request->merge(['url' => 'http://' . $url]);
            }
        }
        if ($request->has('fallback_url') && $request->input('fallback_url')) {
            $fallbackUrl = trim($request->input('fallback_url'));
            if (!preg_match('/^https?:\/\//i', $fallbackUrl)) {
                $request->merge(['fallback_url' => 'http://' . $fallbackUrl]);
            }
        }

        $request->validate([
            'intent' => [
                'required',
                'string',
                'max:100',
                Rule::unique('routing_rules')->where(function ($query) use ($request) {
                    return $query->where('model', $request->input('model'));
                })->ignore($id)
            ],
            'provider' => 'required|string|max:50',
            'model' => 'required|string|max:100',
            'url' => ['required', 'string', 'regex:/^https?:\/\/\S+$/', 'max:255'],
            'api_key_env' => 'required|string|max:512',
            'fallback_provider' => 'nullable|string|max:50',
            'fallback_model' => 'nullable|string|max:100',
            'fallback_url' => ['nullable', 'string', 'regex:/^https?:\/\/\S+$/', 'max:255'],
            'fallback_api_key_env' => 'nullable|string|max:512',
            'weight' => 'nullable|integer|min:0',
        ], [
            'intent.unique' => 'A routing rule for this intent and model combination already exists.',
            'url.regex' => 'The primary API endpoint URL must be valid and start with http:// or https://',
            'fallback_url.regex' => 'The fallback API endpoint URL must start with http:// or https://',
        ]);

        $rule = RoutingRule::findOrFail($id);
        $rule->update([
            'intent' => $request->input('intent'),
            'provider' => $request->input('provider'),
            'model' => $request->input('model'),
            'url' => $request->input('url'),
            'api_key_env' => $request->input('api_key_env'),
            'fallback_provider' => $request->input('fallback_provider'),
            'fallback_model' => $request->input('fallback_model'),
            'fallback_url' => $request->input('fallback_url'),
            'fallback_api_key_env' => $request->input('fallback_api_key_env'),
            'weight' => (int) $request->input('weight', 100),
        ]);

        // Sync rules list to Redis config channel
        $this->syncRulesToGateway();

        return response()->json([
            'message' => 'Routing rule updated successfully and synced to gateway.',
            'rule' => $rule
        ], 200);
    }

    /**
     * Query all routing rules, format them, and publish via Redis Pub/Sub.
     */
    protected function syncRulesToGateway()
    {
        $rules = RoutingRule::all()->groupBy('intent')->map(function ($group) {
            return $group->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'provider' => $item->provider,
                    'model' => $item->model,
                    'url' => $item->url,
                    'api_key_env' => $item->api_key_env,
                    'fallback_provider' => $item->fallback_provider,
                    'fallback_model' => $item->fallback_model,
                    'fallback_url' => $item->fallback_url,
                    'fallback_api_key_env' => $item->fallback_api_key_env,
                    'weight' => (int) $item->weight,
                ];
            })->values()->toArray();
        })->toArray();

        // Publish to gateway reload channel
        Redis::publish('gateway_config_updates', json_encode([
            'routing_rules' => $rules
        ]));
    }
}
