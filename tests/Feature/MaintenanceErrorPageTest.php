<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class MaintenanceErrorPageTest extends TestCase
{
    public function test_unhandled_web_error_displays_the_maintenance_page_without_details(): void
    {
        config()->set('app.debug', false);

        Route::get('/testing/unhandled-error', static function (): never {
            throw new RuntimeException('Detalle interno que no debe mostrarse.');
        });

        $this->get('/testing/unhandled-error')
            ->assertInternalServerError()
            ->assertSee('Portal en mantenimiento')
            ->assertDontSee('Detalle interno que no debe mostrarse.');
    }

    public function test_service_unavailable_uses_the_maintenance_page(): void
    {
        config()->set('app.debug', false);

        Route::get('/testing/service-unavailable', static fn () => abort(503));

        $this->get('/testing/service-unavailable')
            ->assertServiceUnavailable()
            ->assertSee('Portal en mantenimiento');
    }
}
