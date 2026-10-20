<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'website')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->string('website', 2048)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'website')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('website');
            });
        }
    }
};
