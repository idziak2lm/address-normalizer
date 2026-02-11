<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('provider', 20)->nullable();
            $table->json('raw_input');
            $table->json('normalized_output')->nullable();
            $table->float('confidence')->nullable();
            $table->unsignedInteger('processing_time_ms');
            $table->boolean('is_successful')->default(true);
            $table->text('error_message')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->timestamp('created_at');

            $table->index(['api_client_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
