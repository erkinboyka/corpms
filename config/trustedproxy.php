<?php

declare(strict_types=1);

return [
    /*
     * Comma-separated IPs or CIDRs of the reverse proxies allowed to set
     * X-Forwarded-For / X-Forwarded-Proto. Never use "*" in production.
     */
    'proxies' => env('TRUSTED_PROXIES'),
];
