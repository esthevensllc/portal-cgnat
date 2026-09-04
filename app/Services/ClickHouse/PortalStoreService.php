<?php

namespace App\Services\ClickHouse;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PortalStoreService
{
    /** @param array<string, scalar> $parameters @return list<array<string, mixed>> */
    public function select(string $sql, array $parameters = []): array
    {
        $response = $this->request($sql."\nFORMAT JSONEachRow", $parameters);

        return collect(preg_split('/\R/', trim($response->body())))
            ->filter()
            ->map(fn (string $line): mixed => json_decode($line, true, flags: JSON_INVALID_UTF8_SUBSTITUTE))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();
    }

    /** @param array<string, scalar> $parameters */
    public function execute(string $sql, array $parameters = []): void
    {
        $this->request($sql, $parameters);
    }

    /** @param array<string, mixed> $row */
    public function insertJson(string $table, array $row): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException('La tabla de persistencia del portal no es válida.');
        }

        $this->request(
            "INSERT INTO `".config('portal_store.database')."`.`{$table}` FORMAT JSONEachRow",
            [],
            json_encode($row, JSON_THROW_ON_ERROR)."\n",
            'application/x-ndjson',
        );
    }

    /** @param array<string, scalar> $parameters */
    private function request(string $sql, array $parameters = [], ?string $body = null, string $contentType = 'text/plain'): Response
    {
        $url = (string) config('portal_store.url');
        $username = (string) config('portal_store.username');
        $password = (string) config('portal_store.password');

        if (blank($url)) {
            throw new RuntimeException('La conexión ClickHouse de persistencia no está configurada.');
        }

        $response = Http::withBasicAuth($username, $password)
            ->connectTimeout((int) config('portal_store.connect_timeout'))
            ->timeout((int) config('portal_store.query_timeout'))
            ->withOptions([
                'verify' => (bool) config('portal_store.verify_tls'),
                'query' => [
                    ...$parameters,
                    'database' => config('portal_store.database'),
                    ...($body !== null ? ['query' => $sql] : []),
                ],
            ])
            ->withBody($body ?? $sql, $contentType)
            ->post(rtrim($url, '/').'/');

        if (! $response->successful()) {
            throw new RuntimeException('No fue posible persistir o consultar información del portal en ClickHouse.');
        }

        return $response;
    }
}
