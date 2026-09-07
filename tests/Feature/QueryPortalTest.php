<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QueryPortalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('ldap.enabled', false);
    }

    public function test_home_redirects_to_the_query_portal(): void
    {
        $this->get('/')->assertRedirect('/portalcgnat/consultas');
    }

    public function test_query_portal_is_rendered(): void
    {
        $this->get('/portalcgnat/consultas')
            ->assertOk()
            ->assertSee('Portal CGNAT')
            ->assertSee('Consulta IPv4 PAT')
            ->assertSee('CH-01');
    }

    public function test_query_requires_a_discriminating_filter(): void
    {
        $this->from('/portalcgnat/consultas')->post('/portalcgnat/consultas', [
            'from' => '2026-08-04T09:00',
            'to' => '2026-08-04T10:00',
            'nodes' => ['ch01'],
            'limit' => 100,
        ])->assertRedirect('/portalcgnat/consultas')
            ->assertSessionHasErrors('private_ip');
    }

    public function test_query_range_is_limited(): void
    {
        $this->from('/portalcgnat/consultas')->post('/portalcgnat/consultas', [
            'from' => '2026-08-01T09:00',
            'to' => '2026-08-04T10:00',
            'nodes' => ['ch01'],
            'private_ip' => '10.10.10.10',
            'limit' => 100,
        ])->assertRedirect('/portalcgnat/consultas')
            ->assertSessionHasErrors('to');
    }

    public function test_disabled_clickhouse_is_reported_without_crashing_the_portal(): void
    {
        config()->set('clickhouse.enabled', false);

        $this->post('/portalcgnat/consultas', [
            'from' => '2026-08-04T09:00',
            'to' => '2026-08-04T10:00',
            'nodes' => ['ch01'],
            'private_ip' => '10.10.10.10',
            'limit' => 100,
        ])->assertOk()
            ->assertSee('todav')
            ->assertSee('no est');
    }

    public function test_results_from_multiple_nodes_are_merged(): void
    {
        config()->set('clickhouse.enabled', true);
        config()->set('clickhouse.nodes', [
            'ch01' => $this->node('CH-01', 'http://ch01.internal:8123'),
            'ch02' => $this->node('CH-02', 'http://ch02.internal:8123'),
        ]);

        Http::preventStrayRequests();
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->body(), 'count()')) {
                return Http::response("{\"total\":1}\n", 200);
            }

            return str_contains($request->url(), 'ch01.internal')
                ? Http::response($this->row('2026-08-04 09:10:00', 'event-01'), 200)
                : Http::response($this->row('2026-08-04 09:20:00', 'event-02'), 200);
        });

        $this->post('/portalcgnat/consultas', [
            'from' => '2026-08-04T09:00',
            'to' => '2026-08-04T10:00',
            'nodes' => ['ch01', 'ch02'],
            'private_ip' => '10.10.10.10',
            'limit' => 100,
        ])->assertOk()
            ->assertSee('event-01')
            ->assertSee('event-02')
            ->assertSee('CH-01')
            ->assertSee('CH-02')
            ->assertSee('OK');

        Http::assertSentCount(4);
    }

    /**
     * @return array<string, mixed>
     */
    private function node(string $label, string $url): array
    {
        return [
            'label' => $label,
            'url' => $url,
            'username' => 'portal',
            'password' => 'test',
            'database' => 'cgnat',
            'table_pattern' => 'huawei_cgn_nat_v2_%s',
            'verify_tls' => false,
        ];
    }

    private function row(string $eventTime, string $eventId): string
    {
        return json_encode([
            'event_time' => $eventTime,
            'start_time' => $eventTime,
            'end_time' => $eventTime,
            'event_id' => $eventId,
            'event_type' => 'huawei_cgn_nat',
            'router_ip' => '10.96.167.132',
            'router_port' => 9088,
            'protocol' => 'TCP',
            'private_ip' => '10.10.10.10',
            'private_port' => 12345,
            'public_ip' => '190.10.10.10',
            'public_port' => 54321,
            'destination_ip' => '8.8.8.8',
            'destination_port' => 443,
            'packet_size' => 128,
        ], JSON_THROW_ON_ERROR)."\n";
    }
}
