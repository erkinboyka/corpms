<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Элемент базы знаний Bumi: пара вопрос/ответ или загруженный файл.
 *
 * Запись всегда принадлежит компании и агенту — поиск не может «протечь»
 * в другой тенант или к другому агенту.
 */
class BumiKnowledgeItem extends Model
{
    protected $table = 'bumi_knowledge_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'meta' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(BumiAgent::class, 'agent_id');
    }

    /** Текст без HTML — для поиска и подстановки в промпт. */
    public function plainAnswer(): string
    {
        return trim(html_entity_decode(strip_tags((string) $this->answer), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function plainQuestion(): string
    {
        return trim(html_entity_decode(strip_tags((string) $this->question), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function excerpt(int $limit = 600): string
    {
        return Str::limit($this->plainAnswer(), $limit);
    }
}
