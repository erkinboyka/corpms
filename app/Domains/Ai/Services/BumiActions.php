<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Models\DocumentCategory;
use App\Models\DocumentSignatureRequest;
use App\Models\Employee;
use App\Support\PermissionService;

class BumiActions
{
    public function __construct(private PermissionService $permissions, private BumiFiles $files) {}

    /** Interpret a model's suggestion using the same operations and permissions as explicit commands. */
    public function fromOperation(Employee $me, array $operation, string $question, array $facts, callable $writer): ?array
    {
        $subject = is_string($operation['subject'] ?? null) ? mb_substr(trim($operation['subject']), 0, 500) : '';
        $command = match ($operation['kind'] ?? null) {
            'signature' => 'Подпиши документ '.$subject,
            'task' => 'Создай задачу '.$subject,
            'document' => 'Подготовь документ '.$subject,
            'leave' => 'Заполни заявку на отпуск '.$question,
            'presentation', 'deck' => 'Создай презентацию '.$question,
            'chart' => 'Построй диаграмму '.$question,
            'csv' => 'Выгрузи CSV '.$question,
            'report' => 'Подготовь отчёт '.$question,
            'text_file' => 'Создай файл '.$question,
            'image' => preg_match('/рису|рисов|картин|изображ|иллюстр|постер|баннер|логотип/iu', $question) ? 'Нарисуй '.$question : null,
            default => null,
        };

        return $command === null ? null : $this->prepare($me, $command, $facts, $writer);
    }

