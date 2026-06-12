<?php

use App\Http\Controllers\AlertController;
use Illuminate\Support\Facades\Route;

// Gateway Alerting Endpoint (accessed stateless by proxy without session)
Route::post('/alerts', [AlertController::class, 'trigger']);
