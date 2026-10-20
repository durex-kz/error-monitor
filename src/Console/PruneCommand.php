<?php

namespace DurexKz\ErrorMonitor\Console;

use DurexKz\ErrorMonitor\ErrorMonitor;
use Illuminate\Console\Command;

/**
 * Удалить старые исправленные записи (в основном шум сканеров). Неисправленные не трогаются.
 * В routes/console.php: Schedule::command('error-monitor:prune')->daily();
 */
class PruneCommand extends Command
{
	protected $signature = 'error-monitor:prune
		{--days= : Сколько дней хранить исправленные записи (по умолчанию config error-monitor.prune_resolved_after_days)}
		{--scanners-only : Удалять только записи сканеров}';

	protected $description = 'Удалить исправленные записи журнала ошибок старше N дней';

	public function handle(): int
	{
		$days = (int) ($this->option('days') ?? config('error-monitor.prune_resolved_after_days', 90));

		if ($days < 1) {
			$this->error('--days должно быть больше 0');

			return self::FAILURE;
		}

		$deleted = ErrorMonitor::model()::query()
			->whereNotNull('resolved_at')
			->where('created_at', '<', now()->subDays($days))
			->when($this->option('scanners-only'), fn($query) => $query->where('is_scanner', true))
			->delete();

		$this->info("Удалено записей: {$deleted}");

		return self::SUCCESS;
	}
}