    /** Local operations return typed artifacts. Only reviewed forms reach business write routes. */
    public function prepare(Employee $me, string $question, array $facts, callable $writeText): ?array
    {
        $q = trim(preg_replace('/^(?:(?:буми|bumi|пожалуйста|можешь|можно|помоги мне|прошу)[\s,:]+)+/iu', '', trim($question)));
        if (preg_match('/^(?:подпиши(?:те)?|подписать|хочу подписать|покажи документы на подпись|что (?:у меня )?на подпись)\b(.*)$/iu', $q, $m)) {
            $filter = trim(preg_replace('/^(?:мне\s+)?(?:этот\s+)?(?:документ(?:ы)?|договор)?\s*/iu', '', trim($m[1])), " \t:№«»\"?!.");
            return $this->signatures($me, $filter);
        }

        if (preg_match('/^(?:создай(?:те)?|создать|сделай|сделать|составь|сформируй|подготовь|напиши|нарисуй|построй|покажи|заполни|заполнить|оформи|оформить|добавь|заведи|выгрузи|скачай|сгенерируй|нуж(?:ен|на|но)|хочу)\s+(.+)$/ius', $q, $m) !== 1) {
            return null;
        }
        $object = trim($m[1]);
        if (!preg_match('/^покажи\s+(?:мне\s+)?(?:раздел|список)\b|^покажи\s+график\s+(?:работы|смен)/iu', $q)
            && !preg_match('/^(?:форму\s+)?(?:задачу|документ|служебную записку|приказ|файл|заявку)\b/iu', $object)
            && ($artifact = app(BumiArtifacts::class)->prepare($me, $object, $facts)) !== null) {
            return $artifact;
        }
        if (preg_match('/(?:заявк|заявлени|форму|отпуск)\w*.*(?:отпуск|отгул)|^(?:отпуск|отгул)\b/iu', $object) && !preg_match('/отч[её]т|таблиц|диаграмм/iu', $object)) {
            if (! $this->allowed($me, 'leaves.request')) {
                return ['reply' => __('У вашей роли нет права подавать заявки на отсутствие.'), 'blocks' => []];
            }
            $dates = $this->dates($object);
            preg_match('/(?:причина|комментарий|по причине)\s*[:—-]?\s*(.+)$/iu', $object, $reason);
            return ['reply' => __('Проверьте даты вашей заявки. После отправки она попадёт в график согласования.'), 'blocks' => [[
                'type' => 'leave_draft', 'reason' => $reason[1] ?? '', 'start' => $dates[0] ?? '', 'end' => $dates[1] ?? $dates[0] ?? '',
                'kind' => mb_stripos($object, __('отгул')) !== false ? 'day_off' : 'vacation',
            ]]];
        }
        if (preg_match('/^(?:мне\s+)?(?:картинк\w*|изображени\w*|иллюстраци\w*|баннер\w*|постер\w*|логотип\w*)\b/iu', $object) || preg_match('/^нарисуй\b/iu', $q) && !preg_match('/диаграмм|график/iu', $object)) {
            return $this->files->illustration($me, $q);
        }
        if (preg_match('/^(?:мне\s+)?(?:форму\s+)?(?:новую\s+)?задач[уаыи]\b(.*)$/ius', $object, $m)) {
            if (! $this->allowed($me, 'tasks.create')) {
                return ['reply' => __('У вашей роли нет права создавать задачи.'), 'blocks' => []];
            }
            $title = trim($m[1], " :.");
            $deadline = $this->dates($title)[0] ?? null;
            if (preg_match('/[«"]([^»"]+)[»"]/u', $title, $name)) {
                $title = $name[1];
            }
            $assignee = $me->id;
            if (preg_match('/(?:исполнитель|назначь на|исполнителю)\s*[:—-]?\s*([^,;\n]+)/iu', $object, $who)) {
                $name = trim(preg_split('/\s+(?:на завтра|до |со сроком|приоритет|срок)/iu', $who[1])[0], " «»\".");
                $matches = array_filter($this->taskAssignees($me), static function ($label) use ($name) {
                    $parts = explode(' ', mb_strtolower($label));
                    $needle = mb_strtolower($name);
                    return in_array($needle, [...$parts, implode(' ', $parts), implode(' ', array_reverse($parts))], true);
                });
                $assignee = count($matches) === 1 ? array_key_first($matches) : null;
            }
            $priority = match (true) {
                (bool)preg_match('/критическ/iu', $object) => 'critical',
                (bool)preg_match('/срочн|высок(?:ий|им) приоритет|приоритет\s*[:—-]?\s*высок/iu', $object) => 'high',
                (bool)preg_match('/низк(?:ий|им) приоритет|приоритет\s*[:—-]?\s*низк/iu', $object) => 'low',
                default => 'medium',
            };

            return ['reply' => 'Подготовил карточку задачи. Проверьте название, срок и приоритет перед созданием.', 'blocks' => [[
                'type' => 'task_draft', 'assignee' => $assignee, 'title' => mb_substr($title, 0, 255), 'deadline' => $deadline, 'priority' => $priority,
            ]]];
        }

        if (preg_match('/^(?:мне\s+)?(?:форму\s+)?(?:служебн\w*\s+записк\w*|документ\w*|приказ\w*|заявлени\w*|договор\w*|справк\w*)\b/iu', $object, $m)) {
            if (! $this->allowed($me, 'documents.create')) {
                return ['reply' => __('У вашей роли нет права создавать документы.'), 'blocks' => []];
            }
            $categories = DocumentCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
            if (! $categories) {
                // Фолбэк на детерминированный черновик AiPanelService (текст + ссылки).
                return null;
            }
            $subject = trim(mb_substr($object, mb_strlen($m[0])), " :«»\".");
            $title = $subject !== '' ? $subject : \Illuminate\Support\Str::ucfirst($m[0]);
            $body = $subject === '' ? '' : $writeText('Напиши только текст черновика документа. Не выдумывай имена, даты и обязательства. Запрос: '.$q);

            return ['reply' => $body ? __('Подготовил текст и заполнил форму. Проверьте документ перед сохранением.') : 'Открыл форму документа. Проверьте заголовок, дополните текст и выберите категорию.', 'blocks' => [[
                'type' => 'document_draft', 'title' => mb_substr($title, 0, 255), 'body' => $body ?: $subject, 'categories' => $categories,
            ]]];
        }

        if (preg_match('/^(?:текстовый\s+)?(?:файл|txt)\b(.*)$/ius', $object, $m)) {
            $brief = trim($m[1], " :");
            if ($brief === '') {
                return ['reply' => 'Какой текст записать? Например, «Создай файл с текстом: План встречи на пятницу».', 'blocks' => []];
            }
            $literal = preg_match('/^(?:с текстом|текст|с содержимым)\s*:\s*(.+)$/ius', $brief, $m);
            $content = $literal ? $m[1] : $writeText('Составь содержание текстового файла по заданию: '.$brief);
            if (! $content) {
                return ['reply' => 'Для сочинения текста нужна подключённая языковая модель. Чтобы сохранить готовый текст, напишите «Создай файл с текстом: …».', 'blocks' => []];
            }

            return ['reply' => __('Текстовый файл готов.'), 'blocks' => [$this->files->save($me, __('Текстовый файл'), 'txt', $content)]];
        }

        return null;
    }

