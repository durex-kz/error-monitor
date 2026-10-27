<?php

namespace DurexKz\ErrorMonitor\Expected;

use DurexKz\ErrorMonitor\Contracts\ExpectedError;
use Illuminate\Http\Request;

/**
 * 503 во время режима обслуживания (php artisan down при деплое): открытые вкладки панели продолжают
 * делать запросы Livewire и получают 503. Не ошибка приложения. Другие 503 (сбой внешнего сервиса и т. п.)
 * правило не трогает — только пока режим обслуживания действительно включён
 */
class MaintenanceMode implements ExpectedError
{
	public function resolution(int $code, Request $request): ?string
	{
		if ($code !== 503 || !app()->isDownForMaintenance()) {
			return null;
		}

		return 'Не ошибка приложения: запрос пришёл во время режима обслуживания (php artisan down при деплое), 503 — правильный ответ. Записано сразу исправленным.';
	}
}
