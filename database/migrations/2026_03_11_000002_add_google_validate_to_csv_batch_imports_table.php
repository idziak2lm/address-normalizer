<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('csv_batch_imports', function (Blueprint $table) {
            $table->boolean('google_validate')->default(false)->after('format_variant');
        });
    }

    public function down(): void
    {
        Schema::table('csv_batch_imports', function (Blueprint $table) {
            $table->dropColumn('google_validate');
        });
    }
};
