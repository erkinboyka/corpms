<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Скрипт service worker отдаётся Laravel, а не статическим файлом:
 * флаг corpms.service_worker.enabled работает и для уже установленных воркеров.
 */
class ServiceWorkerController extends Controller
{
    public function show(): Response
    {
        /** @var View $view */
        $view = view('service-worker', [
            'enabled' => (bool) config('corpms.service_worker.enabled'),
        ]);

        return response($view->render(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
