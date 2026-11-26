<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\ScopesActiveCompany;
use App\Support\Concerns\StampsCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сообщение Bumi в контексте BI-дашборда.
 *
 * payload: effects (результаты tool calls) и undo (снимки для отката).
 * rating: 1–5, ставится через endpoint обратной связи.
 */
class BiChatMessage extends Model
{
    use ScopesActiveCompany;
    use StampsCompany;

    protected $table = 'bi_chat_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'rating' => 'integer',
            'undone_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(BiDashboard::class, 'dashboard_id');
    }
}
