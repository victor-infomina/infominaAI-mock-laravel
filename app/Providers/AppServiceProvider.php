<?php

namespace App\Providers;

use App\Services\AwsS3ObjectFetcher;
use App\Services\S3ObjectFetcher;
use App\Services\SsmCaseRepository;
use Aws\S3\S3Client;
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

        $this->app->bind(S3ObjectFetcher::class, function () {
            return new AwsS3ObjectFetcher(new S3Client([
                'version' => 'latest',
                'region' => env('SSM_S3_REGION'),
                'credentials' => [
                    'key' => env('SSM_S3_ACCESS_KEY_ID'),
                    'secret' => env('SSM_S3_SECRET_ACCESS_KEY'),
                ],
            ]));
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
