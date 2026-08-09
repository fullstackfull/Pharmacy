<?php

namespace App\Services\Theme;

/**
 * Separate view / edit / publish permissions for the theme system (Phase 1 security requirement).
 *
 * The spec asks for these to be distinct because they carry very different risk: viewing a theme is
 * harmless, editing a draft is recoverable, but PUBLISHING changes the live storefront for every
 * customer. A single "themes" permission would let anyone who can tweak a colour also push it live.
 *
 * It layers on the existing admin RBAC rather than inventing a parallel system: the coarse
 * `themes_and_addons` module grant is still required (enforced by route middleware), and these
 * finer capabilities are read from the role's module_access. A role that predates this feature and
 * simply has the module keeps view+edit, so nothing breaks for existing admins — but publishing is
 * granted explicitly.
 */
class ThemePermissionService
{
    public const VIEW    = 'theme_view';
    public const EDIT    = 'theme_edit';
    public const PUBLISH = 'theme_publish';

    /** The coarse module that already gates the theme routes. */
    private const MODULE = 'themes_and_addons';

    public function can(string $capability): bool
    {
        $admin = auth('admin')->user();
        if (!$admin) {
            return false;
        }

        // Master admin (role 1) keeps full access, consistent with the rest of the panel.
        if ((int) ($admin->admin_role_id ?? 0) === 1) {
            return true;
        }

        $granted = $this->grantedKeys($admin);

        // Explicit grant always wins.
        if (in_array($capability, $granted, true)) {
            return true;
        }

        // Backward compatibility: an existing role holding the module keeps view + edit, but NOT
        // publish — pushing to the live storefront must be granted deliberately.
        if (in_array(self::MODULE, $granted, true)) {
            return $capability !== self::PUBLISH;
        }

        return false;
    }

    public function canView(): bool
    {
        return $this->can(self::VIEW);
    }

    public function canEdit(): bool
    {
        return $this->can(self::EDIT);
    }

    public function canPublish(): bool
    {
        return $this->can(self::PUBLISH);
    }

    /** @return array<int, string> */
    private function grantedKeys(object $admin): array
    {
        $access = $admin->role->module_access ?? null;
        if (is_string($access)) {
            $decoded = json_decode($access, true);
            $access = is_array($decoded) ? $decoded : [];
        }

        return is_array($access) ? array_values(array_filter($access, 'is_string')) : [];
    }
}
