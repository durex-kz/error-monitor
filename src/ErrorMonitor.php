<?php

namespace DurexKz\ErrorMonitor;

use Closure;
use DurexKz\ErrorMonitor\Contracts\ExpectedError;
use DurexKz\ErrorMonitor\Models\ErrorLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Throwable;

/**
 * Точка входа журнала ошибок: подключение к обработчику исключений, запись, распознавание сканеров
 *
 * bootstrap/app.php:
 *   ->withExceptions(function (Exceptions $exceptions) {
 *       ErrorMonitor::handle($exceptions);
 *   })
 */
class ErrorMonitor
{
	/**
	 * Распознаватель запросов сканеров: fn (Request $request, ?int $code): bool.
	 * Регистрирует durex-kz/bot-guard; без него журнал сканеров не различает (кроме серии с того же IP)
	 */
	protected static ?Closure $scannerDetector = null;

	/**
	 * Подключить журнал к обработчику исключений приложения
	 */
	public static function handle(Exceptions $exceptions): void
	{
		// код берём из итогового ответа, поэтому сюда попадают и 404/403, которые Laravel не передаёт в report()
		$exceptions->respond(function (Response $response, Throwable $e, Request $request) {
			if (static::shouldRecord($response->getStatusCode(), $request)) {
				static::record($response->getStatusCode(), $e, $request);
			}

			return $response;
		});

		// вне HTTP-запроса (очереди, artisan, планировщик) ответа нет — пишем как 500.
		// В автотестах всё «в консоли», и HTTP-ошибка записалась бы дважды — поэтому там не пишем
		$exceptions->report(function (Throwable $e) {
			if (app()->runningInConsole() && !app()->runningUnitTests()
				&& config('error-monitor.log_console', true) && config('error-monitor.enabled', true)) {
				static::record(500, $e);
			}
		});
	}

	/**
	 * Запись удалена (soft delete), а не отсутствует: 410 Gone вместо 404 — поисковые роботы убирают
	 * такой адрес из индекса и перестают его запрашивать, а на 404 возвращаются месяцами
	 */
	public static function mapSoftDeletedToGone(Exceptions $exceptions): void
	{
		$exceptions->map(function (ModelNotFoundException $e) {
			$model = $e->getModel();

			if ($model && $e->getIds() && in_array(SoftDeletes::class, class_uses_recursive($model), true)
				&& $model::onlyTrashed()->whereKey($e->getIds())->exists()) {
				return new GoneHttpException($e->getMessage(), $e);
			}

			return $e;
		});
	}

	public static function shouldRecord(int $code, Request $request): bool
	{
		return $code >= 400
			&& !in_array($code, config('error-monitor.ignore_codes', [419, 422]), true)
			&& !Str::is(config('error-monitor.ignore_hosts', []), $request->getHost())
			&& static::enabledInEnvironment();
	}

	protected static function enabledInEnvironment(): bool
	{
		return config('error-monitor.enabled', true)
			&& (!app()->runningUnitTests() || config('error-monitor.log_in_tests', false));
	}

	/**
	 * Записать ошибку в журнал. Сбой записи не порождает нового исключения
	 */
	public static function record(int $code, Throwable $e, ?Request $request = null): void
	{
		static::model()::register($code, $e, $request);
	}

	/**
	 * @return class-string<ErrorLog>
	 */
	public static function model(): string
	{
		return config('error-monitor.model', ErrorLog::class);
	}

	/**
	 * @return class-string<\Illuminate\Database\Eloquent\Model>
	 */
	public static function userModel(): string
	{
		return config('error-monitor.user_model') ?: config('auth.providers.users.model', 'App\\Models\\User');
	}

	public static function detectScannersUsing(?callable $detector): void
	{
		static::$scannerDetector = $detector ? Closure::fromCallable($detector) : null;
	}

	public static function hasScannerDetector(): bool
	{
		return static::$scannerDetector !== null;
	}

	public static function isScannerRequest(Request $request, ?int $code = null): bool
	{
		try {
			return static::$scannerDetector !== null && (bool) (static::$scannerDetector)($request, $code);
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * Пояснение для ожидаемой ошибки (config error-monitor.expected) или null
	 */
	public static function expectedResolution(int $code, ?Request $request): ?string
	{
		if ($request === null) {
			return null;
		}

		foreach (config('error-monitor.expected', []) as $rule) {
			if (is_string($rule) && is_a($rule, ExpectedError::class, true)) {
				$resolution = app($rule)->resolution($code, $request);
			} elseif (is_array($rule)) {
				$resolution = ((int) ($rule['code'] ?? 0) === $code || in_array($code, (array) ($rule['codes'] ?? []), true))
				&& $request->is(...(array) ($rule['paths'] ?? []))
					? ($rule['resolution'] ?? null)
					: null;
			} else {
				$resolution = null;
			}

			if (filled($resolution)) {
				return $resolution;
			}
		}

		return null;
	}

	/**
	 * Пользователь — администратор журнала (gate из config error-monitor.admin_gate)
	 */
	public static function isAdmin($user): bool
	{
		$gate = config('error-monitor.admin_gate');

		return $user !== null && filled($gate) && Gate::has($gate) && Gate::forUser($user)->allows($gate);
	}
}
