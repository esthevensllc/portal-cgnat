<?php

namespace App\Services\Portal;

use App\Domain\Cgnat\CgnatSearch;
use App\Services\ClickHouse\PortalStoreService;
use Illuminate\Support\Str;

class ExportTaskRepository
{
    public function __construct(private readonly PortalStoreService $store) {}

    public function create(string $username, CgnatSearch $search, int $totalRows): string
    {
        $id = (string) Str::uuid();
        $now = now()->format('Y-m-d H:i:s.v');

        $this->store->insertJson('export_tasks', [
            'id' => $id,
            'username' => mb_strtolower($username),
            'state' => 'queued',
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'progress' => 0,
            'filename' => '',
            'filters_json' => json_encode($search->toArray(), JSON_THROW_ON_ERROR),
            'error' => '',
            'created_at' => $now,
            'updated_at' => $now,
            'version' => $now,
        ]);

        return $id;
    }

    public function update(string $id, string $username, string $state, int $totalRows, int $processedRows, string $filename = '', string $error = ''): void
    {
        $now = now()->format('Y-m-d H:i:s.v');
        $this->store->insertJson('export_tasks', [
            'id' => $id,
            'username' => mb_strtolower($username),
            'state' => $state,
            'total_rows' => $totalRows,
            'processed_rows' => $processedRows,
            'progress' => $totalRows > 0 ? min(100, (int) floor(($processedRows / $totalRows) * 100)) : 0,
            'filename' => $filename,
            'filters_json' => '',
            'error' => $error,
            'created_at' => $now,
            'updated_at' => $now,
            'version' => $now,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function forUser(string $username): array
    {
        return $this->store->select(<<<'SQL'
SELECT
    id,
    argMax(state, version) AS state,
    argMax(total_rows, version) AS total_rows,
    argMax(processed_rows, version) AS processed_rows,
    argMax(progress, version) AS progress,
    argMax(filename, version) AS filename,
    argMax(error, version) AS error,
    min(created_at) AS created_at,
    max(updated_at) AS updated_at
FROM portal_cgnat.export_tasks
WHERE lower(username) = lower({username:String})
GROUP BY id
ORDER BY updated_at DESC
LIMIT 100
SQL, ['param_username' => $username]);
    }

    /** @return array<string, mixed>|null */
    public function findForUser(string $id, string $username): ?array
    {
        return $this->store->select(<<<'SQL'
SELECT
    id,
    argMax(state, version) AS state,
    argMax(filename, version) AS filename,
    argMax(error, version) AS error
FROM portal_cgnat.export_tasks
WHERE id = {id:UUID} AND lower(username) = lower({username:String})
GROUP BY id
LIMIT 1
SQL, ['param_id' => $id, 'param_username' => $username])[0] ?? null;
    }
}
