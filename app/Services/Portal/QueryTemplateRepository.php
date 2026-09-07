<?php

namespace App\Services\Portal;

use App\Services\ClickHouse\PortalStoreService;
use Illuminate\Support\Str;

class QueryTemplateRepository
{
    public function __construct(private readonly PortalStoreService $store) {}

    /** @param array<string, mixed> $filters */
    public function create(string $username, string $name, array $filters): string
    {
        $id = (string) Str::uuid();
        $now = now()->format('Y-m-d H:i:s.v');
        $this->store->insertJson('query_templates', [
            'id' => $id,
            'username' => mb_strtolower($username),
            'name' => $name,
            'filters_json' => json_encode($this->sanitizeFilters($filters), JSON_THROW_ON_ERROR),
            'is_active' => 1,
            'created_at' => $now,
            'version' => $now,
        ]);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function forUser(string $username): array
    {
        return collect($this->store->select(<<<'SQL'
SELECT
    id,
    argMax(name, version) AS name,
    argMax(filters_json, version) AS filters_json,
    max(created_at) AS created_at
FROM portal_cgnat.query_templates
WHERE lower(username) = lower({username:String})
GROUP BY id
HAVING argMax(is_active, version) = 1
ORDER BY created_at DESC
LIMIT 100
SQL, ['param_username' => $username]))
            ->map(function (array $template): array {
                $filters = json_decode((string) ($template['filters_json'] ?? ''), true);
                $template['filters_json'] = json_encode(
                    $this->sanitizeFilters(is_array($filters) ? $filters : []),
                    JSON_THROW_ON_ERROR,
                );

                return $template;
            })
            ->all();
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function sanitizeFilters(array $filters): array
    {
        return array_diff_key($filters, array_flip([
            'event_id',
            'event_time',
            'event_type',
            'protocol',
            'header',
            'limit',
            'cursor',
            'known_total',
        ]));
    }
}
