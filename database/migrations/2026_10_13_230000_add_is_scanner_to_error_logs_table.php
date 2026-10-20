<?php

use DurexKz\ErrorMonitor\ErrorMonitor;
use DurexKz\ErrorMonitor\Models\ErrorLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table(config('error-monitor.table', 'error_logs'), function (Blueprint $table) {
	        $table->boolean('is_scanner')->default(false)->index()->comment('Ошибка вызвана запросом сканера уязвимостей');
        });

        // уже записанные ошибки от сканеров помечаем и сразу считаем решёнными
        ErrorMonitor::model()::query()->whereNotNull('url')->eachById(function (ErrorLog $log) {
	        if (!ErrorMonitor::isScannerRequest(Request::create($log->url))) {
		        return;
	        }

	        $log->is_scanner = true;
	        $log->resolved_at ??= now();
	        $log->saveQuietly();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(config('error-monitor.table', 'error_logs'), function (Blueprint $table) {
	        $table->dropIndex(['is_scanner']);
	        $table->dropColumn('is_scanner');
        });
    }
};
