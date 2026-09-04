<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * El release productivo usa un autoloader Composer classmap-authoritative.
         * Al actualizar solo código mediante WinSCP, estas clases pueden no estar
         * aún en vendor/composer. Cargarlas aquí evita requerir una nueva imagen.
         */
        foreach ([
            app_path('Http/Middleware/PortalAuthenticate.php'),
            app_path('Http/Controllers/Auth/PortalSessionController.php'),
            app_path('Http/Controllers/Auth/PortalLoginController.php'),
            app_path('Http/Controllers/Cgnat/ExportController.php'),
            app_path('Http/Controllers/Cgnat/TemplateController.php'),
            app_path('Services/Auth/LdapAuthenticationService.php'),
            app_path('Services/ClickHouse/PortalStoreService.php'),
            app_path('Services/ClickHouse/PortalAuthorizationRepository.php'),
            app_path('Services/ClickHouse/PortalQueryAuditService.php'),
            app_path('Services/Portal/ExportTaskRepository.php'),
            app_path('Services/Portal/QueryTemplateRepository.php'),
            app_path('Jobs/GenerateCgnatExport.php'),
        ] as $file) {
            if (is_file($file)) {
                require_once $file;
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
