# durex-kz/error-monitor

Журнал ошибок для Laravel 12/13. Пишет в базу каждый ответ 4xx/5xx и сбои очередей, artisan и планировщика.

- **Группировка повторов** по отпечатку: HTTP-код, место в коде и текст без чисел. В списке видна одна строка на ошибку с числом повторов, а «Исправлено» можно отметить сразу для всей группы.
- **Место в коде.** Сохраняется первый кадр стека в `app/`, `resources/`, `routes/` или `database/`, без посредников. Скомпилированные Blade-шаблоны заменяются исходным `.blade.php`.
- **Контекст Livewire.** Сохраняются компонент, страница, вызванные методы и изменённые поля. Значения полей не пишутся.
- **POST-данные** сохраняются с замаскированными паролями, токенами и номерами карт.
- **Ожидаемые ошибки** (410, `/.well-known/…`, свои правила) сразу пишутся исправленными, с пояснением в поле «Решение».
- **Запросы сканеров** помечаются `is_scanner` и тоже сразу считаются исправленными. Вместе с ними помечается вся серия запросов с того же IP в окне ±60 минут. Сами правила распознавания живут в [`durex-kz/bot-guard`](https://github.com/durex-kz/bot-guard).

Панель для журнала находится в [`durex-kz/filament-security`](https://github.com/durex-kz/filament-security).

## Установка

```bash
composer require durex-kz/error-monitor
php artisan migrate
```

Если пакет не опубликован на Packagist, сначала добавьте репозиторий в `composer.json` проекта:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/durex-kz/error-monitor" }
]
```

`bootstrap/app.php`:

```php
use DurexKz\ErrorMonitor\ErrorMonitor;

->withExceptions(function (Exceptions $exceptions) {
    // необязательно: удалённая (soft delete) запись отвечает 410 Gone вместо 404
    ErrorMonitor::mapSoftDeletedToGone($exceptions);

    ErrorMonitor::handle($exceptions);
})
```

Доступ к журналу даёт gate `security-admin`. Его нужно определить в `AppServiceProvider::boot()`:

```php
Gate::define('security-admin', fn (User $user) => $user->isGlobalAdmin());
```

Если gate не определён, доступа к журналу нет ни у кого.

## Настройка

```bash
php artisan vendor:publish --tag=error-monitor-config
```

Основные параметры `config/error-monitor.php`:

| Ключ | По умолчанию | Назначение |
|---|---|---|
| `ignore_codes` | `[419, 422]` | коды, которые не пишутся |
| `ignore_hosts` | `['*.test']` | локальная разработка (Herd/Valet) |
| `auto_resolve_codes` | `[410]` | коды, которые сразу пишутся исправленными |
| `expected` | `.well-known/*` → 404, 503 в режиме обслуживания | ожидаемые ошибки с пояснением |
| `scanner_ip_window_minutes` | `60` | окно связывания серии запросов сканера по IP |
| `admin_gate` | `security-admin` | gate доступа |
| `model`, `user_model`, `table` | — | замена модели, пользователя, таблицы |

Свои ожидаемые ошибки задаются массивом или классом:

```php
'expected' => [
    ['code' => 404, 'paths' => ['.well-known/*'], 'resolution' => '…'],
    // спам-ссылки на несуществующие «новости»
    ['code' => 404, 'paths' => ['news/*.html'], 'resolution' => 'Не ошибка приложения: спам-ссылка…'],
    \App\ErrorMonitor\MyRule::class, // implements DurexKz\ErrorMonitor\Contracts\ExpectedError
],
```

Если в приложении свой список `expected`, встроенные правила нужно перечислить в нём: список целиком заменяет
список пакета. Встроенные классы: `\DurexKz\ErrorMonitor\Expected\MaintenanceMode` — 503 во время
`php artisan down`.

## Команды

```bash
php artisan error-monitor:rescan             # перепроверить журнал по текущим правилам сканеров
php artisan error-monitor:rescan --context   # заодно пересобрать место в коде и отпечатки
php artisan error-monitor:prune --days=90    # удалить старые исправленные записи
```

Для регулярной очистки добавьте в `routes/console.php`: `Schedule::command('error-monitor:prune')->daily();`

## Программный доступ

```php
ErrorMonitor::record(500, $exception, $request);   // записать вручную
ErrorLog::unresolved()->latestOfRepeats()->withRepeatsCount()->get();
$log->markAsResolved('Причина и что сделано', withRepeats: true);
```

## Переход с кода в проекте (QazPress, «Апельсин»)

Миграции пакета сохраняют **прежние имена файлов**. В базе проекта они уже отмечены выполненными, поэтому таблица не пересоздаётся, и данные остаются на месте.

1. `composer require durex-kz/error-monitor`
2. Удалить из проекта:
   - `app/Models/ErrorLog.php`, `app/Policies/ErrorLogPolicy.php`;
   - миграции `2026_10_13_210000_…`, `2026_10_13_230000_…`, `2026_10_13_231000_…`, `2026_10_14_100000_…`, `2026_10_14_190000_…`, `2026_10_19_120000_…`.
3. В `bootstrap/app.php` заменить блоки `respond(...)`, `report(...)` и `map(ModelNotFoundException ...)` на `ErrorMonitor::mapSoftDeletedToGone($exceptions); ErrorMonitor::handle($exceptions);`.
4. Определить gate `security-admin` (см. выше).
5. Заменить `App\Models\ErrorLog` на `DurexKz\ErrorMonitor\Models\ErrorLog` в остальном коде и тестах.
6. QazPress: добавить в `config/error-monitor.php` правило `news/*.html` (спам-ссылки). «Апельсин»: правило `sitemap*.xml`.

## Тесты

```bash
composer test
```

## Лицензия

MIT
