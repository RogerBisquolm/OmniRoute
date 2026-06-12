<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckGuiPassword
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredPassword = config('app.gui_password');
        
        // If password is not set (e.g. empty or null), bypass auth
        if (empty($configuredPassword)) {
            return $next($request);
        }

        // Check if admin is logged in
        if (!$request->session()->get('admin_logged_in')) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthorized. Please login.'], 401);
            }
            return redirect()->route('login');
        }

        $response = $next($request);
        
        // Prevent browser caching for all admin/API responses (crucial for Safari/Chrome)
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
