<?php

use DurexKz\ErrorMonitor\ErrorMonitor;
use DurexKz\ErrorMonitor\Models\ErrorLog;
use DurexKz\ErrorMonitor\Tests\Fixtures\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function request404(string $url, string $ip = '203.0.113.5', array $server = []): Request
{
	return Request::create($url, server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'Mozilla/5.0'] + $server);
}

it('records 4xx/5xx responses from HTTP requests', function () {
	Route::get('/boom', fn() => throw new RuntimeException('Something broke 42'));

	$this->get('/missing-page')->assertNotFound();
	$this->get('/boom')->assertStatus(500);

	expect(ErrorLog::where('code', 404)->sole())
		->message->toContain('missing-page')
		->resolved_at->toBeNull()
		->ip->toBe('127.0.0.1')
		->and(ErrorLog::where('code', 500)->sole())
		->message->toBe('Something broke 42')
		->fingerprint->toHaveLength(40);
});

it('skips ignored codes and local hosts', function () {
	Route::post('/form', fn() => request()->validate(['name' => 'required']));

	$this->post('/form')->assertStatus(302); // 422 → редирект с ошибками
	$this->postJson('/form')->assertStatus(422);
	$this->get('http://project.test/missing')->assertNotFound();

	expect(ErrorLog::count())->toBe(0);
});

it('writes 410 and expected errors as already resolved', function () {
	config()->set('error-monitor.expected', [
		...config('error-monitor.expected'),
		['code' => 404, 'paths' => ['news/*.html'], 'resolution' => 'Спам-ссылка'],
	]);

	ErrorLog::register(410, new Exception());
	ErrorLog::register(404, new NotFoundHttpException(), request404('/.well-known/assetlinks.json'));
	ErrorLog::register(404, new NotFoundHttpException(), request404('/news/casino.html'));
	ErrorLog::register(404, new NotFoundHttpException(), request404('/news'));

	expect(ErrorLog::where('code', 410)->sole()->isResolved())->toBeTrue()
		->and(ErrorLog::where('url', 'like', '%well-known%')->sole()->resolution)->toContain('/.well-known/')
		->and(ErrorLog::where('url', 'like', '%casino%')->sole()->resolution)->toBe('Спам-ссылка')
		->and(ErrorLog::where('url', 'like', '%/news')->sole()->isResolved())->toBeFalse();
});

it('writes 503 during maintenance mode as already resolved', function () {
	app()->maintenanceMode()->activate([]);

	ErrorLog::register(503, new Exception(), request404('/livewire/update'));

	app()->maintenanceMode()->deactivate();

	ErrorLog::register(503, new Exception(), request404('/tenders'));

	expect(ErrorLog::where('url', 'like', '%livewire%')->sole())
		->isResolved()->toBeTrue()
		->resolution->toContain('режима обслуживания')
		->and(ErrorLog::where('url', 'like', '%tenders%')->sole()->isResolved())->toBeFalse();
});

it('groups repeats and resolves them together', function () {
	foreach ([1, 2, 3] as $id) {
		ErrorLog::register(404, new NotFoundHttpException(), request404("/posts/{$id}"));
	}
	ErrorLog::register(404, new NotFoundHttpException(), request404('/other'));

	expect(ErrorLog::query()->latestOfRepeats()->count())->toBe(2)
		->and(ErrorLog::query()->withRepeatsCount()->latest('id')->skip(1)->first()->repeats_count)->toBe(3);

	$count = ErrorLog::where('url', 'like', '%/posts/3')->sole()->markAsResolved('Удалена запись', withRepeats: true);

	expect($count)->toBe(3)
		->and(ErrorLog::unresolved()->count())->toBe(1)
		->and(ErrorLog::where('url', 'like', '%/posts/1')->sole()->resolution)->toBe('Удалена запись');
});

