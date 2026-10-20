<?php

namespace DurexKz\ErrorMonitor\Policies;

use DurexKz\ErrorMonitor\ErrorMonitor;
use Illuminate\Database\Eloquent\Model;

/**
 * Журнал ошибок пишется только системой; в панели его может читать, отмечать исправленным
 * и удалять администратор (gate из config error-monitor.admin_gate)
 */
class ErrorLogPolicy
{
	public function viewAny($user): bool
	{
		return ErrorMonitor::isAdmin($user);
	}

	public function view($user, Model $errorLog): bool
	{
		return ErrorMonitor::isAdmin($user);
	}

	public function create($user): bool
	{
		return false;
	}

	public function update($user, Model $errorLog): bool
	{
		return false;
	}

	/**
	 * Отметить ошибку исправленной / снять отметку (resolved_at, resolved_by)
	 */
	public function resolve($user, Model $errorLog): bool
	{
		return ErrorMonitor::isAdmin($user);
	}

	public function delete($user, Model $errorLog): bool
	{
		return ErrorMonitor::isAdmin($user);
	}

	public function deleteAny($user): bool
	{
		return ErrorMonitor::isAdmin($user);
	}

	public function restore($user, Model $errorLog): bool
	{
		return false;
	}

	public function forceDelete($user, Model $errorLog): bool
	{
		return false;
	}
}
