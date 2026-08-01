<?php

return [
    'cases_path' => env('ASIAVERIFY_MOCK_CASES_PATH', storage_path('app/asiaverify-fixtures/cases')),
    'token_ttl_seconds' => (int) env('ASIAVERIFY_MOCK_TOKEN_TTL_SECONDS', 3600),
];
