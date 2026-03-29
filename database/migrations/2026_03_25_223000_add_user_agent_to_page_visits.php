<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add user_agent column to page_visits for accurate browser/OS detection.
 *
 * Stores the raw User-Agent string so the Top IPs widget can re-parse
 * browser/OS at query time (using VisitorParser) instead of relying
 * on the pre-parsed browser/os columns which may have been written
 * with an older, less comprehensive parser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_visits', function (Blueprint $table) {
            $table->string('user_agent', 512)->nullable()->after('is_bot')
                ->comment('Raw UA for browser/OS re-parsing and debugging');
        });
    }

    public function down(): void
    {
        Schema::table('page_visits', function (Blueprint $table) {
            $table->dropColumn('user_agent');
        });
    }
};
