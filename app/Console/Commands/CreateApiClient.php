<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateApiClient extends Command
{
    protected $signature = 'api-client:create
        {name : Name of the API client}
        {--limit=1000 : Monthly request limit}
        {--provider=openai : Preferred AI provider (openai or anthropic)}';

    protected $description = 'Create a new API client with a bearer token';

    public function handle(): int
    {
        $name = $this->argument('name');
        $limit = (int) $this->option('limit');
        $provider = $this->option('provider');

        if (! in_array($provider, ['openai', 'anthropic'])) {
            $this->error('Provider must be "openai" or "anthropic".');

            return self::FAILURE;
        }

        $plainToken = Str::random(64);
        $hashedToken = hash('sha256', $plainToken);

        $client = ApiClient::create([
            'name' => $name,
            'api_key' => $hashedToken,
            'api_key_plain' => null, // Never store plain token
            'monthly_limit' => $limit,
            'preferred_provider' => $provider,
        ]);

        $this->info("API client created successfully!");
        $this->table(['Field', 'Value'], [
            ['ID', $client->id],
            ['Name', $client->name],
            ['Monthly Limit', $client->monthly_limit],
            ['Provider', $client->preferred_provider],
        ]);

        $this->newLine();
        $this->warn('API Token (shown ONCE, save it now!):');
        $this->line($plainToken);

        return self::SUCCESS;
    }
}
