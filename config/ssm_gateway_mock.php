<?php

return [
    'token_ttl_seconds' => (int) env('SSM_GATEWAY_MOCK_TOKEN_TTL_SECONDS', 86400),
    'report_delay_seconds' => (int) env('SSM_GATEWAY_MOCK_REPORT_DELAY_SECONDS', 0),
    'involvements_path' => env('SSM_GATEWAY_MOCK_INVOLVEMENTS_PATH', storage_path('app/ssm-gateway-fixtures/involvements')),
    'recaptcha_sitekey' => env('SSM_GATEWAY_MOCK_RECAPTCHA_SITEKEY', '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI'),
];
