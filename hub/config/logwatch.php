<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sonnet review
    |--------------------------------------------------------------------------
    |
    | Every summary is reviewed by one model. The fallback lets the API retry
    | on another model if the review is declined by a safety classifier, which
    | security logs can occasionally trip.
    |
    */

    'review' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('LOGWATCH_REVIEW_MODEL', 'claude-sonnet-5-5'),
        'effort' => env('LOGWATCH_REVIEW_EFFORT', 'high'),
        'max_tokens' => (int) env('LOGWATCH_REVIEW_MAX_TOKENS', 16000),
        'timeout' => (int) env('LOGWATCH_REVIEW_TIMEOUT', 240),
        'history_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert email
    |--------------------------------------------------------------------------
    |
    | With no recipient, or in silent mode, alerts are still recorded and shown
    | on the dashboard; they just aren't emailed. Silent mode is for the tuning
    | week before email is turned on.
    |
    */

    'alerts' => [
        'to' => env('LOGWATCH_ALERT_TO'),
        'silent' => (bool) env('LOGWATCH_SILENT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hard rules
    |--------------------------------------------------------------------------
    |
    | SSH keys allowed to log in from outside the tailnet on every server,
    | matched against the key's SHA256 fingerprint or its authorized_keys
    | comment. Fingerprints are stronger: anyone who can add a key can choose
    | its comment (though adding a key is itself a hard-rule alert). Each
    | server can add its own with `logwatch:server-config --allow-key`.
    |
    */

    'rules' => [
        'ssh_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOGWATCH_SSH_ALLOWLIST', 'worker@forge.laravel.com'))))),
        'disk_used_pct' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Missing reports
    |--------------------------------------------------------------------------
    |
    | Collectors report the previous UTC day shortly after midnight. If a
    | server that has reported before has no summary for a day by the cutoff
    | (UTC, next morning), that's an alert. The check looks back a few days so
    | a gap isn't missed if the hub itself was down at the cutoff.
    |
    */

    'reports' => [
        'cutoff' => env('LOGWATCH_REPORT_CUTOFF', '06:00'),
        'lookback_days' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hub liveness
    |--------------------------------------------------------------------------
    |
    | A check-in URL (for example from healthchecks.io) pinged every five
    | minutes by a queued job, so it proves both the scheduler and the queue
    | worker are running. If the pings stop, that service sends the email.
    |
    */

    'heartbeat' => [
        'url' => env('LOGWATCH_HEARTBEAT_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Weekly digest
    |--------------------------------------------------------------------------
    |
    | The digest is always on the dashboard. Emailing it on Monday mornings is
    | off until the tuning week is over.
    |
    */

    'digest' => [
        'email' => (bool) env('LOGWATCH_DIGEST_EMAIL', false),
    ],

];
