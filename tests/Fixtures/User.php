<?php

namespace DurexKz\ErrorMonitor\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
	protected $guarded = [];

	public function isGlobalAdmin(): bool
	{
		return (bool) $this->is_admin;
	}
}
