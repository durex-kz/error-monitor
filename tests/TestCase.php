<?php

namespace DurexKz\ErrorMonitor\Tests;

use DurexKz\ErrorMonitor\ErrorMonitor;
use DurexKz\ErrorMonitor\ErrorMonitorServiceProvider;
use DurexKz\ErrorMonitor\Tests\Fixtures\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
	use RefreshDatabase;

	protected function getPackageProviders($app): array
	{
		return [ErrorMonitorServiceProvider::class];
	}

	protected function defineEnvironment($app): void
	{
		$app['config']->set('database.default', 'testing');
		$app['config']->set('error-monitor.log_in_tests', true);
		$app['config']->set('error-monitor.user_model', User::class);
	}

	protected function defineDatabaseMigrations(): void
	{
		Schema::create('users', function (Blueprint $table) {
			$table->id();
			$table->string('name');
			$table->boolean('is_admin')->default(false);
			$table->timestamps();
		});
	}

	protected function setUp(): void
	{
		parent::setUp();

		ErrorMonitor::detectScannersUsing(null);
		ErrorMonitor::handle(new Exceptions($this->app->make(ExceptionHandler::class)));
	}
}
