<?php

namespace App\Providers;

use App\Services\SsmCaseRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SsmCaseRepository::class, function () {
            return new SsmCaseRepository(config('ssm_mock.cases_path'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
