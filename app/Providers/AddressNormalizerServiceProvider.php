<?php

namespace App\Providers;

use App\Contracts\AddressParserInterface;
use App\Contracts\LlmProviderInterface;
use App\Services\AddressNormalizer;
use App\Services\CacheManager;
use App\Services\GoogleAddressValidationClient;
use App\Services\LlmProviders\AnthropicProvider;
use App\Services\LlmProviders\OpenAiProvider;
use App\Services\Parsers\LibpostalClient;
use App\Services\PostValidator;
use App\Services\PreCleaner;
use Illuminate\Support\ServiceProvider;

class AddressNormalizerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PreCleaner::class);
        $this->app->singleton(CacheManager::class);
        $this->app->singleton(PostValidator::class);
        $this->app->singleton(OpenAiProvider::class);
        $this->app->singleton(AnthropicProvider::class);
        $this->app->singleton(LibpostalClient::class);
        $this->app->singleton(GoogleAddressValidationClient::class);

        $this->app->bind(AddressParserInterface::class, LibpostalClient::class);

        $this->app->singleton(AddressNormalizer::class, function ($app) {
            $defaultProvider = config('normalizer.default_provider', 'openai');

            $primary = $defaultProvider === 'anthropic'
                ? $app->make(AnthropicProvider::class)
                : $app->make(OpenAiProvider::class);

            $fallback = $defaultProvider === 'anthropic'
                ? $app->make(OpenAiProvider::class)
                : $app->make(AnthropicProvider::class);

            $parser = config('normalizer.libpostal.enabled')
                ? $app->make(LibpostalClient::class)
                : null;

            return new AddressNormalizer(
                preCleaner: $app->make(PreCleaner::class),
                cacheManager: $app->make(CacheManager::class),
                postValidator: $app->make(PostValidator::class),
                primaryProvider: $primary,
                fallbackProvider: $fallback,
                addressParser: $parser,
                googleValidator: $app->make(GoogleAddressValidationClient::class),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
