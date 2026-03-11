<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csv_batch_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('format_variant', 20); // minimal, standard, full
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->string('status', 20)->default('pending'); // pending, processing, completed, failed
            $table->text('error_message')->nullable();
            $table->string('result_filename')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['api_client_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_batch_imports');
    }
};
