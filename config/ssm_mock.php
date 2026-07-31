<?php

return [
    'cases_path' => env('SSM_MOCK_CASES_PATH', storage_path('app/ssm-fixtures/cases')),
    'local_admin_enabled' => env('APP_ENV') === 'local',
    's3_bucket' => env('SSM_S3_BUCKET'),
    'remote_url' => env('SSM_MOCK_REMOTE_URL'),
    'admin_sync_key' => env('SSM_MOCK_ADMIN_SYNC_KEY'),
    'admin_sync_secret' => env('SSM_MOCK_ADMIN_SYNC_SECRET'),
];
