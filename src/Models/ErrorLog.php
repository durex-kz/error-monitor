<?php

namespace DurexKz\ErrorMonitor\Models;

use Carbon\Carbon;
use DurexKz\ErrorMonitor\ErrorMonitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Журнал ошибок системы. Записи пишутся из обработчика исключений (ErrorMonitor::handle в bootstrap/app.php)
 *
 * @property int $id
 * @property int $code
 * @property bool $is_scanner
 * @property ?Carbon $resolved_at
 * @property ?string $fingerprint
 * @property ?int $repeats_count
 */
class ErrorLog extends Model
{
	public function getTable(): string
	{
		return config('error-monitor.table', 'error_logs');
	}

	protected $fillable = [
		'user_id',

		'resolved_at',
		'resolved_by',
		'resolution',

		'is_scanner',

		'code',
		'exception',
		'message',
		'file',
		'line',
		'trace',
		'method',
		'url',
		'post_data',
		'ip',
		'user_agent',

		'fingerprint',
		'root_exception',
		'app_file',
		'app_line',
		'route_name',
		'livewire_component',
		'livewire_path',
		'livewire_calls',
		'referer',
	];

	protected function casts(): array
	{
		return [
			'resolved_at' => 'datetime',
			'is_scanner' => 'boolean',
			'code' => 'integer',
			'line' => 'integer',
			'app_line' => 'integer',
		];
	}

	public function user(): BelongsTo
	{
		return $this->belongsTo(ErrorMonitor::userModel(), 'user_id');
	}

	public function resolver(): BelongsTo
	{
		return $this->belongsTo(ErrorMonitor::userModel(), 'resolved_by');
	}

	/**
	 * Сколько минут до и после запроса сканера остальные ошибки с того же IP тоже считаются сканерными
	 */
	public static function scannerIpWindowMinutes(): int
	{
		return (int) config('error-monitor.scanner_ip_window_minutes', 60);
	}

	public function scopeUnresolved(Builder $query): Builder
	{
		return $query->whereNull('resolved_at');
	}

	public function scopeResolved(Builder $query): Builder
	{
		return $query->whereNotNull('resolved_at');
	}

	/**
	 * Только последняя запись каждой группы повторов (одинаковый fingerprint) среди записей с тем же
	 * состоянием «исправлено»: старые повторы прячутся под самой новой записью группы
	 */
	public function scopeLatestOfRepeats(Builder $query): Builder
	{
		$table = $query->getModel()->getTable();

		return $query->whereNotExists(fn($sub) => $sub
			->selectRaw('1')
			->from($table . ' as newer')
			->whereColumn('newer.fingerprint', $table . '.fingerprint')
			->whereColumn('newer.id', '>', $table . '.id')
			->where(fn($state) => $state
				->where(fn($both) => $both->whereNull('newer.resolved_at')->whereNull($table . '.resolved_at'))
				->orWhere(fn($both) => $both->whereNotNull('newer.resolved_at')->whereNotNull($table . '.resolved_at'))));
	}

	/**
	 * Сколько всего записей с тем же fingerprint (поле repeats_count)
	 */
	public function scopeWithRepeatsCount(Builder $query): Builder
	{
		$table = $query->getModel()->getTable();

		if (empty($query->getQuery()->columns)) {
			$query->select($table . '.*');
		}

		return $query->selectSub(fn($sub) => $sub
			->selectRaw('count(*)')
			->from($table . ' as repeats')
			->whereColumn('repeats.fingerprint', $table . '.fingerprint'), 'repeats_count');
	}

	/**
	 * Другие записи той же ошибки (тот же fingerprint)
	 */
	public function repeats(): Builder
	{
		return static::query()
			->when(blank($this->fingerprint), fn(Builder $query) => $query->whereRaw('1 = 0'))
			->where('fingerprint', $this->fingerprint)
			->whereKeyNot($this->getKey());
	}

	public function isResolved(): bool
	{
		return $this->resolved_at !== null;
	}

	/**
	 * @param string|null $resolution решение: причина ошибки и что сделано; null — оставить прежнее
	 * @param bool $withRepeats отметить и все неисправленные повторы этой ошибки (тот же fingerprint)
	 * @return int сколько записей отмечено
	 */
	public function markAsResolved(?string $resolution = null, bool $withRepeats = false): int
	{
		$count = 0;

		if (!$this->isResolved()) {
			$this->resolved_at = now();
			$this->resolved_by = auth()->id() ?? 0;
			if (filled($resolution)) {
				$this->resolution = $resolution;
			}
			$this->save();
			$count++;
		}

		if ($withRepeats) {
			$this->repeats()->unresolved()->get()->each(function (self $repeat) use ($resolution, &$count) {
				$count += $repeat->markAsResolved($resolution);
			});
		}

		return $count;
	}