it('marks scanner requests and the rest of the series from the same IP', function () {
	ErrorMonitor::detectScannersUsing(fn(Request $request) => str_contains($request->path(), 'phpinfo'));

	ErrorLog::register(405, new Exception(), request404('/'));               // до распознавания
	ErrorLog::register(404, new NotFoundHttpException(), request404('/phpinfo.php'));
	ErrorLog::register(404, new NotFoundHttpException(), request404('/about')); // после — по IP
	ErrorLog::register(404, new NotFoundHttpException(), request404('/about', '198.51.100.1')); // другой IP

	expect(ErrorLog::where('ip', '203.0.113.5')->where('is_scanner', true)->whereNotNull('resolved_at')->count())->toBe(3)
		->and(ErrorLog::where('ip', '198.51.100.1')->sole()->is_scanner)->toBeFalse();
});

it('does not link series by private or loopback IPs', function () {
	expect(ErrorLog::isTrackableIp('203.0.113.5'))->toBeTrue()
		->and(ErrorLog::isTrackableIp('127.0.0.1'))->toBeFalse()
		->and(ErrorLog::isTrackableIp('192.168.1.10'))->toBeFalse()
		->and(ErrorLog::isTrackableIp('10.0.0.5'))->toBeFalse()
		->and(ErrorLog::isTrackableIp(null))->toBeFalse();
});

it('finds the first application frame, skipping middleware and vendor', function () {
	$base = '/srv/site';

	[$file, $line] = ErrorLog::appFrame([
		['file' => "$base/vendor/laravel/framework/src/Foo.php", 'line' => 10],
		['file' => "$base/app/Http/Middleware/Auth.php", 'line' => 20],
		['file' => "$base/app/Models/Offer.php", 'line' => 42],
	], $base);

	expect($file)->toBe('app/Models/Offer.php')->and($line)->toBe(42);
});

it('masks sensitive POST fields', function () {
	$request = Request::create('/login', 'POST', ['email' => 'a@b.kz', 'password' => 'secret', 'nested' => ['api_key' => 'x']], server: ['REMOTE_ADDR' => '203.0.113.5']);

	ErrorLog::register(500, new Exception('x'), $request);

	$data = json_decode(ErrorLog::sole()->post_data, true);

	expect($data['email'])->toBe('a@b.kz')
		->and($data['password'])->toBe('***')
		->and($data['nested']['api_key'])->toBe('***');
});

it('extracts Livewire context without field values', function () {
	$context = ErrorLog::livewireContext(['components' => [[
		'snapshot' => json_encode(['memo' => ['name' => 'app.filament.pages.edit-offer', 'path' => 'admin/offers/1/edit']]),
		'calls' => [['method' => 'save', 'params' => [1]]],
		'updates' => ['data.price' => '100'],
	]]]);

	expect($context)->toBe([
		'component' => 'app.filament.pages.edit-offer',
		'path' => '/admin/offers/1/edit',
		'calls' => 'save(1); set data.price',
	]);
});

it('authorizes through the configured gate', function () {
	$admin = User::create(['name' => 'Admin', 'is_admin' => true]);
	$user = User::create(['name' => 'User']);
	$log = ErrorLog::create(['code' => 500]);

	expect($admin->can('viewAny', ErrorLog::class))->toBeFalse(); // gate не определён — доступа нет

	Gate::define('security-admin', fn(User $user) => $user->isGlobalAdmin());

	expect($admin->can('viewAny', ErrorLog::class))->toBeTrue()
		->and($admin->can('resolve', $log))->toBeTrue()
		->and($admin->can('update', $log))->toBeFalse()
		->and($user->can('viewAny', ErrorLog::class))->toBeFalse();
});

it('prunes old resolved records only', function () {
	ErrorLog::create(['code' => 404, 'resolved_at' => now()])->forceFill(['created_at' => now()->subDays(100)])->save();
	ErrorLog::create(['code' => 500])->forceFill(['created_at' => now()->subDays(100)])->save();
	ErrorLog::create(['code' => 404, 'resolved_at' => now()]);

	$this->artisan('error-monitor:prune')->assertSuccessful();

	expect(ErrorLog::count())->toBe(2)->and(ErrorLog::unresolved()->count())->toBe(1);
});
