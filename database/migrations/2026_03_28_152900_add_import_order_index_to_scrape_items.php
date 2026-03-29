<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add composite index for chunked import performance on 10k+ chapter_detail jobs.
 * Covers: WHERE job_id=X AND status='selected' ORDER BY sort_order
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_items', function (Blueprint $table) {
            $table->index(['job_id', 'status', 'sort_order'], 'idx_scrape_items_import_order');
        });
    }

    public function down(): void
    {
        Schema::table('scrape_items', function (Blueprint $table) {
            $table->dropIndex('idx_scrape_items_import_order');
        });
    }
};
