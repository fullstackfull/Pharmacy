<?php

namespace Tests\Feature;

use App\Services\Theme\ThemePermissionService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Separate view/edit/publish theme permissions.
 *
 * The security-relevant case is PUBLISH: it changes the live storefront for every customer, so it
 * must not be implied by merely holding the coarse themes module.
 */
class ThemePermissionServiceTest extends TestCase
{
    private function actAs(?int $roleId, array $moduleAccess = []): void
    {
        if ($roleId === null) {
            Auth::guard('admin')->logout();
            return;
        }

        $admin = new class($roleId, $moduleAccess) implements Authenticatable {
            public $admin_role_id;
            public $role;

            public function __construct(int $roleId, array $access)
            {
                $this->admin_role_id = $roleId;
                $this->role = (object) ['module_access' => json_encode($access)];
            }

            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return 1; }
            public function getAuthPassword() { return ''; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return null; }
            public function getAuthPasswordName() { return 'password'; }
        };

        $this->be($admin, 'admin');
    }

    private function svc(): ThemePermissionService
    {
        return new ThemePermissionService();
    }

    public function test_guest_has_no_theme_permissions(): void
    {
        $svc = $this->svc();

        $this->assertFalse($svc->canView());
        $this->assertFalse($svc->canEdit());
        $this->assertFalse($svc->canPublish());
    }

    public function test_master_admin_has_every_permission(): void
    {
        $this->actAs(1); // role 1 = master admin

        $svc = $this->svc();
        $this->assertTrue($svc->canView());
        $this->assertTrue($svc->canEdit());
        $this->assertTrue($svc->canPublish());
    }

    /** The core rule: holding the coarse module must NOT imply publishing to the live storefront. */
    public function test_module_grant_allows_view_and_edit_but_not_publish(): void
    {
        $this->actAs(2, ['themes_and_addons']);

        $svc = $this->svc();
        $this->assertTrue($svc->canView());
        $this->assertTrue($svc->canEdit());
        $this->assertFalse($svc->canPublish());
    }

    public function test_explicit_publish_grant_is_honoured(): void
    {
        $this->actAs(2, ['themes_and_addons', 'theme_publish']);

        $this->assertTrue($this->svc()->canPublish());
    }

    public function test_role_without_the_module_has_nothing(): void
    {
        $this->actAs(2, ['orders']);

        $svc = $this->svc();
        $this->assertFalse($svc->canView());
        $this->assertFalse($svc->canEdit());
        $this->assertFalse($svc->canPublish());
    }

    public function test_explicit_view_only_grant_does_not_allow_editing(): void
    {
        $this->actAs(2, ['theme_view']);

        $svc = $this->svc();
        $this->assertTrue($svc->canView());
        $this->assertFalse($svc->canEdit());
        $this->assertFalse($svc->canPublish());
    }

    public function test_malformed_module_access_does_not_throw(): void
    {
        $this->actAs(2, []); // empty access list

        $this->assertFalse($this->svc()->canPublish());
    }
}
