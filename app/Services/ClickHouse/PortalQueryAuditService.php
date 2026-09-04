<?php

namespace App\Services\ClickHouse;

use App\Domain\Cgnat\CgnatSearch;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PortalQueryAuditService
{
    /** @param array{rows: list<array<string, mixed>>, partial: bool, elapsed_ms: int} $result */
    public function record(string $username, CgnatSearch $search, array $result): void
    {
        try {
            $url = (string) config('portal_store.url');
            $username = (string) config('portal_store.username');
            $password = (string) config('portal_store.password');
            $database = (string) config('portal_store.database');

            if (blank($url)) {
                return;
            }

            $row = [
                'occurred_at' => now()->format('Y-m-d H:i:s.v'),
                'request_id' => (string) Str::uuid(),
                'username' => $username,
                'selected_nodes' => $search->nodes,
                'from_time' => $search->from->format('Y-m-d H:i:s'),
                'to_time' => $search->to->format('Y-m-d H:i:s'),
                'router_ip' => $search->routerIp,
                'private_ip' => $search->privateIp,
                'private_port' => $search->privatePort,
                'public_ip' => $search->publicIp,
                'public_port_from' => $search->publicPortFrom,
                'public_port_to' => $search->publicPortTo,
                'destination_ip' => $search->destinationIp,
                'destination_port' => $search->destinationPort,
                'rows' => count($result['rows']),
                'elapsed_ms' => $result['elapsed_ms'],
                'status' => $result['partial'] ? 'partial' : 'success',
                'error' => '',
            ];

            Http::withBasicAuth((string) $node['username'], (string) $node['password'])
                ->connectTimeout((int) config('portal_store.connect_timeout'))
                ->timeout((int) config('portal_store.query_timeout'))
                ->withOptions(['verify' => (bool) $node['verify_tls'], 'query' => ['database' => config('portal_store.database')]])
                ->withBody(json_encode($row, JSON_THROW_ON_ERROR)."\n", 'application/x-ndjson')
                ->post(rtrim((string) $node['url'], '/').'/?query='.rawurlencode('INSERT INTO portal_cgnat.query_audit FORMAT JSONEachRow'));
        } catch (Throwable $exception) {
            report($exception); // La auditoría no debe impedir una consulta operativa.
        }
    }
}
