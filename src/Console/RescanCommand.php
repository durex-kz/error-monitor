<?php

namespace DurexKz\ErrorMonitor\Console;

use DurexKz\ErrorMonitor\ErrorMonitor;
use DurexKz\ErrorMonitor\Models\ErrorLog;
use Illuminate\Console\Command;

/**
 * Повторная проверка журнала — после обновления правил сканеров или формата контекста
 */
class RescanCommand extends Command
{
	protected $signature = 'error-monitor:rescan
		{--context : Заново собрать место в коде, контекст Livewire и отпечаток повторов}';

	protected $description = 'Перепроверить записи журнала ошибок по текущим правилам сканеров (и пересобрать контекст)';

	public function handle(): int
	{
		$model = ErrorMonitor::model();

		if ($this->option('context')) {
			$rebuilt = 0;

			$model::query()->eachById(function (ErrorLog $log) use (&$rebuilt) {
				$log->rebuildContext();
				$log->saveQuietly();
				$rebuilt++;
			});

			$this->info("Контекст пересобран: {$rebuilt}");
		}

		if (!ErrorMonitor::hasScannerDetector()) {
			$this->warn('Распознаватель сканеров не подключён (durex-kz/bot-guard) — проверка сканеров пропущена');

			return self::SUCCESS;
		}

		$marked = 0;

		$model::query()->where('is_scanner', false)->whereNotNull('url')->eachById(function (ErrorLog $log) use (&$marked) {
			$request = $log->toRequest();

			if ($request === null || !ErrorMonitor::isScannerRequest($request, $log->code)) {
				return;
			}

			$log->is_scanner = true;
			$log->resolved_at ??= now();
			$log->saveQuietly();
			$marked++;
		});

		// ошибки с IP сканеров в окне вокруг их запросов
		$model::query()->where('is_scanner', true)->whereNotNull('ip')->eachById(function (ErrorLog $log) use ($model) {
			$model::markScannerIp($log->ip, $log->created_at);
		});

		$this->info("Помечено запросами сканеров: {$marked}");

		return self::SUCCESS;
	}
}
