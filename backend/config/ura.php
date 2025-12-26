<?php

return [
    /*
    |--------------------------------------------------------------------------
    | URA Webhook Secret
    |--------------------------------------------------------------------------
    | Shared secret para autenticar o POST da URA para sua API.
    */
    'webhook_secret' => env('URA_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Default language
    |--------------------------------------------------------------------------
    */
    'default_language' => env('URA_DEFAULT_LANGUAGE', 'pt_BR'),

    /*
    |--------------------------------------------------------------------------
    | Queue (Jobs URA)
    |--------------------------------------------------------------------------
    */
    'job_queue' => env('URA_JOB_QUEUE', 'ura'),

    'job_tries' => (int) env('URA_JOB_TRIES', 3),
    'job_backoff_seconds' => (int) env('URA_JOB_BACKOFF_SECONDS', 10),
];
