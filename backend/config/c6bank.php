<?php

return [

    'base_url' => rtrim(
        env('C6_BASE_URL', 'https://marketplace-proposal-service-api-p.c6bank.info'),
        '/'
    ),

    'auth' => [
        'username' => env('C6_USERNAME'),
        'password' => env('C6_PASSWORD'),
    ],

    'http' => [
        'timeout'         => (int) env('C6_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('C6_HTTP_CONNECT_TIMEOUT', 5),
        'retry'           => (int) env('C6_HTTP_RETRY', 1),
        'retry_delay_ms'  => (int) env('C6_HTTP_RETRY_DELAY_MS', 200),
    ],

    'job' => [
        'queue'   => env('C6_JOB_QUEUE', 'c6-auth'),
        'timeout' => (int) env('C6_JOB_TIMEOUT', 60),
    ],

    'token' => [
        'ttl_seconds' => (int) env('C6_TOKEN_TTL_SECONDS', 1199),
        'skew'        => (int) env('C6_TOKEN_TTL_SKEW', 60),
    ],

    'authorization' => [
        'first_poll_delay_seconds' => (int) env('C6_AUTH_STATUS_FIRST_POLL_DELAY', 60),
        'poll_interval_seconds'    => (int) env('C6_AUTH_STATUS_POLL_INTERVAL', 60),
        'max_wait_minutes'         => (int) env('C6_AUTH_STATUS_MAX_WAIT_MINUTES', 20),
        'reminder_every_minutes'   => (int) env('C6_AUTH_STATUS_REMINDER_EVERY_MINUTES', 5),

        // ✅ performance knobs
        'status_lock_seconds'          => (int) env('C6_STATUS_LOCK_SECONDS', 30),
        'store_raw_payload'            => (bool) env('C6_STORE_RAW_PAYLOAD', false),
        'persist_pending_every_seconds'=> (int) env('C6_PENDING_PERSIST_EVERY_SECONDS', 300),
    ],

    'rate_limit' => [
        'read_per_minute_user'  => (int) env('C6_RATE_LIMIT_READ_USER', 600),
        'read_per_minute_ip'    => (int) env('C6_RATE_LIMIT_READ_IP', 1800),
        'write_per_minute_user' => (int) env('C6_RATE_LIMIT_WRITE_USER', 90),
        'write_per_minute_ip'   => (int) env('C6_RATE_LIMIT_WRITE_IP', 300),
    ],

    'headers' => [
        'authorization_generate_accept' => env(
            'C6_AUTHORIZATION_GENERATE_ACCEPT',
            'application/vnd.c6bank_authorization_generate_liveness_v1+json'
        ),

        'authorization_status_accept' => env(
            'C6_AUTHORIZATION_STATUS_ACCEPT',
            'application/vnd.c6bank_authorization_status_v1+json'
        ),
    ],
];
