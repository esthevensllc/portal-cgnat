<?php

namespace Tests\Feature;

use App\Domain\Cgnat\CgnatSearch;
use App\Services\ClickHouse\PortalQueryAuditService;
use App\Services\Portal\PortalAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('portal_store.url', 'http://portal-store.internal:8123');
        config()->set('portal_store.username', 'portal_service');
        config()->set('portal_store.password', 'secret');
        config()->set('portal_store.database', 'portal_cgnat');
        config()->set('portal_store.verify_tls', false);
        Http::preventStrayRequests();
        Http::fake(['portal-store.internal:8123/*' => Http::response('', 200)]);
    }

    public function test_audit_screen_requires_the_audit_permission(): void
    {
        config()->set('ldap.enabled', false);
        config()->set('ldap.temporary_permissions', ['cgnat.query']);

        $this->get('/portalcgnat/auditoria')->assertForbidden();
    }

    public function test_auditor_can_open_the_audit_screen(): void
    {
        config()->set('ldap.enabled', false);
        config()->set('ldap.temporary_permissions', ['cgnat.query', 'cgnat.audit.view']);

        $this->get('/portalcgnat/auditoria')
            ->assertOk()
            ->assertSee('Auditoría del portal');
    }

    public function test_generic_audit_event_is_persisted_without_secrets(): void
    {
        $request = Request::create('/portalcgnat/login', 'POST', server: ['REMOTE_ADDR' => '172.19.10.186']);
        $request->attributes->set('portal_request_id', (string) Str::uuid());

        app(PortalAuditService::class)->record('auth.login', 'failure', 'E708478', $request, [
            'reason' => 'invalid_credentials',
            'password' => 'never-store-this',
        ]);

        Http::assertSent(function (HttpRequest $request): bool {
            $event = json_decode(trim($request->body()), true, flags: JSON_THROW_ON_ERROR);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['query'] ?? '') === 'INSERT INTO `portal_cgnat`.`audit_events` FORMAT JSONEachRow'
                && $event['username'] === 'e708478'
                && $event['source_ip'] === '172.19.10.186'
                && $event['details_json'] === '{"reason":"invalid_credentials"}'
                && ! str_contains($request->body(), 'never-store-this');
        });
    }

    public function test_query_audit_uses_the_logged_in_user_and_total_rows(): void
    {
        $search = new CgnatSearch(
            CarbonImmutable::parse('2026-08-04 09:00:00', 'America/Lima'),
            CarbonImmutable::parse('2026-08-04 10:00:00', 'America/Lima'),
            ['ch01'],
            '10.96.167.132',
            '10.10.10.10',
            null,
            null,
            null,
            null,
            null,
            null,
        );
        $request = Request::create('/portalcgnat/consultas', 'POST');

        app(PortalQueryAuditService::class)->recordSuccess('E708478', $search, [
            'rows' => [['event_id' => 'one-row-on-screen']],
            'total_rows' => 125000,
            'elapsed_ms' => 3210,
            'large_result' => true,
        ], $request);

        Http::assertSent(function (HttpRequest $request): bool {
            $event = json_decode(trim($request->body()), true, flags: JSON_THROW_ON_ERROR);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['query'] ?? '') === 'INSERT INTO `portal_cgnat`.`query_audit` FORMAT JSONEachRow'
                && $event['username'] === 'e708478'
                && $event['username'] !== 'portal_service'
                && $event['rows'] === 125000
                && $event['status'] === 'export_required';
        });
    }
}
