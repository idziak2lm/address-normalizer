<?php

namespace App\Console\Commands;

use App\Models\RequestLog;
use Illuminate\Console\Command;

class CleanExpiredLogs extends Command
{
    protected $signature = 'logs:clean-expired';

    protected $description = 'Delete request logs older than retention period';

    public function handle(): int
    {
        $days = config('normalizer.log_retention_days', 30);

        $deleted = RequestLog::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} expired log entries (older than {$days} days).");

        return self::SUCCESS;
    }
}
