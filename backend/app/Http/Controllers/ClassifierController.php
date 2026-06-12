<?php

namespace App\Http\Controllers;

use App\Models\ClassifierSample;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class ClassifierController extends Controller
{
    /**
     * Display a list of all classifier training samples.
     */
    public function index()
    {
        return response()->json(ClassifierSample::orderBy('updated_at', 'desc')->get());
    }

    /**
     * Store a newly created classifier training sample.
     */
    public function store(Request $request)
    {
        $request->validate([
            'intent' => 'required|string|in:code,creative,support,general',
            'sample_text' => 'required|string|max:1000|min:1',
        ]);

        $sample = ClassifierSample::create([
            'intent' => $request->input('intent'),
            'sample_text' => $request->input('sample_text'),
        ]);

        return response()->json([
            'message' => 'Training sample added successfully.',
            'sample' => $sample
        ], 201);
    }

    /**
     * Update the specified classifier training sample.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'intent' => 'required|string|in:code,creative,support,general',
            'sample_text' => 'required|string|max:1000|min:1',
        ]);

        $sample = ClassifierSample::findOrFail($id);
        $sample->update([
            'intent' => $request->input('intent'),
            'sample_text' => $request->input('sample_text'),
        ]);

        return response()->json([
            'message' => 'Training sample updated successfully.',
            'sample' => $sample
        ]);
    }

    /**
     * Remove the specified classifier training sample.
     */
    public function destroy(string $id)
    {
        $sample = ClassifierSample::findOrFail($id);
        $sample->delete();

        return response()->json([
            'message' => 'Training sample deleted successfully.'
        ]);
    }

    /**
     * Trigger retraining of the FastText model on the gateway.
     */
    public function retrain()
    {
        // Broadcast retrain message via Redis Pub/Sub
        Redis::publish('gateway_config_updates', json_encode([
            'action' => 'train_fasttext'
        ]));

        return response()->json([
            'message' => 'Retraining request broadcasted to gateway.'
        ]);
    }
}
