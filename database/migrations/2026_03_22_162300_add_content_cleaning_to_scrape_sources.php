<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_sources', function (Blueprint $table) {
            $table->text('remove_selectors')->nullable()
                ->comment('Global CSS selectors to remove from chapter content (newline-separated). Merged with per-job settings.');
            $table->text('remove_text_patterns')->nullable()
                ->comment('Global text patterns to remove from chapter content (newline-separated). Merged with per-job settings.');
        });
    }

    public function down(): void
    {
        Schema::table('scrape_sources', function (Blueprint $table) {
            $table->dropColumn(['remove_selectors', 'remove_text_patterns']);
        });
    }
};
