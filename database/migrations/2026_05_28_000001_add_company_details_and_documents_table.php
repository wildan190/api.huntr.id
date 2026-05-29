<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('tax_id')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('region')->nullable();
            $table->string('provincy_country')->nullable();
            $table->string('regency')->nullable();
            $table->string('city')->nullable();
            $table->string('zip_code')->nullable();
            $table->text('address')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('bank_account_name')->nullable();
        });

        Schema::create('company_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('type');
            $table->string('file_path')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_documents');
        
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'tax_id',
                'email',
                'phone',
                'region',
                'provincy_country',
                'regency',
                'city',
                'zip_code',
                'address',
                'bank_name',
                'bank_account',
                'bank_account_name'
            ]);
        });
    }
};
