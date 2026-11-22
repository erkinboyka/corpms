<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Models\Employee;
use App\Support\AiEndpointGuard;
use App\Support\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class BumiFiles
{
    public function directory(Employee $employee): string
    {
        return 'bumi/'.$employee->company_id.'/'.$employee->id;
    }

    public function save(Employee $employee, string $title, string $extension, string $bytes): array
    {
        $file = Str::ulid().'.'.$extension;
        if (! Storage::disk('local')->put($this->directory($employee).'/'.$file, $bytes)) {
            throw new RuntimeException(__('Не удалось сохранить файл'));
        }

        return ['type' => 'file', 'title' => $title, 'file' => $file, 'format' => strtoupper($extension)];
    }

    public function illustration(Employee $employee, string $prompt): array
    {
        $settings = app(SettingsService::class);
        $base = rtrim((string) $settings->get('corpms.ai.base_url'), '/');
        $key = (string) $settings->get('corpms.ai.api_key');
        $model = (string) $settings->get('corpms.ai.image_model');
        if ($model === '' || $base === '' || $key === '') {
            return ['reply' => 'Для иллюстраций администратору нужно подключить модель изображений в настройках AI. Диаграмму по данным я могу построить уже сейчас.', 'blocks' => []];
        }
        if (! AiEndpointGuard::isAllowed($base.'/images/generations')) {
            return ['ok' => false, 'reply' => __('Адрес провайдера AI не прошёл проверку безопасности.'), 'blocks' => []];
        }
        try {
            $response = Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(90)->withoutRedirecting()->post($base.'/images/generations', [
                'model' => $model, 'prompt' => $prompt, 'size' => '1024x1024', 'n' => 1,
            ]);
            $encoded = $response->json('data.0.b64_json');
            $bytes = $response->successful() && is_string($encoded) && strlen($encoded) <= 24_000_000 ? base64_decode($encoded, true) : false;
        } catch (ConnectionException) {
            $bytes = false;
        }
        if (! is_string($bytes) || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || @getimagesizefromstring($bytes) === false) {
            return ['ok' => false, 'reply' => 'Провайдер не вернул PNG. Проверьте доступ к модели изображений и повторите запрос.', 'blocks' => []];
        }

        return ['reply' => __('Иллюстрация готова.'), 'blocks' => [$this->save($employee, __('Иллюстрация'), 'png', $bytes)]];
    }

    public function artifact(Employee $employee, string $title, array $values, string $kind, int $number, string $style, array $source): array
    {
        $rows = [];
        foreach ($values as $label => $value) {
            $rows[] = ['label' => (string) $label, 'value' => $value];
        }
        $chart = ['type' => 'chart', 'title' => $title, 'data' => $rows, 'style' => $style];
        $edition = BumiTemplates::edition($kind, $number);
        $name = $kind === 'chart' ? BumiTemplates::CHARTS[$style] : $edition['name'];
        $graphic = view('ai.graphic', ['series' => $rows] + compact('style', 'title', 'source'))->render();
        $ranked = collect($rows)->sortByDesc('value')->values()->all();
        $context = compact('title', 'edition', 'rows', 'ranked', 'source', 'graphic');
        [$extension, $bytes] = match ($kind) {
            'deck' => ['pptx', app(BumiPresentation::class)->render($context)],
            'chart' => ['svg', $graphic],
            'csv' => ['csv', $this->csv($rows)],
            default => ['html', view('ai.report', $context)->render()],
        };
        $file = $this->save($employee, $title, $extension, $bytes) + ['artifact' => $kind, 'template' => $number];

        return ['reply' => $title.'. '.($kind === 'csv' ? __('Таблица CSV готова.') : __('Шаблон №').$number.' «'.$name.'».'),
            'blocks' => [$chart, $file, $source]];
    }

    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [__('Показатель'), __('Значение')], ';', '"', '');
        foreach ($rows as ['label' => $label, 'value' => $value]) {
            fputcsv($stream, [preg_match('/^[\s]*[=+@\-\t\r]/u', $label) ? "'".$label : $label, $value], ';', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }
}
