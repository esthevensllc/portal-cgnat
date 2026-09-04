<?php

namespace App\Http\Middleware;

use App\Services\ClickHouse\PortalAuthorizationRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PortalAuthenticate
{
    public function handle(Request $request, Closure $next, string $permission = 'cgnat.query'): Response
    {
        if (! (bool) config('ldap.enabled', true)) {
            $this->startTemporarySession($request);

            return $next($request);
        }

        $identity = $request->session()->get('portal_auth');

        if (is_array($identity) && ($identity['temporary'] ?? false) === true) {
            $request->session()->forget('portal_auth');
            $identity = null;
        }

        if (! is_array($identity) || blank($identity['username'] ?? null)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Sesión corporativa requerida.'], 401);
            }

            return redirect()->guest(route('portal.login'));
        }

        $permissions = app(PortalAuthorizationRepository::class)->permissions((string) $identity['username']);
        if (! in_array($permission, $permissions, true)) {
            $request->session()->forget('portal_auth');
            abort(403, 'Tu rol para este portal fue retirado o está inactivo.');
        }

        $request->session()->put('portal_auth.permissions', $permissions);

        return $next($request);
    }

    private function startTemporarySession(Request $request): void
    {
        $request->session()->put('portal_auth', [
            'username' => (string) config('ldap.temporary_username', 'acceso_temporal'),
            'display_name' => 'Acceso temporal',
            'email' => '',
            'groups' => [],
            'temporary' => true,
            'permissions' => (array) config('ldap.temporary_permissions', []),
        ]);
    }
}
