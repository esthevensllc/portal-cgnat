<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\LdapAuthenticationService;
use App\Services\ClickHouse\PortalAuthorizationRepository;
use App\Services\Portal\PortalAuditService;
use App\Services\Portal\PortalSessionRegistry;
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

        $pending = $request->session()->get('portal_pending_login');
        $conflict = is_array($pending) && (int) ($pending['expires_at'] ?? 0) > now()->timestamp;
        if (! $conflict) {
            $request->session()->forget('portal_pending_login');
        }

        return view('auth.login', ['sessionConflict' => $conflict]);
    }

    public function login(
        Request $request,
        LdapAuthenticationService $ldap,
        PortalAuthorizationRepository $authorization,
        PortalAuditService $audit,
        PortalSessionRegistry $sessions,
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
            $audit->record('auth.login', 'rate_limited', $credentials['username'], $request, [
                'retry_after_seconds' => $seconds,
            ]);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => "Demasiados intentos de inicio de sesión. Inténtalo nuevamente en {$seconds} segundos."]);
        }

        try {
            $authentication = $ldap->authenticate($credentials['username'], $credentials['password']);
        } catch (RuntimeException $exception) {
            report($exception);
            RateLimiter::hit($rateKey, $decaySeconds);
            $audit->record('auth.login', 'failure', $credentials['username'], $request, [
                'reason' => 'ldap_unavailable',
            ]);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'No fue posible comunicarse con el servicio LDAP corporativo. Inténtalo nuevamente.']);
        }

        $principal = $authentication['principal'];
        if ($principal === null) {
            RateLimiter::hit($rateKey, $decaySeconds);
            $audit->record('auth.login', 'failure', $credentials['username'], $request, [
                'reason' => $authentication['status'],
            ]);

            $message = match ($authentication['status']) {
                'group_denied' => 'No cuenta con permisos para el Portal',
                'user_not_found', 'invalid_credentials' => 'Usuario o contraseña corporativa no válidos.',
                default => 'No fue posible iniciar sesión.',
            };

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => $message]);
        }

        RateLimiter::clear($rateKey);

        try {
            $permissions = $authorization->permissions($principal['username']);
        } catch (RuntimeException $exception) {
            report($exception);
            $audit->record('auth.login', 'failure', $principal['username'], $request, [
                'reason' => 'authorization_store_unavailable',
            ]);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'No fue posible validar los permisos del portal. Inténtalo nuevamente.']);
        }

        if (! in_array('cgnat.query', $permissions, true)) {
            $audit->record('auth.login', 'denied', $principal['username'], $request, [
                'reason' => 'portal_role_missing',
                'required_permission' => 'cgnat.query',
            ]);

            return back()
                ->withInput($request->only('username'))
                ->withErrors(['username' => 'No tiene un rol activo para este portal.']);
        }

        try {
            if (! $sessions->claim($request, $principal['username'])) {
                $request->session()->put('portal_pending_login', [
                    'principal' => $principal,
                    'permissions' => $permissions,
                    'expires_at' => now()->addMinutes(5)->timestamp,
                ]);
                $audit->record('auth.login', 'pending', $principal['username'], $request, ['reason' => 'active_session']);

                return redirect()->route('portal.login')->with('session_conflict', true);
            }
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput($request->only('username'))
                ->withErrors(['username' => 'No fue posible verificar las sesiones activas. Inténtalo nuevamente.']);
        }

        $request->session()->forget('portal_pending_login');
        $request->session()->put('portal_auth', [
            ...$principal,
            'permissions' => $permissions,
            'authenticated_at' => now()->toIso8601String(),
        ]);

        $audit->record('auth.login', 'success', $principal['username'], $request, [
            'permissions' => $permissions,
            'ldap_group_required' => filled(config('ldap.allowed_group')),
        ]);

        return redirect()->intended(route('queries.index'));
    }

    public function replace(Request $request, PortalSessionRegistry $sessions, PortalAuditService $audit): RedirectResponse
    {
        $pending = $request->session()->get('portal_pending_login');
        if (! is_array($pending) || (int) ($pending['expires_at'] ?? 0) <= now()->timestamp) {
            $request->session()->forget('portal_pending_login');

            return redirect()->route('portal.login')->withErrors(['username' => 'La confirmación venció. Ingresa nuevamente.']);
        }

        $principal = $pending['principal'];
        try {
            $sessions->claim($request, (string) $principal['username'], true);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('portal.login')->withErrors(['username' => 'No fue posible cerrar la otra sesión. Inténtalo nuevamente.']);
        }

        $request->session()->forget('portal_pending_login');
        $request->session()->put('portal_auth', [
            ...$principal,
            'permissions' => $pending['permissions'],
            'authenticated_at' => now()->toIso8601String(),
        ]);
        $audit->record('auth.other_sessions_closed', 'success', $principal['username'], $request);

        return redirect()->route('queries.index');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget('portal_pending_login');

        return redirect()->route('portal.login');
    }

    private function rateLimitKey(Request $request, string $username): string
    {
        return 'portal-login:'.Str::lower(trim($username)).'|'.$request->ip();
    }
}
