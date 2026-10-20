<?php

use DurexKz\ErrorMonitor\ErrorMonitor;
use DurexKz\ErrorMonitor\Models\ErrorLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table(config('error-monitor.table', 'error_logs'), function (Blueprint $table) {
	        $table->char('fingerprint', 40)->nullable()->index()->comment('Отпечаток для группировки повторов одной ошибки');

	        $table->string('root_exception')->nullable()->comment('Класс исходного исключения, если оно обёрнуто (ViewException и т. п.)');
	        $table->string('app_file', 500)->nullable()->comment('Первое место в коде приложения (путь от корня проекта)');
	        $table->unsignedInteger('app_line')->nullable()->comment('Строка в app_file');

	        $table->string('route_name')->nullable()->comment('Имя маршрута');
	        $table->string('livewire_component')->nullable()->comment('Livewire-компонент(ы) запроса /livewire-…/update');
	        $table->string('livewire_path', 500)->nullable()->comment('Страница, на которой открыт Livewire-компонент');
	        $table->string('livewire_calls', 1000)->nullable()->comment('Вызванные методы и изменённые поля Livewire');
	        $table->string('referer', 2000)->nullable()->comment('Страница, с которой пришёл запрос (Referer)');
        });

        // контекст и отпечаток для уже записанных ошибок — по сохранённым стеку вызовов и POST-данным
        ErrorMonitor::model()::query()->eachById(function (ErrorLog $log) {
	        $log->rebuildContext();
	        $log->saveQuietly();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(config('error-monitor.table', 'error_logs'), function (Blueprint $table) {
	        $table->dropIndex(['fingerprint']);
	        $table->dropColumn([
		        'fingerprint',
		        'root_exception',
		        'app_file',
		        'app_line',
		        'route_name',
		        'livewire_component',
		        'livewire_path',
		        'livewire_calls',
		        'referer',
	        ]);
        });
    }
};
