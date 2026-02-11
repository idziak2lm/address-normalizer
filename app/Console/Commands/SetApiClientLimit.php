<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;

class SetApiClientLimit extends Command
{
    protected $signature = 'api-client:set-limit {id : API client ID} {limit : New monthly limit}';

    protected $description = 'Update monthly limit for an API client';

    public function handle(): int
    {
        $client = ApiClient::find($this->argument('id'));

        if (! $client) {
            $this->error('API client not found.');

            return self::FAILURE;
        }

        $limit = (int) $this->argument('limit');
        $client->update(['monthly_limit' => $limit]);

        $this->info("Monthly limit for '{$client->name}' updated to {$limit}.");

        return self::SUCCESS;
    }
}
