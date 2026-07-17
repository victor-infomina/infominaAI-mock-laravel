<?php

return [
    'cases_path' => env('SSM_MOCK_CASES_PATH', storage_path('app/ssm-fixtures/cases')),
    'api_key' => env('MOCK_SSM_API_KEY'),
    'api_secret' => env('MOCK_SSM_API_SECRET'),
];
