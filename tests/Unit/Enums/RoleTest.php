<?php

namespace Tests\Unit\Enums;

use App\Enums\Permission;
use App\Enums\Role;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    public function test_admin_has_every_permission(): void
    {
        $this->assertSame(Permission::cases(), Role::Admin->permissions());
    }

    public function test_regular_user_has_no_elevated_permissions(): void
    {
        $this->assertSame([], Role::User->permissions());
    }
}
