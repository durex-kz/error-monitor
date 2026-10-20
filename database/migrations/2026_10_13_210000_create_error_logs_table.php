<?php

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
        Schema::create(config('error-monitor.table', 'error_logs'), function (Blueprint $table) {
	        $table->id();
	        $table->timestamps();
	        $table->bigInteger('user_id')->default(0)->index(); // id пользователя, у которого произошла ошибка (0 — гость/консоль)

	        $table->dateTime('resolved_at')->nullable()->index(); // когда ошибка отмечена исправленной
	        $table->bigInteger('resolved_by')->default(0)->index(); // id пользователя

	        $table->unsignedSmallInteger('code')->index()->comment('HTTP-код ошибки: 500, 404, 403 и т.д.');
	        $table->string('exception')->nullable()->index()->comment('Класс исключения');
	        $table->text('message')->nullable()->comment('Текст ошибки'); // text в SQL Server индексировать нельзя
	        $table->string('file', 500)->nullable()->comment('Файл');
	        $table->unsignedInteger('line')->nullable()->comment('Строка');
	        $table->text('trace')->nullable()->comment('Стек вызовов');

	        $table->string('method', 10)->nullable()->comment('HTTP-метод');
	        $table->string('url', 2000)->nullable()->comment('Адрес запроса');
	        $table->string('ip', 45)->nullable()->index()->comment('IP-адрес');
	        $table->string('user_agent', 500)->nullable()->comment('User-Agent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('error-monitor.table', 'error_logs'));
    }
};