	public function markAsUnresolved(): void
	{
		if (!$this->isResolved()) {
			return;
		}

		$this->resolved_at = null;
		$this->resolved_by = 0;
		$this->save();
	}

	/**
	 * Запрос сканера уязвимостей. Правила задаёт durex-kz/bot-guard (ErrorMonitor::detectScannersUsing);
	 * $code нужен для правила «пустой User-Agent + 404/405»
	 */
	public static function isScannerProbe(Request $request, ?int $code = null): bool
	{
		return ErrorMonitor::isScannerRequest($request, $code);
	}

	/**
	 * С этого IP за последние scanner_ip_window_minutes минут уже был запрос сканера
	 */
	public static function isRecentScannerIp(?string $ip): bool
	{
		if (!static::isTrackableIp($ip)) {
			return false;
		}

		return static::query()
			->where('ip', $ip)
			->where('is_scanner', true)
			->where('created_at', '>=', now()->subMinutes(static::scannerIpWindowMinutes()))
			->exists();
	}

	/**
	 * Пометить сканерными (и решёнными) ошибки с IP в окне ±scanner_ip_window_minutes вокруг $around
	 */
	public static function markScannerIp(?string $ip, \DateTimeInterface $around): void
	{
		if (!static::isTrackableIp($ip)) {
			return;
		}

		$window = fn() => static::query()
			->where('ip', $ip)
			->where('is_scanner', false)
			->whereBetween('created_at', [
				Carbon::instance($around)->subMinutes(static::scannerIpWindowMinutes()),
				Carbon::instance($around)->addMinutes(static::scannerIpWindowMinutes()),
			]);

		$window()->whereNull('resolved_at')->update(['resolved_at' => now()]);
		$window()->update(['is_scanner' => true]);
	}