    public function taskAssignees(Employee $me): array
    {
        $scope = $this->permissions->can($me->user, 'employees.view');
        $people = Employee::query()->where('company_id', $me->company_id)->where('status', 'active');
        if ($scope === \App\Shared\Enums\PermissionScope::Department && $me->department_id !== null) {
            $people->where('department_id', $me->department_id);
        } elseif ($scope !== \App\Shared\Enums\PermissionScope::Company) {
            $people->whereKey($me->id);
        }
        return $people->orderBy('last_name')->get(['id','first_name','last_name'])
            ->mapWithKeys(fn ($p) => [$p->id => $p->last_name.' '.$p->first_name])->all();
    }

    private function allowed(Employee $me, string $permission): bool
    {
        return $me->user !== null && (bool) $this->permissions->check($me->user, $permission);
    }

    private function dates(string $text): array
    {
        preg_match_all('/\b(?:\d{4}-\d{2}-\d{2}|\d{1,2}\.\d{1,2}\.\d{4}|сегодня|завтра|послезавтра)\b/iu', mb_strtolower($text), $matches);
        $dates = [];
        foreach ($matches[0] as $date) {
            if (in_array($date, [__('сегодня'),__('завтра'),__('послезавтра')], true)) {
                $dates[] = today()->addDays(array_search($date, [__('сегодня'),__('завтра'),__('послезавтра')], true))->toDateString();
                continue;
            }
            $parts = str_contains($date, '.') ? array_reverse(explode('.', $date)) : explode('-', $date);
            [$year, $month, $day] = array_map('intval', $parts);
            if (checkdate($month, $day, $year)) {
                $dates[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        return $dates;
    }

    private function signatures(Employee $me, string $filter): array
    {
        if (! $this->allowed($me, 'signatures.sign')) {
            return ['reply' => __('Ваша роль не даёт права подписывать документы.'), 'blocks' => []];
        }
        $requests = DocumentSignatureRequest::query()->with(['document', 'documentVersion'])
            ->where('signer_id', $me->id)->whereIn('status', ['pending', 'partial'])
            ->whereHas('document', function ($query) use ($me, $filter) {
                $query->where('company_id', $me->company_id)->whereIn('status', ['approved', 'signing']);
                if ($filter !== '') {
                    $query->where(fn ($q) => $q->where('document_number', $filter)->orWhere('title', 'like', '%'.$filter.'%'));
                }
            })->latest('id')->limit(10)->get();

        if ($requests->isEmpty()) {
            return ['reply' => $filter === '' ? __('Сейчас у вас нет документов, ожидающих вашей подписи.') : 'Среди запросов на вашу подпись такой документ не найден. Напишите «Подпиши документ», чтобы увидеть весь список.', 'blocks' => []];
        }
        $blocks = [];
        foreach ($requests as $request) {
            $doc = $request->document;
            $blocks[] = [
                'type' => 'signature', 'title' => $doc->title, 'number' => $doc->document_number,
                'document' => $doc->id, 'request' => $request->id, 'version' => $request->documentVersion?->version,
                'otp' => $request->requires_otp, 'internal' => $request->driver->value === 'internal',
            ];
        }
        return ['reply' => 'Вот документы, ожидающие вашей подписи. Проверьте название и версию, затем подтвердите подпись нужного документа.', 'blocks' => $blocks];
    }
}
