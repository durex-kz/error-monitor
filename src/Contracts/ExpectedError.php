<?php

namespace DurexKz\ErrorMonitor\Contracts;

use Illuminate\Http\Request;

/**
 * Правило ожидаемой ошибки: такая запись пишется сразу исправленной с пояснением.
 * Подключается в config/error-monitor.php → expected
 */
interface ExpectedError
{
	/**
	 * Пояснение для поля «Решение» или null, если правило не подходит
	 */
	public function resolution(int $code, Request $request): ?string;
}
