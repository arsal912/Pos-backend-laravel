<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Custom Permission model that always uses the CENTRAL database connection.
 *
 * Without this, when in tenant context (stancl/tenancy switches the default
 * connection to the tenant DB), Spatie would query pos_store_X.permissions
 * which doesn't exist. Permissions are stored in pos_system (central DB).
 */
class Permission extends SpatiePermission
{
    protected $connection = 'mysql'; // always central DB
}
