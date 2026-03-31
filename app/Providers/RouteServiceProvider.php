<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Registers the application's route files with their middleware groups.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->routes(
            /**
             * Maps the API and web route files into the router.
             *
             * @return void
             */
            function () {
                Route::middleware('api')
                    ->group(base_path('routes/api.php'));

                Route::middleware('web')
                    ->group(base_path('routes/web.php'));
            }
        );
    }
}
