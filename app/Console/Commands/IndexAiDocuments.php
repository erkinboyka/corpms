<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Ai\Services\DocumentRagService;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * D4 (RAG): массовая индексация документов для AI-поиска.
 *
 * Без --company индексируются все документы всех компаний. Чанки без
 * embeddings создаются, если провайдер /embeddings недоступен (keyword-режим).
 */
class IndexAiDocuments extends Command
{
    protected $signature = 'ai:index-documents
        {--company= : Ограничить индексацию одной компанией (id)}
        {--force : Принудительно поднять поколение индекса и переиндексировать все документы}';

    protected $description = 'Проиндексировать документы для AI-поиска (RAG): чанки, вложения + embeddings';

    public function handle(DocumentRagService $rag): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;

        if ($companyId !== null && ! Company::query()->whereKey($companyId)->exists()) {
            $this->error("Компания #{$companyId} не найдена");

            return self::INVALID;
        }

        $chunks = $rag->reindexAll($companyId, (bool) $this->option('force'));

        $this->info($chunks > 0
            ? "Индексация завершена, чанков: {$chunks}"
            : 'Документы для индексации не найдены (или у документов пустой текст)');

        return self::SUCCESS;
    }
}