	/**
	 * По IP связываем только публичные адреса: частные и служебные (127.0.0.1, ::1, 10/8, 172.16/12, 192.168/16…)
	 * — это artisan, тесты, разработка, а сканеры подставляют их в X-Forwarded-For, чтобы сойти за внутренний запрос
	 */
	public static function isTrackableIp(?string $ip): bool
	{
		return filled($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
	}

	/**
	 * Восстановить запрос по записи журнала — для повторной проверки isScannerProbe() у старых записей
	 */
	public function toRequest(): ?Request
	{
		if (blank($this->url)) {
			return null;
		}

		// Request::create по умолчанию подставляет User-Agent «Symfony», поэтому задаём его явно
		return Request::create($this->url, $this->method ?: 'GET', server: [
			'HTTP_USER_AGENT' => (string) $this->user_agent,
			'REMOTE_ADDR' => $this->ip ?: '127.0.0.1',
		]);
	}

	/**
	 * Сводка ошибки одним текстом для копирования в буфер обмена
	 */
	public function toReport(): string
	{
		return implode("\n", [
			__('Код') . ': ' . $this->code,
			__('Сообщение') . ': ' . ($this->message ?: '—'),
			...($this->root_exception ? [__('Исходное исключение') . ': ' . $this->root_exception] : []),
			...($this->app_file ? [__('Место в коде') . ': ' . $this->appLocation()] : []),
			__('Метод') . ': ' . ($this->method ?: '—'),
			__('Адрес') . ': ' . ($this->url ?: '—'),
			...($this->referer ? [__('Откуда') . ': ' . $this->referer] : []),
			...($this->livewire_component ? [__('Livewire') . ': ' . $this->livewire_component . ($this->livewire_path ? ' @ ' . $this->livewire_path : '')] : []),
			...($this->livewire_calls ? [__('Вызовы') . ': ' . $this->livewire_calls] : []),
			...($this->post_data ? [__('POST-данные') . ':', $this->post_data] : []),
			__('Стек вызовов') . ':',
			$this->trace ?: '—',
			...($this->resolution ? [__('Решение') . ':', $this->resolution] : []),
		]);
	}

	/**
	 * Сохранить ошибку в журнал. Сбой записи (например, недоступна БД) не должен
	 * порождать новое исключение внутри обработчика исключений.
	 */
	public static function register(int $code, Throwable $e, ?Request $request = null): void
	{
		try {
			// запросы сканеров уязвимостей пишем, но сразу как решённые: в счётчик и виджет они не попадают.
			// Сканер видно и по поведению: с его IP недавно уже был распознанный запрос (например, POST / → 405
			// сам по себе выглядит обычно, но приходит в той же серии, что и /phpinfo.php.bak)
			$isScanner = $request !== null
				&& (static::isScannerProbe($request, $code) || static::isRecentScannerIp($request->ip()));

			// ViewException, ошибки в замыканиях Filament и т. п. оборачивают исходную ошибку: место и текст берём у неё
			$root = static::rootException($e);
			[$appFile, $appLine] = static::appFrame(static::exceptionFrames($root));
			$livewire = static::livewireContext($request?->input() ?? []);
			$message = filled($e->getMessage()) ? $e->getMessage() : static::codeDescription($code);

			// ожидаемые ошибки (config error-monitor.expected): 404 на /.well-known/… и т. п.
			$expectedResolution = ErrorMonitor::expectedResolution($code, $request);

			// 410 — запрос удалённой записи (обычно поисковый робот по старому адресу): исправлять нечего,
			// пишем сразу решённой (config error-monitor.auto_resolve_codes)
			$autoResolved = in_array($code, config('error-monitor.auto_resolve_codes', [410]), true);

			$log = static::create([
				'user_id' => auth()->id() ?? 0,
				'is_scanner' => $isScanner,
				'resolved_at' => ($isScanner || $autoResolved || $expectedResolution) ? now() : null,
				'resolution' => $expectedResolution,
				'code' => $code,
				'exception' => $e::class,
				// у abort(403) / 404 и т.п. текст исключения обычно пустой — пишем расшифровку кода
				'message' => mb_substr($message, 0, 4000),
				'file' => mb_substr($e->getFile(), 0, 500),
				'line' => $e->getLine(),
				'trace' => mb_substr($e->getTraceAsString(), 0, 20000),
				'method' => $request?->method(),
				'url' => $request ? mb_substr($request->fullUrl(), 0, 2000) : null,
				'post_data' => $request ? static::postData($request) : null,
				'ip' => $request?->ip(),
				'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,

				'fingerprint' => static::makeFingerprint($code, $appFile, $appLine, $livewire['component'], $request?->fullUrl(), filled($root->getMessage()) ? $root->getMessage() : $message),
				'root_exception' => $root !== $e ? $root::class : null,
				'app_file' => $appFile ? mb_substr($appFile, 0, 500) : null,
				'app_line' => $appLine,
				'route_name' => $request?->route()?->getName(),
				'livewire_component' => $livewire['component'] ? mb_substr($livewire['component'], 0, 255) : null,
				'livewire_path' => $livewire['path'] ? mb_substr($livewire['path'], 0, 500) : null,
				'livewire_calls' => $livewire['calls'] ? mb_substr($livewire['calls'], 0, 1000) : null,
				'referer' => $request?->headers->get('referer') ? mb_substr($request->headers->get('referer'), 0, 2000) : null,
			]);

			// предыдущие ошибки этой же серии, записанные до распознавания, тоже помечаем
			if ($isScanner) {
				static::markScannerIp($log->ip, $log->created_at);
			}
		} catch (Throwable) {
			// журнал ошибок не должен ломать ответ
		}
	}

	/**
	 * Самое глубокое исключение цепочки getPrevious()
	 */
	public static function rootException(Throwable $e): Throwable
	{
		while ($e->getPrevious() !== null) {
			$e = $e->getPrevious();
		}

		return $e;
	}

	/**
	 * Место возникновения исключения и его стек вызовов: [['file' => …, 'line' => …], …]
	 */
	protected static function exceptionFrames(Throwable $e): array
	{
		return [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];
	}

	/**
	 * Разобрать сохранённый стек (getTraceAsString) обратно в кадры — для записей, у которых
	 * контекст восстанавливается задним числом (rebuildContext)
	 */
	protected static function parseTrace(?string $trace): array
	{
		preg_match_all('~^#\d+ (/[^(\n]+)\((\d+)\):~m', (string) $trace, $matches, PREG_SET_ORDER);

		return array_map(fn(array $m): array => ['file' => $m[1], 'line' => (int) $m[2]], $matches);
	}

	/**
	 * Первое место в коде приложения (app/, resources/, routes/, database/): сначала файл исключения,
	 * затем стек вызовов. Посредники app/Http/Middleware пропускаются — они есть в стеке почти любой
	 * ошибки и места не указывают. Скомпилированный шаблон storage/framework/views заменяется исходным
	 * .blade.php (строка тогда не указывается: в скомпилированном файле она другая)
	 *
	 * @param string|null $basePath корень проекта, на котором произошла ошибка (у старых записей — сервера)
	 * @return array{0: ?string, 1: ?int} путь от корня проекта и строка
	 */
	public static function appFrame(array $frames, ?string $basePath = null): array
	{
		$basePath = rtrim($basePath ?? base_path(), '/') . '/';

		foreach ($frames as $frame) {
			$file = $frame['file'] ?? null;

			if (!$file || !str_starts_with($file, $basePath)) {
				continue;
			}

			$file = substr($file, strlen($basePath));
			$line = $frame['line'] ?? null;

			if (str_starts_with($file, 'storage/framework/views/')) {
				$source = static::compiledViewSource($basePath . $file);
				$file = $source && str_starts_with($source, $basePath) ? substr($source, strlen($basePath)) : null;
				$line = null;
			}

			if ($file && static::isAppFile($file)) {
				return [$file, $line];
			}
		}

		return [null, null];
	}

	/**
	 * Файл из каталогов кода приложения (config error-monitor.app_paths), кроме skip_app_paths
	 */
	protected static function isAppFile(string $file): bool
	{
		foreach (config('error-monitor.skip_app_paths', []) as $skip) {
			if (str_starts_with($file, $skip)) {
				return false;
			}
		}

		foreach (config('error-monitor.app_paths', ['app', 'resources', 'routes', 'database']) as $dir) {
			if (str_starts_with($file, rtrim($dir, '/') . '/')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Исходный .blade.php скомпилированного шаблона: Blade дописывает в конец файла комментарий PATH … ENDPATH
	 */
	protected static function compiledViewSource(string $compiled): ?string
	{
		if (!is_file($compiled)) {
			return null;
		}

		$tail = (string) @file_get_contents($compiled, false, null, max(0, filesize($compiled) - 1000));

		return preg_match('~/\*\*PATH (.+?) ENDPATH\*\*/~', $tail, $m) ? $m[1] : null;
	}

	/**
	 * Livewire-запрос (/livewire-…/update): у всех таких ошибок один адрес, поэтому сохраняем, какие
	 * компоненты и на какой странице открыты, какие методы вызваны (с параметрами) и какие поля изменены
	 *
	 * @return array{component: ?string, path: ?string, calls: ?string}
	 */
	public static function livewireContext(array $input): array
	{
		$components = [];
		$paths = [];
		$calls = [];

		foreach ($input['components'] ?? [] as $component) {
			$memo = json_decode($component['snapshot'] ?? '', true)['memo'] ?? [];

			if (filled($memo['name'] ?? null)) {
				$components[] = $memo['name'];
			}

			if (filled($memo['path'] ?? null)) {
				$paths[] = '/' . ltrim($memo['path'], '/');
			}

			foreach ($component['calls'] ?? [] as $call) {
				$method = (string) ($call['method'] ?? '?');

				// у служебных методов Livewire параметры — закодированные снимки; оставляем только короткий первый
				// (у __dispatch это имя события, например ncalayer-sign)
				$first = $call['params'][0] ?? null;
				$params = str_starts_with($method, '__')
					? (is_string($first) && mb_strlen($first) <= 60 ? json_encode($first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ', …' : '…')
					: mb_substr(substr((string) json_encode($call['params'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 1, -1), 0, 200);

				$calls[] = $method . '(' . $params . ')';
			}

			// значения полей не пишем — там могут быть пароли и персональные данные
			foreach (array_keys($component['updates'] ?? []) as $field) {
				$calls[] = 'set ' . $field;
			}
		}

		return [
			'component' => implode(', ', array_unique($components)) ?: null,
			'path' => implode(', ', array_unique($paths)) ?: null,
			'calls' => implode('; ', $calls) ?: null,
		];
	}

	/**
	 * Отпечаток ошибки для группировки повторов: HTTP-код, место в коде (если его нет — Livewire-компонент
	 * или адрес без чисел) и текст исходной ошибки без чисел, хвоста «(View: …)» и SQL-запроса
	 */
	public static function makeFingerprint(int $code, ?string $appFile, ?int $appLine, ?string $component, ?string $url, ?string $message): string
	{
		$place = $appFile
			? $appFile . ':' . $appLine
			: ($component ?: preg_replace('~\d+~', 'N', parse_url((string) $url, PHP_URL_PATH) ?: '/'));

		$message = preg_replace('~\s*\(View: [^)]*\)~', '', (string) $message); // ViewException дописывает шаблон
		$message = preg_replace('~\s*\(Connection: .*$~s', '', $message); // QueryException — SQL со значениями
		$message = preg_replace(['~[0-9a-f]{8,}~i', '~\d+~'], ['H', 'N'], $message);

		return sha1(implode('|', [$code, $place, mb_strtolower(trim(mb_substr($message, 0, 500)))]));
	}

	/**
	 * Заполнить контекст и отпечаток по сохранённым полям — для записей, сделанных до появления этих полей.
	 * Корень проекта берём из стека: записи могли прийти с сервера, где он другой
	 */
	public function rebuildContext(): void
	{
		$basePath = preg_match('~^#\d+ (/.+?)/(public/index\.php|artisan)\(~m', (string) $this->trace, $m) ? $m[1] : null;

		[$appFile, $appLine] = static::appFrame(
			[['file' => $this->file, 'line' => $this->line], ...static::parseTrace($this->trace)],
			$basePath,
		);

		$livewire = static::livewireContext(json_decode((string) $this->post_data, true) ?: []);

		$this->app_file = $appFile ? mb_substr($appFile, 0, 500) : null;
		$this->app_line = $appLine;
		$this->livewire_component = $livewire['component'] ? mb_substr($livewire['component'], 0, 255) : null;
		$this->livewire_path = $livewire['path'] ? mb_substr($livewire['path'], 0, 500) : null;
		$this->livewire_calls = $livewire['calls'] ? mb_substr($livewire['calls'], 0, 1000) : null;
		$this->fingerprint = static::makeFingerprint((int) $this->code, $appFile, $appLine, $livewire['component'], $this->url, $this->message);
	}

	/**
	 * Место в коде одной строкой: app/Models/Offer.php:42
	 */
	public function appLocation(): ?string
	{
		return $this->app_file ? $this->app_file . ($this->app_line ? ':' . $this->app_line : '') : null;
	}

	/**
	 * Тело запроса (форма или JSON) для журнала: без файлов, с замаскированными паролями/токенами,
	 * обрезанное до 20000 символов (Livewire-запросы бывают большими)
	 */
	protected static function postData(Request $request): ?string
	{
		$data = $request->post();

		if (empty($data)) {
			return null;
		}

		$json = json_encode(static::maskSensitive($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

		return $json === false ? null : mb_substr($json, 0, 20000);
	}

	protected static function maskSensitive(array $data): array
	{
		foreach ($data as $key => $value) {
			if (is_string($key) && preg_match('~' . config('error-monitor.sensitive_keys', 'pass|token|secret|api_?key|authorization|card|cvv|cvc') . '~i', $key)) {
				$data[$key] = '***';
			} elseif (is_array($value)) {
				$data[$key] = static::maskSensitive($value);
			}
		}

		return $data;
	}

	/**
	 * Расшифровка HTTP-кода для записей, у которых исключение не содержит текста
	 */
	public static function codeDescription(int $code): string
	{
		return match ($code) {
			400 => 'Некорректный запрос',
			401 => 'Требуется авторизация',
			402 => 'Требуется оплата',
			403 => 'Доступ запрещён: недостаточно прав для просмотра страницы или выполнения действия',
			404 => 'Страница или запись не найдена',
			405 => 'Метод запроса не поддерживается для этого адреса',
			408 => 'Истекло время ожидания запроса',
			409 => 'Конфликт: запись была изменена другим запросом',
			410 => 'Ресурс удалён',
			413 => 'Слишком большой объём данных (например, размер загружаемого файла)',
			415 => 'Неподдерживаемый тип данных',
			419 => 'Сессия истекла или недействителен CSRF-токен',
			422 => 'Данные не прошли проверку',
			429 => 'Слишком много запросов: сработало ограничение частоты',
			500 => 'Внутренняя ошибка сервера',
			501 => 'Функция не реализована',
			502 => 'Ошибка шлюза: внешний сервис вернул некорректный ответ',
			503 => 'Сервис временно недоступен (режим обслуживания или перегрузка)',
			504 => 'Внешний сервис не ответил вовремя',
			default => Response::$statusTexts[$code] ?? 'Ошибка ' . $code,
		};
	}
}
