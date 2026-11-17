<?php

namespace Tests\Feature;

use App\Domain\Cgnat\CgnatSearch;
use App\Jobs\GenerateCgnatExport;
use App\Services\ClickHouse\ClickHouseQueryService;
use App\Services\ClickHouse\PortalQueryAuditService;
use App\Services\Portal\ExportTaskRepository;
use App\Services\Portal\PortalAuditService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SevenCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Exercise the same permission checks as basic/admin without LDAP or a real store.
        config()->set('ldap.enabled', false);
        config()->set('ldap.temporary_permissions', ['cgnat.query', 'cgnat.exports']);
        config()->set('clickhouse.enabled', true);
        config()->set('clickhouse.export_threshold', 100000);
        config()->set('clickhouse.nodes', collect(range(1, 7))->mapWithKeys(function (int $number): array {
            $name = sprintf('ch%02d', $number);

            return [$name => [
                'label' => sprintf('CH-%02d', $number),
                'url' => "http://{$name}.example.test:8123",
                'username' => 'test', 'password' => 'test',
                'database' => 'cgnat', 'table_pattern' => 'huawei_cgn_nat_v2_%s',
                'verify_tls' => true,
            ]];
        })->all());
        $this->mock(PortalQueryAuditService::class, function ($mock): void {
            $mock->shouldReceive('recordSuccess', 'recordFailure')->andReturnNull();
        });
        $this->mock(PortalAuditService::class, function ($mock): void {
            $mock->shouldReceive('record')->andReturnNull();
        });
        Http::preventStrayRequests();
    }

    public function test_basic_user_searches_all_seven_even_when_posting_a_subset(): void
    {
        $this->fakeCollectors();
        $this->get('/portalcgnat/consultas')->assertOk()->assertDontSee('name="nodes[]"', false);
        $this->post('/portalcgnat/consultas', $this->filters(['ch01']))
            ->assertOk()->assertViewHas('result', fn (array $result): bool =>
                $result['total_rows'] === 7 && count($result['rows']) === 7
                && array_keys($result['nodes']) === array_keys(config('clickhouse.nodes')));
        Http::assertSentCount(21); // Table existence, count, and rows on each collector.
    }

    public function test_administrator_sees_seven_choices_and_can_search_only_new_collectors(): void
    {
        config()->set('ldap.temporary_permissions', ['cgnat.query', 'cgnat.nodes']);
        $this->fakeCollectors();
        $page = $this->get('/portalcgnat/consultas')->assertOk();
        foreach (array_keys(config('clickhouse.nodes')) as $name) {
            $page->assertSee('value="'.$name.'"', false);
        }
        $selected = ['ch05', 'ch06', 'ch07'];
        $this->post('/portalcgnat/consultas', $this->filters($selected))
            ->assertOk()->assertViewHas('result', fn (array $result): bool =>
                $result['total_rows'] === 3 && array_keys($result['nodes']) === $selected);
        Http::assertSentCount(9);
    }

    public function test_unconfigured_collector_is_rejected(): void
    {
        $this->postJson('/portalcgnat/consultas', $this->filters(['ch08']))
            ->assertUnprocessable()->assertJsonValidationErrors('nodes.0');
        Http::assertNothingSent();
    }

    public function test_failure_of_seventh_collector_prevents_partial_results(): void
    {
        $this->fakeCollectors('ch07');
        $this->postJson('/portalcgnat/consultas', $this->filters(['ch01']))
            ->assertUnprocessable()->assertSee('CH-07');
    }

    public function test_basic_export_queues_all_seven_and_preserves_them_in_the_job(): void
    {
        $this->fakeCollectors();
        Queue::fake();
        $this->mock(ExportTaskRepository::class, function ($mock): void {
            $mock->shouldReceive('create')->once()->withArgs(fn ($username, CgnatSearch $search, int $total): bool =>
                $search->nodes === array_keys(config('clickhouse.nodes')) && $total === 7
            )->andReturn('00000000-0000-4000-8000-000000000001');
        });
        $this->postJson('/portalcgnat/exportaciones', $this->filters(['ch01']))->assertAccepted();
        Queue::assertPushed(GenerateCgnatExport::class, fn (GenerateCgnatExport $job): bool =>
            $job->filters['nodes'] === array_keys(config('clickhouse.nodes'))
            && $job->perNode === array_fill_keys(array_keys(config('clickhouse.nodes')), 1)
            && $job->totalRows === 7);
        Http::assertSentCount(14);
    }

    public function test_export_reads_seven_collectors_and_writes_one_csv_header(): void
    {
        $this->fakeCollectors();
        $path = tempnam(sys_get_temp_dir(), 'cgnat-seven-');
        $progress = [];
        try {
            $total = app(ClickHouseQueryService::class)->exportTo(
                CgnatSearch::fromValidated($this->filters(array_keys(config('clickhouse.nodes')))),
                $path,
                array_fill_keys(array_keys(config('clickhouse.nodes')), 1),
                function (int $rows) use (&$progress): void { $progress[] = $rows; },
            );
            $csv = file_get_contents($path);
            $this->assertSame(7, $total);
            $this->assertSame(range(1, 7), $progress);
            $this->assertSame(1, substr_count($csv, "event_id\n"));
            foreach (array_keys(config('clickhouse.nodes')) as $name) {
                $this->assertStringContainsString($name."\n", $csv);
            }
            Http::assertSentCount(14);
        } finally {
            @unlink($path);
        }
    }

    private function filters(array $nodes): array
    {
        return [
            'from' => '2026-08-04T09:00', 'to' => '2026-08-04T10:00',
            'router_ip' => '192.0.2.1', 'private_ip' => '10.0.0.1', 'nodes' => $nodes,
        ];
    }

    private function fakeCollectors(?string $failed = null): void
    {
        Http::fake(function (Request $request, array $options) use ($failed) {
            $name = explode('.', parse_url($request->url(), PHP_URL_HOST))[0];
            if ($name === $failed) {
                return Http::response('Unavailable', 503);
            }
            if (str_contains($request->body(), 'FROM system.tables')) {
                return Http::response("{\"name\":\"huawei_cgn_nat_v2_2026_08_04\"}\n");
            }
            if (str_contains($request->body(), 'count()')) {
                return Http::response("{\"total\":1}\n");
            }
            if (isset($options['sink'])) {
                $csv = str_contains($request->body(), 'CSVWithNames') ? "event_id\n{$name}\n" : "{$name}\n";
                return Http::response($csv);
            }

            return Http::response(json_encode([
                'event_id' => $name, 'event_time' => '2026-08-04 09:10:00',
                'start_time' => '2026-08-04 09:10:00', 'end_time' => '2026-08-04 09:11:00',
                'event_type' => 'huawei_cgn_nat', 'router_ip' => '192.0.2.1',
                'router_port' => 1, 'protocol' => 'TCP', 'private_ip' => '10.0.0.1',
                'private_port' => 1234, 'public_ip' => '192.0.2.2', 'public_port' => 5678,
                'destination_ip' => '192.0.2.3', 'destination_port' => 443, 'packet_size' => 128,
            ], JSON_THROW_ON_ERROR)."\n");
        });
    }
}
