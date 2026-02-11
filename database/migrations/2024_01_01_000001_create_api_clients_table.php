<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('api_key', 64)->unique();
            $table->string('api_key_plain', 64)->nullable();
            $table->unsignedInteger('monthly_limit')->default(1000);
            $table->unsignedInteger('current_month_usage')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('preferred_provider')->default('openai');
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
