<?php

use DurexKz\ErrorMonitor\ErrorMonitor;
use DurexKz\ErrorMonitor\Models\ErrorLog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Повторная проверка старых записей по расширенным правилам ErrorMonitor::isScannerRequest()
     * (User-Agent сканеров, обращение по IP, инъекции в адресе, пустой User-Agent + 404/405 и т. д.),
     * затем — ошибки с IP сканеров в окне ±scanner_ip_window_minutes вокруг их запросов
     */
    public function up(): void
    {
        ErrorMonitor::model()::query()->where('is_scanner', false)->whereNotNull('url')->eachById(function (ErrorLog $log) {
	        $request = $log->toRequest();

	        if ($request === null || !ErrorMonitor::isScannerRequest($request, $log->code)) {
		        return;
	        }

	        $log->is_scanner = true;
	        $log->resolved_at ??= now();
	        $log->saveQuietly();
        });

        ErrorMonitor::model()::query()->where('is_scanner', true)->whereNotNull('ip')->eachById(function (ErrorLog $log) {
	        ErrorMonitor::model()::markScannerIp($log->ip, $log->created_at);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // какие записи были помечены этой миграцией, а какие раньше — не различить, поэтому откат не нужен
    }
};
