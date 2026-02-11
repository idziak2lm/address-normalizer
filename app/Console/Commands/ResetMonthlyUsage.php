<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;

class ResetMonthlyUsage extends Command
{
    protected $signature = 'usage:reset-monthly';

    protected $description = 'Reset monthly usage counters for all API clients';

    public function handle(): int
    {
        $updated = ApiClient::query()->update(['current_month_usage' => 0]);

        $this->info("Reset monthly usage for {$updated} API clients.");

        return self::SUCCESS;
    }
}
