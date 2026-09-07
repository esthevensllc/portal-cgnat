<?php

namespace App\Services\ClickHouse;

use App\Domain\Cgnat\CgnatSearch;
use App\Services\Portal\PortalAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class PortalQueryAuditService
{
    public function __construct(
        private readonly PortalStoreService $store,
        private readonly PortalAuditService $audit,
    ) {}

    /** @param array<string, mixed> $result */
    public function recordSuccess(string $username, CgnatSearch $search, array $result, Request $request): void
    {
        $rows = (int) ($result['total_rows'] ?? count($result['rows'] ?? []));
        $elapsedMs = (int) ($result['elapsed_ms'] ?? 0);
        $status = ($result['large_result'] ?? false) ? 'export_required' : 'success';

        $this->record(
            $username,
            $search,
            $rows,
            $elapsedMs,
            $status,
            '',
            $request,
        );
        $this->audit->record(
            'query.completed',
            'success',
            $username,
            $request,
            [...$search->toArray(), 'status' => $status],
            elapsedMs: $elapsedMs,
            totalRows: $rows,
        );
    }

    public function recordFailure(string $username, CgnatSearch $search, string $error, Request $request): void
    {
        $this->record($username, $search, 0, 0, 'failure', mb_substr($error, 0, 1000), $request);
        $this->audit->record('query.failed', 'failure', $username, $request, [
            ...$search->toArray(),
            'reason' => mb_substr($error, 0, 1000),
        ]);
    }

    private function record(
        string $username,
        CgnatSearch $search,
        int $rows,
        int $elapsedMs,
        string $status,
        string $error,
        Request $request,
    ): void {
        try {
            $requestId = $request->attributes->get('portal_request_id');
            if (! is_string($requestId) || ! Str::isUuid($requestId)) {
                $requestId = (string) Str::uuid();
            }

            $this->store->insertJson('query_audit', [
                'occurred_at' => now()->format('Y-m-d H:i:s.v'),
                'request_id' => $requestId,
                'username' => mb_strtolower(trim($username)),
                'selected_nodes' => $search->nodes,
                'from_time' => $search->from->format('Y-m-d H:i:s'),
                'to_time' => $search->to->format('Y-m-d H:i:s'),
                'router_ip' => $search->routerIp !== '' ? $search->routerIp : null,
                'private_ip' => $search->privateIp !== '' ? $search->privateIp : null,
                'private_port' => $search->privatePort,
                'public_ip' => $search->publicIp,
                'public_port_from' => $search->publicPortFrom,
                'public_port_to' => $search->publicPortTo,
                'destination_ip' => $search->destinationIp,
                'destination_port' => $search->destinationPort,
                'rows' => max(0, $rows),
                'elapsed_ms' => max(0, $elapsedMs),
                'status' => $status,
                'error' => $error,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
