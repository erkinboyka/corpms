<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Вложение сообщения: изображение или документ.
 *
 * Хранится ссылка на файл в диске local (вне public) и метаданные.
 * Содержимое бизнес-сущности в сообщение не дублируется — задачи и
 * документы передаются ссылкой на объект, а не копией.
 */
class MessageAttachment extends Model
{
    protected $table = 'message_attachments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_image' => 'boolean',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'uploaded_by');
    }
}
