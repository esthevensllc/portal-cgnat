<?php

namespace App\Services\ClickHouse;

use App\Domain\Cgnat\CgnatSearch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ClickHouseQueryService
{
    private const PAGE_SIZE = 20;

    /** @return array{rows: list<array<string, mixed>>, nodes: array<string, array<string, mixed>>, partial: bool, elapsed_ms: int, has_more: bool, next_cursor: ?string, page_size: int} */
    public function search(CgnatSearch $search): array
    {
        $nodes = $this->nodes($search);
        $startedAt = hrtime(true);
        [$sql, $parameters] = $this->compileRows($search);
        $responses = $this->pool($nodes, $sql, $parameters, $search);
        $rows = [];
        $nodeStatus = [];

        foreach ($nodes as $name => $node) {
            $response = $responses[$name] ?? null;
            if ($response instanceof ConnectionException) {
                $nodeStatus[$name] = $this->connectionFailure($name, $node, $response);
                continue;
            }
            if (! $response instanceof Response || ! $response->successful()) {
                $nodeStatus[$name] = $this->responseFailure($name, $node, $response);
                continue;
            }

            $decoded = collect(preg_split('/\R/', trim($response->body())))
                ->filter()
                ->map(fn (string $line): ?array => json_decode($line, true, flags: JSON_INVALID_UTF8_SUBSTITUTE))
                ->filter(fn (mixed $row): bool => is_array($row))
                ->map(fn (array $row): array => ['source_node' => $node['label'], ...$row])
                ->values()
                ->all();
            array_push($rows, ...$decoded);
            $nodeStatus[$name] = ['ok' => true, 'label' => (string) $node['label'], 'rows' => count($decoded)];
        }

        usort($rows, fn (array $left, array $right): int => $this->rowSortKey($right) <=> $this->rowSortKey($left));

        $hasMore = count($rows) > self::PAGE_SIZE;
        $visibleRows = array_slice($rows, 0, self::PAGE_SIZE);
        $nextCursor = $hasMore && $visibleRows !== []
            ? $this->encodeCursor($visibleRows[array_key_last($visibleRows)])
            : null;

        $visibleRows = array_map(static function (array $row): array {
            unset($row['source_node_key']);

            return $row;
        }, $visibleRows);

        return [
            'rows' => $visibleRows,
            'nodes' => $nodeStatus,
            'partial' => collect($nodeStatus)->contains(fn (array $status): bool => ! $status['ok']),
            'elapsed_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'page_size' => self::PAGE_SIZE,
        ];
    }

    /** @return array{total: int, per_node: array<string, int>, nodes: array<string, array<string, mixed>>, partial: bool, elapsed_ms: int} */
    public function count(CgnatSearch $search): array
    {
        $nodes = $this->nodes($search);
        $startedAt = hrtime(true);
        [$sql, $parameters] = $this->compileCount($search);
        $responses = $this->pool($nodes, $sql, $parameters, $search);
        $total = 0;
        $perNode = [];
        $nodeStatus = [];

        foreach ($nodes as $name => $node) {
            $response = $responses[$name] ?? null;
            if ($response instanceof ConnectionException) {
                $nodeStatus[$name] = $this->connectionFailure($name, $node, $response);
                continue;
            }
            if (! $response instanceof Response || ! $response->successful()) {
                $nodeStatus[$name] = $this->responseFailure($name, $node, $response);
                continue;
            }

            $row = json_decode(trim($response->body()), true);
            $count = is_array($row) ? (int) ($row['total'] ?? 0) : 0;
            $total += $count;
            $perNode[$name] = $count;
            $nodeStatus[$name] = ['ok' => true, 'label' => (string) $node['label'], 'rows' => $count];
        }

        return [
            'total' => $total,
            'per_node' => $perNode,
            'nodes' => $nodeStatus,
            'partial' => collect($nodeStatus)->contains(fn (array $status): bool => ! $status['ok']),
            'elapsed_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ];
    }

    /** @param array<string, array<string, mixed>> $nodeStatuses */
    public function failureMessage(array $nodeStatuses): string
    {
        $failed = array_filter(
            $nodeStatuses,
            static fn (array $status): bool => ! (bool) ($status['ok'] ?? false),
        );

        if ($failed === []) {
            return 'No fue posible completar la consulta en ClickHouse.';
        }

        $types = array_values(array_unique(array_map(
            static fn (array $status): string => (string) ($status['type'] ?? 'query_error'),
            $failed,
        )));

        $labels = array_values(array_unique(array_map(
            static fn (array $status): string => (string) ($status['label'] ?? 'ClickHouse'),
            $failed,
        )));

        if ($types === ['missing_table']) {
            $dates = [];
            foreach ($failed as $status) {
                foreach ((array) ($status['dates'] ?? []) as $date) {
                    if (is_string($date) && $date !== '') {
                        $dates[] = $date;
                    }
                }
            }
            $dates = array_values(array_unique($dates));

            if (count($dates) === 1) {
                return sprintf(
                    'No existe la tabla de datos correspondiente a la fecha %s en los nodos: %s.',
                    $dates[0],
                    implode(', ', $labels),
                );
            }

            if ($dates !== []) {
                return sprintf(
                    'No existen las tablas de datos correspondientes a las fechas %s en los nodos: %s.',
                    implode(', ', $dates),
                    implode(', ', $labels),
                );
            }

            return sprintf(
                'No existen una o más tablas de datos para el rango de fechas consultado en los nodos: %s.',
                implode(', ', $labels),
            );
        }

        if ($types === ['connection']) {
            return sprintf(
                'No fue posible establecer conexión con los nodos ClickHouse: %s.',
                implode(', ', $labels),
            );
        }

        if ($types === ['timeout']) {
            return sprintf(
                'Los nodos ClickHouse %s excedieron el tiempo máximo de respuesta.',
                implode(', ', $labels),
            );
        }

        if ($types === ['authentication']) {
            return sprintf(
                'Los nodos ClickHouse %s rechazaron las credenciales de conexión.',
                implode(', ', $labels),
            );
        }

        $messages = [];
        foreach ($failed as $status) {
            $label = (string) ($status['label'] ?? 'ClickHouse');
            $message = (string) ($status['message'] ?? 'El nodo devolvió un error al procesar la consulta.');
            $messages[] = $label.': '.$message;
        }

        return 'No fue posible completar la consulta. '.implode(' ', $messages);
    }

    /** @param array<string, int> $perNode @param callable(int): void $progress */
    public function exportTo(CgnatSearch $search, string $destination, array $perNode, callable $progress): void
    {
        $nodes = $this->nodes($search);
        [$sql, $parameters] = $this->compileExport($search);
        $completed = 0;
        $headerWritten = false;
        $output = fopen($destination, 'wb');
        if ($output === false) {
            throw new RuntimeException('No fue posible crear el archivo CSV.');
        }

        try {
            foreach ($nodes as $name => $node) {
                $part = $destination.'.'.$name.'.part';
                $response = Http::sink($part)
                    ->withBasicAuth((string) $node['username'], (string) $node['password'])
                    ->connectTimeout((int) config('clickhouse.connect_timeout', 3))
                    ->timeout((int) config('clickhouse.export_timeout', 3600))
                    ->withOptions([
                        'verify' => (bool) $node['verify_tls'],
                        'query' => [...$parameters, 'database' => $node['database'], 'query_id' => (string) Str::uuid()],
                    ])
                    ->withBody($this->applyTables(str_replace('__FORMAT__', $headerWritten ? 'CSV' : 'CSVWithNames', $sql), $search, $node), 'text/plain')
                    ->post(rtrim((string) $node['url'], '/').'/');

                if (! $response->successful()) {
                    @unlink($part);
                    throw new RuntimeException('No fue posible exportar el nodo '.$node['label'].': '.$this->responseError($response));
                }

                $input = fopen($part, 'rb');
                if ($input === false) {
                    throw new RuntimeException('No fue posible leer una parte del CSV generado.');
                }
                stream_copy_to_stream($input, $output);
                fclose($input);
                @unlink($part);
                $headerWritten = true;
                $completed += $perNode[$name] ?? 0;
                $progress($completed);
            }
        } finally {
            fclose($output);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function nodes(CgnatSearch $search): array
    {
        if (! config('clickhouse.enabled')) {
            throw new RuntimeException('Las conexiones ClickHouse todavía no están habilitadas en este ambiente.');
        }
        $configuredNodes = config('clickhouse.nodes', []);
        $nodes = collect($search->nodes)->mapWithKeys(fn (string $name): array => isset($configuredNodes[$name]) ? [$name => $configuredNodes[$name]] : [])->all();
        if ($nodes === []) {
            throw new RuntimeException('No existe ningún nodo ClickHouse válido para la consulta.');
        }

        return $nodes;
    }

    /** @param array<string, array<string, mixed>> $nodes @param array<string, scalar> $parameters */
    private function pool(array $nodes, string $sql, array $parameters, CgnatSearch $search): array
    {
        $queryId = (string) Str::uuid();

        return Http::pool(function (Pool $pool) use ($nodes, $sql, $parameters, $queryId, $search): array {
            $requests = [];
            foreach ($nodes as $name => $node) {
                if (blank($node['url'])) {
                    continue;
                }

                $nodeParameters = $parameters;
                if (str_contains($sql, '{source_node_key:String}')) {
                    $nodeParameters['param_source_node_key'] = $name;
                }

                $requests[] = $pool->as($name)->withBasicAuth((string) $node['username'], (string) $node['password'])
                    ->connectTimeout((int) config('clickhouse.connect_timeout', 3))
                    ->timeout((int) config('clickhouse.query_timeout', 30))
                    ->withOptions([
                        'verify' => (bool) $node['verify_tls'],
                        'query' => [...$nodeParameters, 'database' => $node['database'], 'query_id' => $queryId.'-'.$name, 'wait_end_of_query' => 1],
                    ])
                    ->withBody($this->applyTables($sql, $search, $node), 'text/plain')
                    ->post(rtrim((string) $node['url'], '/').'/');
            }

            return $requests;
        });
    }

    /** @return array{string, array<string, scalar>} */
    private function compileRows(CgnatSearch $search): array
    {
        [$where, $parameters] = $this->where($search, true);
        $limit = self::PAGE_SIZE + 1;

        return [<<<SQL
SELECT start_time, end_time, router_ip, router_port,
    private_ip, private_port, public_ip, public_port,
    destination_ip, destination_port, packet_size,
    {source_node_key:String} AS source_node_key
FROM (__TABLES__)
WHERE {$where}
ORDER BY start_time DESC, end_time DESC, router_ip DESC, router_port DESC,
    private_ip DESC, private_port DESC, public_ip DESC, public_port DESC,
    destination_ip DESC, destination_port DESC, packet_size DESC, source_node_key DESC
LIMIT {$limit}
FORMAT JSONEachRow
SQL, $parameters];
    }

    /** @return array{string, array<string, scalar>} */
    private function compileCount(CgnatSearch $search): array
    {
        [$where, $parameters] = $this->where($search);

        return ["SELECT count() AS total FROM (__TABLES__) WHERE {$where} FORMAT JSONEachRow", $parameters];
    }

    /** @return array{string, array<string, scalar>} */
    private function compileExport(CgnatSearch $search): array
    {
        [$where, $parameters] = $this->where($search);

        return [<<<SQL
SELECT start_time, end_time, router_ip, router_port,
    private_ip, private_port, public_ip, public_port,
    destination_ip, destination_port, packet_size
FROM (__TABLES__)
WHERE {$where}
ORDER BY start_time DESC, end_time DESC
FORMAT __FORMAT__
SQL, $parameters];
    }

    /** @return array{string, array<string, scalar>} */
    private function where(CgnatSearch $search, bool $includeCursor = false): array
    {
        $conditions = [
            'start_time >= {from:DateTime}',
            'start_time <= {to:DateTime}',
            'router_ip = {router_ip:String}',
        ];
        $parameters = [
            'param_from' => $search->from->format('Y-m-d H:i:s'),
            'param_to' => $search->to->format('Y-m-d H:i:s'),
            'param_router_ip' => $search->routerIp,
        ];
        $filters = [
            ['private_ip', $search->privateIp, 'private_ip = {private_ip:String}'],
            ['private_port', $search->privatePort, 'private_port = {private_port:UInt16}'],
            ['public_ip', $search->publicIp, 'public_ip = {public_ip:String}'],
            ['public_port_from', $search->publicPortFrom, 'public_port >= {public_port_from:UInt16}'],
            ['public_port_to', $search->publicPortTo, 'public_port <= {public_port_to:UInt16}'],
            ['destination_ip', $search->destinationIp, 'destination_ip = {destination_ip:String}'],
            ['destination_port', $search->destinationPort, 'destination_port = {destination_port:UInt16}'],
        ];

        foreach ($filters as [$name, $value, $condition]) {
            if ($value === null || $value === '') {
                continue;
            }
            $conditions[] = $condition;
            $parameters['param_'.$name] = $value;
        }

        if ($includeCursor && filled($search->cursor)) {
            $cursor = $this->decodeCursor((string) $search->cursor);
            $conditions[] = <<<'SQL'
tuple(
    toString(start_time), toString(end_time), router_ip, router_port,
    private_ip, private_port, public_ip, public_port,
    destination_ip, destination_port, packet_size, {source_node_key:String}
) < tuple(
    {cursor_start_time:String}, {cursor_end_time:String}, {cursor_router_ip:String}, {cursor_router_port:UInt64},
    {cursor_private_ip:String}, {cursor_private_port:UInt64}, {cursor_public_ip:String}, {cursor_public_port:UInt64},
    {cursor_destination_ip:String}, {cursor_destination_port:UInt64}, {cursor_packet_size:UInt64}, {cursor_source_node_key:String}
)
SQL;
            foreach ($cursor as $name => $value) {
                $parameters['param_cursor_'.$name] = $value;
            }
        }

        return [implode("\n    AND ", $conditions), $parameters];
    }

    /** @param array<string, mixed> $node */
    private function applyTables(string $sql, CgnatSearch $search, array $node): string
    {
        $tables = [];
        for ($day = $search->from->startOfDay(); $day <= $search->to->startOfDay(); $day = $day->addDay()) {
            $table = sprintf((string) $node['table_pattern'], $day->format('Y_m_d'));
            $database = (string) $node['database'];
            if (! preg_match('/^[A-Za-z0-9_]+$/', $table) || ! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
                throw new RuntimeException('El nombre de tabla o base ClickHouse no es válido.');
            }
            $tables[] = sprintf(<<<'SQL'
SELECT start_time, end_time, toString(router_ip) AS router_ip, router_port,
    toString(private_ip) AS private_ip, private_port, toString(public_ip) AS public_ip,
    public_port, toString(destination_ip) AS destination_ip, destination_port, packet_size
FROM `%s`.`%s`
SQL, $database, $table);
        }

        return str_replace('__TABLES__', implode(' UNION ALL ', $tables), $sql);
    }

    /** @param array<string, mixed> $row @return list<string|int> */
    private function rowSortKey(array $row): array
    {
        return [
            (string) ($row['start_time'] ?? ''),
            (string) ($row['end_time'] ?? ''),
            (string) ($row['router_ip'] ?? ''),
            (int) ($row['router_port'] ?? 0),
            (string) ($row['private_ip'] ?? ''),
            (int) ($row['private_port'] ?? 0),
            (string) ($row['public_ip'] ?? ''),
            (int) ($row['public_port'] ?? 0),
            (string) ($row['destination_ip'] ?? ''),
            (int) ($row['destination_port'] ?? 0),
            (int) ($row['packet_size'] ?? 0),
            (string) ($row['source_node_key'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row */
    private function encodeCursor(array $row): string
    {
        $payload = [
            'start_time' => (string) ($row['start_time'] ?? ''),
            'end_time' => (string) ($row['end_time'] ?? ''),
            'router_ip' => (string) ($row['router_ip'] ?? ''),
            'router_port' => (int) ($row['router_port'] ?? 0),
            'private_ip' => (string) ($row['private_ip'] ?? ''),
            'private_port' => (int) ($row['private_port'] ?? 0),
            'public_ip' => (string) ($row['public_ip'] ?? ''),
            'public_port' => (int) ($row['public_port'] ?? 0),
            'destination_ip' => (string) ($row['destination_ip'] ?? ''),
            'destination_port' => (int) ($row['destination_port'] ?? 0),
            'packet_size' => (int) ($row['packet_size'] ?? 0),
            'source_node_key' => (string) ($row['source_node_key'] ?? ''),
        ];

        return rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{start_time:string,end_time:string,router_ip:string,router_port:int,private_ip:string,private_port:int,public_ip:string,public_port:int,destination_ip:string,destination_port:int,packet_size:int,source_node_key:string} */
    private function decodeCursor(string $cursor): array
    {
        try {
            $padding = (4 - strlen($cursor) % 4) % 4;
            $decoded = base64_decode(strtr($cursor.str_repeat('=', $padding), '-_', '+/'), true);
            if ($decoded === false) {
                throw new RuntimeException('Cursor inválido.');
            }
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new RuntimeException('Cursor inválido.');
            }

            $required = [
                'start_time', 'end_time', 'router_ip', 'router_port', 'private_ip', 'private_port',
                'public_ip', 'public_port', 'destination_ip', 'destination_port', 'packet_size', 'source_node_key',
            ];
            foreach ($required as $field) {
                if (! array_key_exists($field, $payload)) {
                    throw new RuntimeException('Cursor inválido.');
                }
            }

            return [
                'start_time' => (string) $payload['start_time'],
                'end_time' => (string) $payload['end_time'],
                'router_ip' => (string) $payload['router_ip'],
                'router_port' => (int) $payload['router_port'],
                'private_ip' => (string) $payload['private_ip'],
                'private_port' => (int) $payload['private_port'],
                'public_ip' => (string) $payload['public_ip'],
                'public_port' => (int) $payload['public_port'],
                'destination_ip' => (string) $payload['destination_ip'],
                'destination_port' => (int) $payload['destination_port'],
                'packet_size' => (int) $payload['packet_size'],
                'source_node_key' => (string) $payload['source_node_key'],
            ];
        } catch (Throwable $exception) {
            throw new RuntimeException('La paginación ya no es válida. Ejecuta nuevamente la consulta.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $node @return array<string, mixed> */
    private function connectionFailure(string $name, array $node, ConnectionException $exception): array
    {
        $detail = trim($exception->getMessage());
        $normalized = Str::lower($detail);
        $isTimeout = str_contains($normalized, 'timed out')
            || str_contains($normalized, 'timeout')
            || str_contains($normalized, 'curl error 28');

        return [
            'ok' => false,
            'type' => $isTimeout ? 'timeout' : 'connection',
            'label' => (string) ($node['label'] ?? strtoupper($name)),
            'message' => $isTimeout
                ? 'El nodo excedió el tiempo máximo de respuesta.'
                : 'No fue posible establecer conexión con el nodo.',
            'detail' => Str::limit($detail, 500),
        ];
    }

    /** @param array<string, mixed> $node @return array<string, mixed> */
    private function responseFailure(string $name, array $node, mixed $response): array
    {
        $label = (string) ($node['label'] ?? strtoupper($name));

        if (blank($node['url'] ?? null)) {
            return [
                'ok' => false,
                'type' => 'configuration',
                'label' => $label,
                'message' => 'El nodo no tiene una URL de conexión configurada.',
                'detail' => '',
            ];
        }

        if (! $response instanceof Response) {
            return [
                'ok' => false,
                'type' => 'invalid_response',
                'label' => $label,
                'message' => 'El nodo no devolvió una respuesta válida.',
                'detail' => '',
            ];
        }

        $body = trim($response->body());
        $normalized = Str::lower($body);
        $status = $response->status();

        if ($this->isMissingTableError($normalized)) {
            $dates = $this->extractMissingTableDates($body);

            return [
                'ok' => false,
                'type' => 'missing_table',
                'label' => $label,
                'dates' => $dates,
                'message' => count($dates) === 1
                    ? 'No existe la tabla de datos correspondiente a la fecha '.$dates[0].'.'
                    : 'No existen una o más tablas de datos para el rango de fechas consultado.',
                'detail' => Str::limit($body, 500),
            ];
        }

        if (
            in_array($status, [401, 403], true)
            || str_contains($normalized, 'authentication failed')
            || str_contains($normalized, 'password is incorrect')
        ) {
            return [
                'ok' => false,
                'type' => 'authentication',
                'label' => $label,
                'message' => 'El nodo rechazó las credenciales de conexión.',
                'detail' => Str::limit($body, 500),
            ];
        }

        if (
            in_array($status, [408, 504], true)
            || str_contains($normalized, 'timeout exceeded')
            || str_contains($normalized, 'query execution timeout')
        ) {
            return [
                'ok' => false,
                'type' => 'timeout',
                'label' => $label,
                'message' => 'El nodo excedió el tiempo máximo de respuesta.',
                'detail' => Str::limit($body, 500),
            ];
        }

        return [
            'ok' => false,
            'type' => 'query_error',
            'label' => $label,
            'message' => 'El nodo devolvió un error al procesar la consulta.',
            'detail' => Str::limit($body, 500),
        ];
    }

    private function isMissingTableError(string $message): bool
    {
        return str_contains($message, 'unknown_table')
            || str_contains($message, 'unknown table')
            || str_contains($message, 'code: 60')
            || (str_contains($message, 'table') && str_contains($message, "doesn't exist"))
            || (str_contains($message, 'table') && str_contains($message, 'does not exist'));
    }

    /** @return list<string> */
    private function extractMissingTableDates(string $message): array
    {
        preg_match_all(
            '/huawei_cgn_nat_v2_(\d{4})_(\d{2})_(\d{2})/i',
            $message,
            $matches,
            PREG_SET_ORDER,
        );

        $dates = [];
        foreach ($matches as $match) {
            $dates[] = sprintf('%s/%s/%s', $match[3], $match[2], $match[1]);
        }

        return array_values(array_unique($dates));
    }

    private function responseError(mixed $response): string
    {
        return $response instanceof Response ? Str::limit(trim($response->body()), 180) : 'El nodo no devolvió una respuesta válida.';
    }
}
