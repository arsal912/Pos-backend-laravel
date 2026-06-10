<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Custom Role model that always uses the CENTRAL database connection.
 *
 * Same reason as Permission — roles live in pos_system, not in tenant DBs.
 */
class Role extends SpatieRole
{
    protected $connection = 'mysql'; // always central DB
}
