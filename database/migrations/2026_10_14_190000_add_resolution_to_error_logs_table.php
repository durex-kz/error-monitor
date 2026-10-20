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
        Schema::table(config('error-monitor.table', 'error_logs'), function (Blueprint $table) {
	        $table->text('resolution')->nullable()->comment('Решение: причина ошибки и что сделано для исправления');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(config('error-monitor.table', 'error_logs'), function (Blueprint $table) {
	        $table->dropColumn('resolution');
        });
    }
};
