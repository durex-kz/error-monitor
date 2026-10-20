<?php

namespace DurexKz\ErrorMonitor;

use DurexKz\ErrorMonitor\Console\PruneCommand;
use DurexKz\ErrorMonitor\Console\RescanCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ErrorMonitorServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		$this->mergeConfigFrom(__DIR__ . '/../config/error-monitor.php', 'error-monitor');
	}

	public function boot(): void
	{
		if (config('error-monitor.run_migrations', true)) {
			$this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
		}

		if ($policy = config('error-monitor.policy')) {
			Gate::policy(ErrorMonitor::model(), $policy);
		}

		if ($this->app->runningInConsole()) {
			$this->publishes([
				__DIR__ . '/../config/error-monitor.php' => config_path('error-monitor.php'),
			], 'error-monitor-config');

			$this->publishes([
				__DIR__ . '/../database/migrations' => database_path('migrations'),
			], 'error-monitor-migrations');

			$this->commands([
				RescanCommand::class,
				PruneCommand::class,
			]);
		}
	}
}
