<?php

namespace App\Http\Middleware;

use App\Services\ClickHouse\PortalAuthorizationRepository;
use App\Services\Portal\PortalAuditService;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class PortalAuthenticate
{
    public function handle(Request $request, Closure $next, string $permission = 'cgnat.query'): Response
    {
        if (! (bool) config('ldap.enabled', true)) {
            $this->startTemporarySession($request);

            if (! in_array($permission, (array) config('ldap.temporary_permissions', []), true)) {
                abort(403, 'El acceso temporal no incluye este permiso.');
            }

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

        try {
            $permissions = app(PortalAuthorizationRepository::class)->permissions((string) $identity['username']);
        } catch (RuntimeException $exception) {
            report($exception);
            app(PortalAuditService::class)->record(
                'authorization.check',
                'failure',
                (string) $identity['username'],
                $request,
                ['reason' => 'authorization_store_unavailable'],
            );
            abort(503, 'No fue posible validar los permisos del portal.');
        }

        if (! in_array($permission, $permissions, true)) {
            app(PortalAuditService::class)->record(
                'authorization.denied',
                'denied',
                (string) $identity['username'],
                $request,
                ['required_permission' => $permission],
            );
            if ($permission === 'cgnat.query') {
                $request->session()->forget('portal_auth');
            }
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
