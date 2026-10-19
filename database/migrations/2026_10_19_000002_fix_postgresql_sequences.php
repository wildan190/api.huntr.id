<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fixes PostgreSQL sequence values that may be out of sync with max IDs (e.g. after data dumps or imports).
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $columns = DB::select("
            SELECT table_name, column_name, column_default
            FROM information_schema.columns
            WHERE table_schema = 'public' 
              AND column_default LIKE 'nextval(%'
        ");

        foreach ($columns as $col) {
            $tableName = $col->table_name;
            $columnName = $col->column_name;

            if (preg_match("/'([^']+)'/", $col->column_default, $matches)) {
                $sequenceName = $matches[1];
            } else {
                continue;
            }

            try {
                $maxId = (int) DB::table($tableName)->max($columnName);
                if ($maxId > 0) {
                    DB::statement("SELECT setval(?, ?, true)", [$sequenceName, $maxId]);
                } else {
                    DB::statement("SELECT setval(?, 1, false)", [$sequenceName]);
                }
            } catch (\Throwable) {
                // Ignore if sequence cannot be updated
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down migration needed for sequence calibration
    }
};
