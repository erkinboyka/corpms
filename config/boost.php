<?php

declare(strict_types=1);

return [

    /*
     * SEC-04: Boost (и его неаутентифицированный /_boost/browser-logs) должен
     * быть доступен только в локальном окружении. В production значения из .env
     * не должны включать этот функционал даже при установленных dev-пакетах.
     */
    'enabled' => env('BOOST_ENABLED', env('APP_ENV', 'production') === 'local'),

    /*
     * Browser log watcher пишет произвольные данные с браузера в storage/logs.
     * По умолчанию выключен; включать только точечно для dev-диагностики.
     */
    'browser_logs_watcher' => env('BOOST_BROWSER_LOGS_WATCHER', false),

    'browser_logs_allowed_ips' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('BOOST_BROWSER_LOGS_ALLOWED_IPS', '127.0.0.1,::1')),
    ))),

];
