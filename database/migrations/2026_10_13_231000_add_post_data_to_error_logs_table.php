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
	        $table->text('post_data')->nullable()->comment('Тело запроса (POST/PUT/PATCH) в JSON, пароли и токены скрыты');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(config('error-monitor.table', 'error_logs'), function (Blueprint $table) {
	        $table->dropColumn('post_data');
        });
    }
};
