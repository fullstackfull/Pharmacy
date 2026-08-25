<?php

namespace Tests\Feature;

use App\Services\DeveloperPortal\DeveloperPortalPermissionService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Reading the API documentation and firing requests at the platform are not one permission.
 *
 * Analytics and monitoring were each split into capabilities because they expose more than an
 * ordinary screen. The Developer Portal was not: holding the coarse `system_settings` module opened
 * all of it, including a console that issues real authenticated requests against this installation.
 * Anyone who could read the docs could call the endpoints they described.
 *
 * The narrowing here is deliberate and is the point of the change: a role holding only the module
 * keeps every page it reads and loses the console until somebody grants it. These pin both halves —
 * that the reading survives, and that the acting does not come free with it.
 */
class DeveloperPortalPermissionTest extends TestCase
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

    private function svc(): DeveloperPortalPermissionService
    {
        return new DeveloperPortalPermissionService();
    }

    public function test_a_guest_has_nothing(): void
    {
        $this->actAs(null);
        $svc = $this->svc();

        $this->assertFalse($svc->canView());
        $this->assertFalse($svc->canUseConsole());
        $this->assertFalse($svc->canSnapshot());
    }

    public function test_master_admin_keeps_everything(): void
    {
        $this->actAs(1);
        $svc = $this->svc();

        $this->assertTrue($svc->canView());
        $this->assertTrue($svc->canUseConsole());
        $this->assertTrue($svc->canSnapshot());
    }

    public function test_the_module_alone_reads_the_documentation_and_nothing_more(): void
    {
        // The whole change in one assertion: the portal stays open, the console closes.
        $this->actAs(7, ['system_settings']);
        $svc = $this->svc();

        $this->assertTrue($svc->canView());
        $this->assertFalse($svc->canUseConsole(), 'the console sends real requests at this installation');
        $this->assertFalse($svc->canSnapshot(), 'a snapshot writes the baseline later runs are compared against');
    }

    public function test_the_console_can_be_granted_on_its_own(): void
    {
        $this->actAs(7, [DeveloperPortalPermissionService::CONSOLE]);

        $this->assertTrue($this->svc()->canUseConsole());
    }

    public function test_a_role_with_neither_the_module_nor_a_grant_cannot_open_the_portal(): void
    {
        $this->actAs(7, ['catalog']);

        $this->assertFalse($this->svc()->canView());
    }

    public function test_the_dotted_spelling_of_a_capability_is_understood(): void
    {
        // Roles are edited by hand and by import; two spellings of one grant must not be two
        // different answers.
        $this->actAs(7, ['developer.console']);

        $this->assertTrue($this->svc()->canUseConsole());
    }

    public function test_every_capability_is_offered_to_the_role_editor(): void
    {
        // A capability the editor cannot tick is one nobody can ever be granted.
        $offered = array_keys(DeveloperPortalPermissionService::all());

        foreach ([
            DeveloperPortalPermissionService::VIEW,
            DeveloperPortalPermissionService::CONSOLE,
            DeveloperPortalPermissionService::SNAPSHOT,
        ] as $capability) {
            $this->assertContains($capability, $offered);
        }
    }
}
