<?php

use App\Http\Controllers\PasswordAuthController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\RoutingRuleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ClassifierController;
use Illuminate\Support\Facades\Route;

// Authentication Routes
Route::get('/login', [PasswordAuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [PasswordAuthController::class, 'login']);
Route::post('/logout', [PasswordAuthController::class, 'logout'])->name('logout');

// Protected Routes Group
Route::middleware(['gui.auth'])->group(function () {
    
    // Main Admin Dashboard
    Route::get('/', function () {
        return view('dashboard');
    });

    // Control Center Management API Endpoints
    Route::prefix('api')->group(function () {
        // API Keys Management
        Route::get('/keys', [ApiKeyController::class, 'index']);
        Route::post('/keys', [ApiKeyController::class, 'store']);
        Route::put('/keys/{id}', [ApiKeyController::class, 'update']);
        Route::delete('/keys/{id}', [ApiKeyController::class, 'destroy']);

        // Routing Rules Management
        Route::get('/rules', [RoutingRuleController::class, 'index']);
        Route::post('/rules', [RoutingRuleController::class, 'store']);
        Route::put('/rules/{id}', [RoutingRuleController::class, 'update']);
        Route::delete('/rules/{id}', [RoutingRuleController::class, 'destroy']);

        // Telemetry & Metrics Analytics
        Route::get('/dashboard/metrics', [DashboardController::class, 'metrics']);

        // Guardrails & Cache management
        Route::get('/guardrails/keywords', [DashboardController::class, 'getKeywords']);
        Route::post('/guardrails/keywords', [DashboardController::class, 'addKeyword']);
        Route::delete('/guardrails/keywords', [DashboardController::class, 'deleteKeyword']);
        Route::post('/cache/clear', [DashboardController::class, 'clearCache']);
        Route::get('/rephrase/config', [DashboardController::class, 'getRephraseConfig']);
        Route::post('/rephrase/config', [DashboardController::class, 'updateRephraseConfig']);

        // Ollama Control API
        Route::get('/ollama/models', [DashboardController::class, 'getOllamaModels']);
        Route::post('/ollama/pull', [DashboardController::class, 'pullOllamaModel']);
        Route::delete('/ollama/delete', [DashboardController::class, 'deleteOllamaModel']);

        // Intent Classifier Management
        Route::get('/classifier/samples', [ClassifierController::class, 'index']);
        Route::post('/classifier/samples', [ClassifierController::class, 'store']);
        Route::put('/classifier/samples/{id}', [ClassifierController::class, 'update']);
        Route::delete('/classifier/samples/{id}', [ClassifierController::class, 'destroy']);
        Route::post('/classifier/retrain', [ClassifierController::class, 'retrain']);
    });
});

