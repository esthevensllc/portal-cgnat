<?php

namespace App\Services\ClickHouse;

class PortalAuthorizationRepository
{
    public function __construct(private readonly PortalStoreService $store) {}

    public function can(string $username, string $permission): bool
    {
        return in_array($permission, $this->permissions($username), true);
    }

    /** @return list<string> */
    public function permissions(string $username): array
    {
        $rows = $this->store->select(<<<'SQL'
SELECT permission
FROM
(
    SELECT lower(username) AS username
    FROM portal_cgnat.users
    WHERE lower(username) = lower({username:String})
    GROUP BY username
    HAVING argMax(is_active, version) = 1
) AS active_users
INNER JOIN
(
    SELECT lower(username) AS username, role_code
    FROM portal_cgnat.user_roles
    WHERE lower(username) = lower({username:String})
    GROUP BY username, role_code
    HAVING argMax(is_active, version) = 1
) AS user_roles USING (username)
INNER JOIN
(
    SELECT role_code, permission
    FROM portal_cgnat.role_permissions
    GROUP BY role_code, permission
    HAVING argMax(is_active, version) = 1
) AS permissions USING (role_code)
ORDER BY permission
SQL, ['param_username' => $username]);

        return collect($rows)
            ->pluck('permission')
            ->filter(fn (mixed $permission): bool => is_string($permission))
            ->values()
            ->all();
    }
}
