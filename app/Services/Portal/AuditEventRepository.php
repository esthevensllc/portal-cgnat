<?php

namespace App\Services\Portal;

use App\Services\ClickHouse\PortalStoreService;
use Carbon\CarbonImmutable;

class AuditEventRepository
{
    public function __construct(private readonly PortalStoreService $store) {}

    /** @param array<string, string|null> $filters @return list<array<string, mixed>> */
    public function search(array $filters): array
    {
        $conditions = ['occurred_at >= {from:DateTime64(3)}', 'occurred_at < {to:DateTime64(3)}'];
        $timezone = (string) config('app.timezone', 'America/Lima');
        $parameters = [
            'param_from' => CarbonImmutable::parse($filters['from'].' 00:00:00', $timezone)->utc()->format('Y-m-d H:i:s.v'),
            'param_to' => CarbonImmutable::parse($filters['to'].' 00:00:00', $timezone)->addDay()->utc()->format('Y-m-d H:i:s.v'),
        ];

        foreach (['username', 'event_type', 'outcome', 'source_ip', 'request_id'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $conditions[] = match ($field) {
                    'username' => 'positionCaseInsensitive(username, {username:String}) > 0',
                    'request_id' => 'request_id = {request_id:UUID}',
                    default => "{$field} = {{$field}:String}",
                };
                $parameters['param_'.$field] = (string) $filters[$field];
            }
        }

        return $this->store->select(sprintf(<<<'SQL'
SELECT occurred_at, event_id, request_id, event_type, outcome, username,
       source_ip, http_method, route_name, resource_type, resource_id,
       elapsed_ms, total_rows, details_json
FROM portal_cgnat.audit_events
WHERE %s
ORDER BY occurred_at DESC, event_id DESC
LIMIT 500
SQL, implode("\n  AND ", $conditions)), $parameters);
    }
}
