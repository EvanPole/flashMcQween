<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function showRegister(): RedirectResponse
    {
        return redirect()->route('home');
    }

    public function register(Request $request): RedirectResponse|JsonResponse
    {
        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create($attributes);

        Auth::login($user);
        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => route('home', [], false),
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);
        }

        return redirect()->route('home');
    }

    public function showLogin(): RedirectResponse
    {
        return redirect()->route('home');
    }

    public function login(Request $request): RedirectResponse|JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Ces identifiants ne correspondent a aucun compte.',
                    'errors' => [
                        'email' => ['Ces identifiants ne correspondent a aucun compte.'],
                    ],
                ], 422);
            }

            return back()
                ->withErrors(['email' => 'Ces identifiants ne correspondent a aucun compte.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        if ($request->expectsJson()) {
            $user = $request->user();

            return response()->json([
                'redirect' => redirect()->intended(route('home'))->getTargetUrl(),
                'user' => [
                    'name' => $user?->name,
                    'email' => $user?->email,
                ],
            ]);
        }

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse|JsonResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => route('home', [], false),
            ]);
        }

        return redirect()->route('login');
    }
}
