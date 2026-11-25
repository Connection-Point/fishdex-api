<?php

namespace App\Filament\Concerns;

use App\Domain\Admin\Models\Admin;
use App\Domain\Shared\Enums\AdminRole;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Role-based access for Filament resources (doc 10 §2).
 * Resources declare: protected static array $viewRoles, $editRoles; super_admin always passes.
 * Actions listed in $readOnlyActions are allowed for $viewRoles.
 */
trait AuthorizesByAdminRole
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->is_active) {
            return Response::deny();
        }

        $action = $action instanceof UnitEnum ? $action->name : $action;
        $readOnly = in_array($action, ['viewAny', 'view'], true);

        $roles = array_map(fn (string $r) => AdminRole::from($r), $readOnly ? static::viewRoles() : static::editRoles());

        if (! $readOnly && in_array($action, static::forbiddenActions(), true)) {
            return Response::deny();
        }

        return $admin->hasRole(...$roles) ? Response::allow() : Response::deny();
    }

    /** @return list<string> */
    protected static function viewRoles(): array
    {
        return property_exists(static::class, 'viewRoles') ? static::$viewRoles : ['catalogue_editor'];
    }

    /** @return list<string> */
    protected static function editRoles(): array
    {
        return property_exists(static::class, 'editRoles') ? static::$editRoles : ['catalogue_editor'];
    }

    /** @return list<string> */
    protected static function forbiddenActions(): array
    {
        return property_exists(static::class, 'forbiddenActions') ? static::$forbiddenActions : [];
    }
}
