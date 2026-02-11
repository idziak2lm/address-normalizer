<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;

class ListApiClients extends Command
{
    protected $signature = 'api-client:list';

    protected $description = 'List all API clients';

    public function handle(): int
    {
        $clients = ApiClient::all();

        if ($clients->isEmpty()) {
            $this->info('No API clients found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Active', 'Provider', 'Limit', 'Used', 'Remaining'],
            $clients->map(fn (ApiClient $c) => [
                $c->id,
                $c->name,
                $c->is_active ? 'Yes' : 'No',
                $c->preferred_provider,
                $c->monthly_limit,
                $c->current_month_usage,
                $c->remainingQuota(),
            ])
        );

        return self::SUCCESS;
    }
}
