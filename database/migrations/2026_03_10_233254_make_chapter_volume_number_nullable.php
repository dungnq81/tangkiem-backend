<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make volume_number nullable — null means "no volume" (single-volume story).
     * Previously defaulted to 1, which incorrectly showed "Quyển 1" even when
     * the story has no volume structure.
     */
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->unsignedTinyInteger('volume_number')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse: restore non-nullable with default 1.
     */
    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->unsignedTinyInteger('volume_number')->default(1)->change();
        });
    }
};
