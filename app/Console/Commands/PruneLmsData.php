<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Lms\Services\LmsRetentionService;
use Illuminate\Console\Command;

class PruneLmsData extends Command
{
    protected $signature = 'lms:prune';

    protected $description = 'Prune high-volume LMS data according to config/lms.php retention';

    public function handle(LmsRetentionService $retention): int
    {
        $result = $retention->prune();
        $total = array_sum($result);

        $this->info('LMS prune complete. Deleted: '.json_encode($result, JSON_UNESCAPED_UNICODE));

        return $total >= 0 ? self::SUCCESS : self::FAILURE;
    }
}
