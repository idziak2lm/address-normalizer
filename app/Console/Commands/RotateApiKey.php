<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RotateApiKey extends Command
{
    protected $signature = 'api-client:rotate-key {id : API client ID}';

    protected $description = 'Generate a new API token for a client';

    public function handle(): int
    {
        $client = ApiClient::find($this->argument('id'));

        if (! $client) {
            $this->error('API client not found.');

            return self::FAILURE;
        }

        $plainToken = Str::random(64);
        $hashedToken = hash('sha256', $plainToken);

        $client->update(['api_key' => $hashedToken]);

        // Delete all existing Sanctum tokens
        $client->tokens()->delete();

        $this->info("API key rotated for client: {$client->name}");
        $this->newLine();
        $this->warn('New API Token (shown ONCE, save it now!):');
        $this->line($plainToken);

        return self::SUCCESS;
    }
}
