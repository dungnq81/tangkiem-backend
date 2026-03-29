<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add ip_address column to page_visits for admin monitoring.
 *
 * Stores the raw IP address alongside the privacy-safe ip_hash.
 * Used by the Analytics dashboard "Top IPs" widget to identify
 * suspicious traffic patterns, bots, and abuse.
 *
 * Retention: Cleaned up with raw page_visits (30-day default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_visits', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('ip_hash')
                ->comment('Raw IP for admin monitoring (IPv4=15, IPv6=45 chars)');

            // Index for the "Top IPs" widget query: GROUP BY ip_address ORDER BY count
            $table->index(['ip_address', 'visited_at'], 'idx_visits_ip');
        });
    }

    public function down(): void
    {
        Schema::table('page_visits', function (Blueprint $table) {
            $table->dropIndex('idx_visits_ip');
            $table->dropColumn('ip_address');
        });
    }
};
