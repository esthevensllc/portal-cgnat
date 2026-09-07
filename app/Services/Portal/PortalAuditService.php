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

            $this->store->insertJson('audit_events', [
                'event_id' => (string) Str::uuid(),
                'occurred_at' => now()->utc()->format('Y-m-d H:i:s.v'),
                'request_id' => $requestId,
                'event_type' => $eventType,
                'outcome' => $outcome,
                'username' => mb_strtolower(trim((string) $username)),
                'auth_provider' => config('ldap.enabled', true) ? 'ldap' : 'temporary',
                'source_ip' => $request?->ip() ?? '',
                'http_method' => $request?->method() ?? '',
                'route_name' => $request?->route()?->getName() ?? '',
                'resource_type' => $resourceType ?? '',
                'resource_id' => $resourceId ?? '',
                'elapsed_ms' => max(0, $elapsedMs ?? 0),
                'total_rows' => max(0, $totalRows ?? 0),
                'details_json' => json_encode($this->sanitize($details), JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $exception) {
            report($exception); // Una falla de auditoría no debe exponer datos ni bloquear la operación.
        }
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
