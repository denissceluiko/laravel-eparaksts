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
        $tableName = (new (config('eparaksts.user_model')))->getTable();

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (!Schema::hasColumn($tableName, config('eparaksts.fields.first_name'))) {
                $table->string(config('eparaksts.fields.first_name'))
                    ->nullable();
            }

            if (!Schema::hasColumn($tableName, config('eparaksts.fields.last_name'))) {
                $table->string(config('eparaksts.fields.last_name'))
                    ->after(config('eparaksts.fields.first_name'))
                    ->nullable();
            }
            
            if (!Schema::hasColumn($tableName, config('eparaksts.fields.full_name'))) {
                $table->string(config('eparaksts.fields.full_name'))
                    ->after(config('eparaksts.fields.last_name'))
                    ->nullable();
            }

            if (!Schema::hasColumn($tableName, config('eparaksts.fields.personal_number'))) {
                $table->string(config('eparaksts.fields.personal_number'))
                    ->after(config('eparaksts.fields.full_name'))
                    ->nullable()
                    ->unique();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = (new (config('eparaksts.user_model')))->getTable();

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            // We're not reversing
        });
    }
};
