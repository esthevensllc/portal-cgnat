<?php

namespace App\Services\Portal;

use App\Services\ClickHouse\PortalStoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class PortalAuditService
{
    public function __construct(private readonly PortalStoreService $store) {}

    /** @param array<string, mixed> $details */
    public function record(
        string $eventType,
        string $outcome,
        ?string $username = null,
        ?Request $request = null,
        array $details = [],
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?int $elapsedMs = null,
        ?int $totalRows = null,
    ): void {
        try {
            $requestId = $request?->attributes->get('portal_request_id');
            if (! is_string($requestId) || ! Str::isUuid($requestId)) {
                $requestId = (string) Str::uuid();
            }

            $hasTarget = filled($details['destination_ip'] ?? null);

            $this->store->insertJson('audit_events', [
                'event_id' => (string) Str::uuid(),
                'occurred_at' => now()->utc()->format('Y-m-d H:i:s.v'),
                'request_id' => $requestId,
                'event_type' => $eventType,
                'outcome' => match ($outcome) {
                    'success' => 'Correcto',
                    'pending' => 'Pendiente',
                    default => 'Fallido',
                },
                'username' => mb_strtolower(trim((string) $username)),
                'user_description' => $request !== null && $request->hasSession()
                    ? (string) $request->session()->get('portal_auth.display_name', '') : '',
                'auth_provider' => config('ldap.enabled', true) ? 'ldap' : 'temporary',
                'source_ip' => $request?->ip() ?? '',
                'source_hostname' => (string) ($request?->server('REMOTE_HOST') ?? ''),
                'destination_ip' => $hasTarget ? (string) $details['destination_ip'] : (string) ($request?->server('SERVER_ADDR') ?? ''),
                'destination_hostname' => $hasTarget ? (string) ($details['destination_hostname'] ?? '') : ($request?->getHost() ?? ''),
                'os_username' => $this->osUsername(),
                'http_method' => $request?->method() ?? '',
                'route_name' => $request?->route()?->getName() ?? '',
                'resource_type' => $resourceType ?? '',
                'resource_id' => $resourceId ?? '',
                'elapsed_ms' => max(0, $elapsedMs ?? 0),
                'total_rows' => max(0, $totalRows ?? 0),
                'details_json' => json_encode($this->sanitize([
                    ...$details,
                    ...($resourceType !== null ? ['resource_type' => $resourceType] : []),
                    ...($resourceId !== null ? ['resource_id' => $resourceId] : []),
                    ...($elapsedMs !== null ? ['elapsed_ms' => $elapsedMs] : []),
                    ...($totalRows !== null ? ['total_rows' => $totalRows] : []),
                ]), JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $exception) {
            report($exception); // Una falla de auditoría no debe exponer datos ni bloquear la operación.
        }
    }

    private function osUsername(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            return (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');
        }

        return (string) (getenv('USERNAME') ?: getenv('USER') ?: '');
    }

    /** @param array<string, mixed> $details @return array<string, mixed> */
    private function sanitize(array $details): array
    {
        return collect($details)
            ->reject(fn (mixed $value, string|int $key): bool => $this->isSecretKey($key))
            ->map(fn (mixed $value): mixed => $this->sanitizeValue($value))
            ->all();
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? mb_substr($value, 0, 2000) : $value;
        }

        return collect($value)
            ->reject(fn (mixed $nested, string|int $key): bool => $this->isSecretKey($key))
            ->map(fn (mixed $nested): mixed => $this->sanitizeValue($nested))
            ->all();
    }

    private function isSecretKey(string|int $key): bool
    {
        return in_array(mb_strtolower((string) $key), [
            'password',
            'token',
            'cookie',
            'session',
            'authorization',
            'bind_password',
        ], true);
    }
}
