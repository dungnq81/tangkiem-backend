<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Add human-readable `label` column to roles table.
     *
     * This replaces the hardcoded RoleLabel enum approach,
     * allowing dynamic Vietnamese labels for each role
     * created through the Shield admin UI.
     */
    public function up(): void
    {
        $tableName = config('permission.table_names.roles', 'roles');

        Schema::table($tableName, function (Blueprint $table) {
            $table->string('label')->nullable()->after('name');
        });

        // Backfill existing roles with Vietnamese labels
        $labelMap = [
            'super_admin' => 'Quản trị viên cấp cao',
            'admin' => 'Quản trị viên',
            'moderator' => 'Điều hành viên',
            'panel_user' => 'Người dùng',
        ];

        $roles = DB::table($tableName)->get(['id', 'name']);

        foreach ($roles as $role) {
            $label = $labelMap[$role->name]
                ?? Str::headline($role->name);

            DB::table($tableName)
                ->where('id', $role->id)
                ->update(['label' => $label]);
        }
    }

    public function down(): void
    {
        $tableName = config('permission.table_names.roles', 'roles');

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
