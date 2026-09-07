<?php

use App\Http\Controllers\Auth\PortalLoginController;
use App\Http\Controllers\Auth\PortalSessionController;
use App\Http\Controllers\Cgnat\AuditController;
use App\Http\Controllers\Cgnat\ExportController;
use App\Http\Controllers\Cgnat\QueryController;
use App\Http\Controllers\Cgnat\TemplateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(config('ldap.enabled', true) ? 'portal.login' : 'queries.index'));

Route::prefix('portalcgnat')->group(function (): void {
    Route::get('/', fn (Request $request) => redirect()->route(
        config('ldap.enabled', true) ? 'portal.login' : 'queries.index'
    ));

    Route::get('/login', [PortalLoginController::class, 'show'])->name('portal.login');
    Route::post('/login', [PortalLoginController::class, 'login'])->name('portal.login.submit');

    Route::middleware('portal.auth:cgnat.query')->group(function (): void {
        Route::get('/consultas', [QueryController::class, 'index'])->name('queries.index');
        Route::post('/consultas', [QueryController::class, 'store'])->name('queries.store');
        Route::post('/salir', [PortalSessionController::class, 'destroy'])->name('auth.logout');
    });

    Route::middleware('portal.auth:cgnat.exports')->group(function (): void {
        Route::get('/tareas-y-exportaciones', [ExportController::class, 'index'])->name('exports.index');
        Route::post('/exportaciones', [ExportController::class, 'store'])->name('exports.store');
        Route::get('/exportaciones/{id}/descargar', [ExportController::class, 'download'])->name('exports.download');
    });

    Route::middleware('portal.auth:cgnat.templates')->group(function (): void {
        Route::get('/plantillas', [TemplateController::class, 'index'])->name('templates.index');
        Route::post('/plantillas', [TemplateController::class, 'store'])->name('templates.store');
    });

    Route::middleware('portal.auth:cgnat.audit.view')->group(function (): void {
        Route::get('/auditoria', [AuditController::class, 'index'])->name('audit.index');
    });
});

Route::get('/health/live', fn () => response()->json(['status' => 'ok']))->name('health.live');
