<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\LdapAuthenticationService;
use App\Services\ClickHouse\PortalAuthorizationRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class PortalLoginController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! (bool) config('ldap.enabled', true)) {
            return redirect()->route('queries.index');
        }

        if (is_array($request->session()->get('portal_auth'))) {
            return redirect()->route('queries.index');
        }

        return view('auth.login');
    }

    public function login(
        Request $request,
        LdapAuthenticationService $ldap,
        PortalAuthorizationRepository $authorization,
    ): RedirectResponse {
        if (! (bool) config('ldap.enabled', true)) {
            return redirect()->route('queries.index');
        }

        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $rateKey = $this->rateLimitKey($request, $credentials['username']);
        $maxAttempts = max(1, (int) config('ldap.max_attempts', 5));
        $decaySeconds = max(60, (int) config('ldap.decay_seconds', 300));

        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => "Demasiados intentos de inicio de sesión. Inténtalo nuevamente en {$seconds} segundos."]);
        }

        try {
            $principal = $ldap->authenticate($credentials['username'], $credentials['password']);
        } catch (RuntimeException $exception) {
            report($exception);
            RateLimiter::hit($rateKey, $decaySeconds);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'No fue posible comunicarse con el servicio LDAP corporativo. Inténtalo nuevamente.']);
        }

        if ($principal === null) {
            RateLimiter::hit($rateKey, $decaySeconds);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'Usuario o contraseña corporativa no válidos.']);
        }

        RateLimiter::clear($rateKey);

        $permissions = $authorization->permissions($principal['username']);
        if (! in_array('cgnat.query', $permissions, true)) {
            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'Tu cuenta LDAP es válida, pero no tiene un rol activo para este portal.']);
        }

        $request->session()->regenerate();
        $request->session()->put('portal_auth', [
            ...$principal,
            'permissions' => $permissions,
            'authenticated_at' => now()->toIso8601String(),
        ]);

        return redirect()->intended(route('queries.index'));
    }

    private function rateLimitKey(Request $request, string $username): string
    {
        return 'portal-login:'.Str::lower(trim($username)).'|'.$request->ip();
    }
}
