<?php

return [

	/*
	| Писать ли ошибки в журнал. Выключение не отменяет миграции и панель — только запись
	*/
	'enabled' => env('ERROR_MONITOR_ENABLED', true),

	/*
	| Таблица и модель журнала. Модель можно расширить (class ErrorLog extends \DurexKz\ErrorMonitor\Models\ErrorLog)
	*/
	'table' => 'error_logs',

	'model' => \DurexKz\ErrorMonitor\Models\ErrorLog::class,

	/*
	| Модель пользователя для связей user/resolver. null — auth.providers.users.model
	*/
	'user_model' => null,

	/*
	| Gate, который пускает к журналу (смотреть, отмечать исправленным, удалять). Определяется в приложении:
	| Gate::define('security-admin', fn (User $user) => $user->isGlobalAdmin());
	| Если gate не определён — доступа нет ни у кого
	*/
	'admin_gate' => 'security-admin',

	/*
	| Политика модели. null — не регистрировать (приложение регистрирует свою)
	*/
	'policy' => \DurexKz\ErrorMonitor\Policies\ErrorLogPolicy::class,

	/*
	| Коды, которые не пишутся: 422 — ошибки валидации форм, 419 — истёкшая сессия/CSRF-токен
	| (вкладка открыта после выхода или долгого простоя, особенно с wire:poll): это не сбой системы
	*/
	'ignore_codes' => [419, 422],

	/*
	| Хосты, ошибки которых не пишутся (шаблоны Str::is): локальная разработка в Herd/Valet
	*/
	'ignore_hosts' => ['*.test'],

	/*
	| Сбои вне HTTP-запроса (очереди, artisan, планировщик) пишутся как 500
	*/
	'log_console' => true,

	/*
	| Автотесты намеренно вызывают 404/403/410 и часто работают с базой площадки — по умолчанию не пишем
	*/
	'log_in_tests' => false,

	/*
	| Коды, которые сразу пишутся исправленными: 410 — запрос удалённой записи (обычно поисковый робот
	| по старому адресу), исправлять нечего
	*/
	'auto_resolve_codes' => [410],

	/*
	| Ожидаемые ошибки: пишутся сразу исправленными с пояснением в «Решении».
	| Элемент — массив [code, paths (шаблоны Request::is), resolution] или класс,
	| реализующий \DurexKz\ErrorMonitor\Contracts\ExpectedError
	*/
	'expected' => [
		[
			// Google, Chrome, рекламные и ИИ-роботы проверяют стандартные служебные файлы
			// (assetlinks.json, traffic-advice, apple-app-site-association…); сайту они не нужны
			'code' => 404,
			'paths' => ['.well-known/*'],
			'resolution' => 'Не ошибка приложения: робот проверял стандартный служебный файл /.well-known/…, сайту он не нужен, 404 — правильный ответ. Записано сразу исправленным.',
		],
	],

	/*
	| Сколько минут до и после запроса сканера остальные ошибки с того же IP тоже считаются сканерными.
	| Окно короткое: за общим IP (мобильный оператор, офис) могут быть и обычные пользователи
	*/
	'scanner_ip_window_minutes' => 60,

	/*
	| Каталоги кода приложения: первое место в них — «Место в коде». Посредники пропускаются —
	| они есть в стеке почти любой ошибки и места не указывают
	*/
	'app_paths' => ['app', 'resources', 'routes', 'database'],

	'skip_app_paths' => ['app/Http/Middleware/'],

	/*
	| Поля POST-данных, значения которых маскируются (регулярное выражение по имени поля)
	*/
	'sensitive_keys' => 'pass|token|secret|api_?key|authorization|card|cvv|cvc',

	/*
	| error-monitor:prune — сколько дней хранить исправленные записи
	*/
	'prune_resolved_after_days' => 90,

	/*
	| Подключать миграции пакета автоматически. false — опубликовать (vendor:publish --tag=error-monitor-migrations)
	*/
	'run_migrations' => true,

];
