<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use RuntimeException;

class SiteAccessController extends Controller
{
    public function show(): View
    {
        return view('access.login');
    }

    public function store(Request $request): RedirectResponse|Response
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'Введіть пароль.',
        ]);

        $hash = (string) config('services.site_access.password_hash', '');

        try {
            $accessGranted = $hash !== '' && Hash::check($validated['password'], $hash);
        } catch (RuntimeException) {
            $accessGranted = false;
        }

        if ($accessGranted) {
            $request->session()->regenerate();
            $request->session()->put('site_access_granted', true);

            return redirect()->intended(route('monitoring.sites'));
        }

        return response()->view('access.denied', [], 403);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('site_access_granted');
        $request->session()->regenerateToken();

        return redirect()->route('access.show');
    }
}
