<?php

declare(strict_types=1);

namespace App\Approov;

use App\Approov\Config\ApproovConfig;
use App\Approov\Http\ApproovExceptionResponder;
use App\Approov\State\ApproovStateStore;
use App\Approov\Verification\LocalRequestVerifier;
use App\Approov\Verification\RequestVerifier;
use Illuminate\Support\ServiceProvider;

final class ApproovServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/Resources/config/approov.php', 'approov');

        $this->app->singleton(ApproovConfig::class);
        $this->app->singleton(ApproovStateStore::class);
        $this->app->singleton(RequestVerifier::class, LocalRequestVerifier::class);
        $this->app->singleton(ApproovService::class);
        $this->app->singleton(ApproovExceptionResponder::class);
    }
}
