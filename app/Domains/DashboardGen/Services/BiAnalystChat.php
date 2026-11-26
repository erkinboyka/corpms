<?php

declare(strict_types=1);

namespace App\Domains\DashboardGen\Services;

use App\Domains\Ai\Services\AiToolRegistry;
use App\Domains\Audit\Services\AuditService;
use App\Models\BiChatMessage;
use App\Models\BiDashboard;
use App\Models\Employee;
use App\Models\User;
use App\Support\TenantGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Диалог Bumi с текущим BI-дашбордом (команда /bi).
 *
 * Отличается от обычного AI-панеля тем, что инструменты меняют дашборд, а не
 * возвращают черновики. Дашборд фиксируется маршрутом, все изменения пишут
 * undo-снимки в сообщение и проходят tenant/Gate проверки.
 */
class BiAnalystChat
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly AiToolRegistry $tools,
        private readonly AuditService $audit,
        private readonly SchemaInspector $schemaInspector,
        private readonly WidgetCatalog $catalog,
    ) {}

    /**
     * @return array{ok: bool, id: ?int, text: string, effects: list<array<string, mixed>>, undoable: bool, error: ?string}
     */
    public function reply(User $user, BiDashboard $dashboard, string $question): array
    {
        $asker = $user->employee;
        \assert($asker instanceof Employee);

        TenantGuard::ensure($dashboard, $asker);
        Gate::authorize('update', $dashboard);

        $history = $dashboard->chatMessages()
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->reverse()
            ->map(static fn (BiChatMessage $message): array => [
                'role' => $message->role === 'assistant' ? 'assistant' : 'user',
                'content' => mb_substr((string) $message->content, 0, 1000),
            ])
            ->values()
            ->all();

        /** @var BiChatMessage $userMessage */
        $userMessage = $dashboard->chatMessages()->create([
            'company_id' => (int) $dashboard->company_id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $question,
        ]);

        $tools = $this->tools->toolsFor($asker, 'bi');
        $schema = $this->schemaInspector->schema((array) config('dashboard_gen.tables', []));

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($dashboard, $schema)],
            ...$history,
            ['role' => 'user', 'content' => $question],
        ];

        $context = new BiToolContext($dashboard);
        $effects = [];
        $text = null;
        $error = null;

        try {
            if ($tools === []) {
                throw new RuntimeException('Инструменты BI недоступны: проверьте настройки AI и права на управление дашбордом.');
            }

            $first = $this->ai->chatWithTools($messages, $tools);

            if ($first['error'] !== null) {
                throw new RuntimeException($first['error']);
            }

            $calls = array_slice($first['tool_calls'], 0, 3);

            if ($calls === []) {
                $text = $first['content'];
            } else {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $first['content'] ?? '',
                    'tool_calls' => $calls,
                ];

                foreach ($calls as $call) {
                    $name = (string) ($call['function']['name'] ?? '');
                    $decoded = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                    $arguments = is_array($decoded) ? $decoded : [];
                    $result = $this->tools->execute($asker, $name, $arguments, $context);

                    $effects[] = [
                        'tool' => $name,
                        'ok' => $result['ok'],
                        ...($result['ok']
                            ? ['result' => $result['result']]
                            : ['error' => $result['error']]),
                    ];

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => (string) ($call['id'] ?? Str::random(8)),
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE) ?: '{}',
                    ];
                }

                $second = $this->ai->chatWithTools($messages, []);
                $text = $second['error'] === null ? $second['content'] : null;
            }
        } catch (Throwable $e) {
            report($e);
            $error = $e->getMessage();
        }

        if ($text === null || trim($text) === '') {
            $summaries = [];

            foreach ($effects as $effect) {
                if (($effect['ok'] ?? false) === true && is_array($effect['result'] ?? null)) {
                    $summaries[] = (string) ($effect['result']['text'] ?? '');
                }
            }

            $text = $summaries !== []
                ? implode(' ', array_filter($summaries))
                : ($error !== null
                    ? 'Не удалось изменить дашборд: '.$error
                    : 'Не удалось подобрать изменение по вашему запросу. Уточните, какой виджет и какую метрику нужно показать.');
        }

        /** @var BiChatMessage $reply */
        $reply = $dashboard->chatMessages()->create([
            'company_id' => (int) $dashboard->company_id,
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => mb_substr(trim($text), 0, 4000),
            'payload' => [
                'effects' => $effects,
                'undo' => $context->undo,
            ],
        ]);

        $this->audit->log($user, 'bi.chat.asked', 'bi_dashboard', $dashboard->id, null, [
            'question' => mb_substr($question, 0, 500),
            'tools' => array_column($effects, 'tool'),
        ]);

        return [
            'ok' => $error === null,
            'id' => (int) $reply->id,
            'text' => (string) $reply->content,
            'effects' => $effects,
            'undoable' => $context->undo !== [],
            'error' => $error,
        ];
    }

    /**
     * Откат всех изменений, сделанных одним ответом Bumi.
     *
     * @return array{message: string, refresh: bool}
     */
    public function undo(User $user, BiDashboard $dashboard, BiChatMessage $message): array
    {
        $asker = $user->employee;
        \assert($asker instanceof Employee);

        TenantGuard::ensure($dashboard, $asker);
        Gate::authorize('update', $message);

        return DB::transaction(function () use ($user, $dashboard, $message): array {
            /** @var BiChatMessage $locked */
            $locked = BiChatMessage::query()
                ->whereKey($message->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->undone_at !== null) {
                throw ValidationException::withMessages(['message' => __('Этот ответ уже отменён.')]);
            }

            $undos = array_reverse(is_array($locked->payload['undo'] ?? null) ? $locked->payload['undo'] : []);

            foreach ($undos as $undo) {
                $this->revert($dashboard, is_array($undo) ? $undo : []);
            }

            if ($undos !== []) {
                $dashboard->increment('version');
            }

            $locked->forceFill(['undone_at' => now()])->save();

            $this->audit->log($user, 'bi.chat.undone', 'bi_dashboard', $dashboard->id, null, [
                'message_id' => $locked->id,
                'operations' => count($undos),
            ]);

            return ['message' => 'Изменения Bumi отменены.', 'refresh' => true];
        });
    }

    /** @param array<string, mixed> $undo */
    private function revert(BiDashboard $dashboard, array $undo): void
    {
        $kind = (string) ($undo['kind'] ?? '');

        if ($kind === 'delete_widget') {
            $dashboard->widgets()->whereKey((int) ($undo['widget_id'] ?? 0))->delete();

            return;
        }

        if ($kind === 'restore_widget') {
            $widget = $dashboard->widgets()->whereKey((int) ($undo['widget_id'] ?? 0))->first();

            if ($widget !== null && is_array($undo['attributes'] ?? null)) {
                $widget->forceFill($undo['attributes'])->save();
            }

            return;
        }

        if ($kind === 'restore_filters') {
            $filters = is_array($undo['filters'] ?? null) ? $undo['filters'] : [];

            if (($undo['target'] ?? 'dashboard') === 'widget') {
                $widget = $dashboard->widgets()->whereKey((int) ($undo['widget_id'] ?? 0))->first();
                if ($widget !== null) {
                    $widget->forceFill(['filters' => $filters])->save();
                }

                return;
            }

            $dashboard->forceFill(['filters' => $filters])->save();
        }
    }

    /** @return array{ok: bool, rating: int} */
    public function rate(User $user, BiChatMessage $message, int $rating): array
    {
        $asker = $user->employee;
        \assert($asker instanceof Employee);

        TenantGuard::ensureRelated($message, $asker, ['dashboard']);
        Gate::authorize('rate', $message);

        $message->forceFill(['rating' => $rating])->save();

        $this->audit->log($user, 'bi.chat.rated', 'bi_chat_message', $message->id, null, [
            'rating' => $rating,
        ]);

        return ['ok' => true, 'rating' => $rating];
    }

    /**
     * @param  array<string, array<string, array{type: string, samples?: list<string>}>>  $schema
     */
    private function systemPrompt(BiDashboard $dashboard, array $schema): string
    {
        $widgets = $dashboard->widgets()
            ->orderBy('position')
            ->get(['id', 'title', 'widget_family', 'widget_type'])
            ->map(static fn ($widget): array => [
                'id' => (int) $widget->id,
                'title' => (string) $widget->title,
                'family' => $widget->widget_family,
                'type' => $widget->widget_type,
            ])
            ->all();

        $schemaText = Str::limit($this->schemaInspector->describe($schema), 6000, '');
        $catalogText = implode(', ', $this->catalog->names());

        return "Ты Bumi, BI-аналитик текущего дашборда «{$dashboard->name}». "
            ."Отвечай на языке пользователя, коротко и только по данным схемы.\n"
            ."Для изменений дашборда обязательно вызывай инструменты add_widget, update_widget или set_filter. "
            ."Никогда не пиши SQL в тексте ответа и не выдумывай значения.\n"
            ."spec — структурированная спецификация, а не SQL: {\"queries\":{\"main\":{"
            ."\"from\":\"crm_clients\",\"alias\":\"c\",\"select\":["
            ."{\"expr\":\"count\",\"as\":\"value\"}]}},\"shape\":\"counters\",\"presentation\":{}}.\n"
            ."Доступные expr: column, count, sum, avg, min, max, month, year, date, literal. "
            ."Операции в where: =, !=, >, >=, <, <=, like, in, not in, is null, is not null. "
            ."П company_id платформа фильтрует сама — не подставляй его вручную.\n"
            ."Семейства виджетов: {$catalogText}. Форма по семейству: mini-counters/table/pie/radial/funnel/treemap → "
            ."counters/rows/series_values; bar/line/radar/combo/heatmap → series_matrix; scatter → points.\n"
            ."Текущие виджеты: ".json_encode($widgets, JSON_UNESCAPED_UNICODE)."\n"
            ."Схема таблиц:\n{$schemaText}\n"
            ."Если данных не хватает — объясни, не вызывай инструмент с выдуманной колонкой.";
    }
}
