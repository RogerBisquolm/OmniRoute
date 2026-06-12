<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PasswordAuthController extends Controller
{
    /**
     * Show the login form.
     */
    public function showLoginForm(Request $request)
    {
        $configuredPassword = config('app.gui_password');

        // If no password is configured, bypass and redirect to dashboard
        if (empty($configuredPassword)) {
            return redirect('/');
        }

        // If already authenticated, redirect to dashboard
        if ($request->session()->get('admin_logged_in')) {
            return redirect('/');
        }

        return view('login');
    }

    /**
     * Authenticate the login request.
     */
    public function login(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $configuredPassword = config('app.gui_password');
        $inputPassword = $request->input('password');

        if ($inputPassword === $configuredPassword) {
            $request->session()->put('admin_logged_in', true);
            // Regenerate session to prevent session fixation attacks
            $request->session()->regenerate();
            return redirect('/');
        }

        return redirect()->route('login')->withErrors([
            'password' => 'Invalid password. Please try again.',
        ])->withInput();
    }

    /**
     * Log the admin out.
     */
    public function logout(Request $request)
    {
        $request->session()->forget('admin_logged_in');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
