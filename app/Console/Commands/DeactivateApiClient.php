<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;

class DeactivateApiClient extends Command
{
    protected $signature = 'api-client:deactivate {id : API client ID}';

    protected $description = 'Deactivate an API client';

    public function handle(): int
    {
        $client = ApiClient::find($this->argument('id'));

        if (! $client) {
            $this->error('API client not found.');

            return self::FAILURE;
        }

        $client->update(['is_active' => false]);

        $this->info("API client '{$client->name}' has been deactivated.");

        return self::SUCCESS;
    }
}
