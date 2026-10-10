<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Restored PostgreSQL databases can retain a stale sequence even when
        // the roles table already contains records. Synchronize it before the
        // insert so the generated primary key cannot collide with existing data.
        DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), COALESCE((SELECT MAX(id) FROM roles), 1), (SELECT COUNT(*) > 0 FROM roles))");

        DB::table('roles')->updateOrInsert(
            ['slug' => 'warehouse_admin'],
            ['name' => 'Warehouse Admin', 'description' => 'Operates WMS & Inventory for a buyer workspace.', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'warehouse_admin')->delete();
    }
};
