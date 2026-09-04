<?php

namespace App\Services\Auth;

use RuntimeException;

class LdapAuthenticationService
{
    /**
     * @return array{username: string, display_name: string, email: string, groups: list<string>}|null
     */
    public function authenticate(string $username, string $password): ?array
    {
        $this->assertConfigured();

        if (! extension_loaded('ldap')) {
            throw new RuntimeException('La extensión LDAP de PHP no está instalada en el contenedor del portal.');
        }

        $username = trim($username);
        if ($username === '' || $password === '') {
            return null;
        }

        $connection = @ldap_connect((string) config('ldap.host'), (int) config('ldap.port'));
        if ($connection === false) {
            throw new RuntimeException('No se pudo inicializar la conexión con el servidor LDAP.');
        }

        try {
            ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
            if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
                ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, (int) config('ldap.timeout', 10));
            }

            if (! @ldap_bind($connection, $this->bindUsername($username), $password)) {
                if (ldap_errno($connection) === 49) {
                    return null;
                }

                throw new RuntimeException('LDAP rechazó la conexión: '.ldap_error($connection));
            }

            $account = $this->accountName($username);
            $escapedAccount = ldap_escape($account, '', LDAP_ESCAPE_FILTER);
            $search = @ldap_search(
                $connection,
                (string) config('ldap.base_dn'),
                "(sAMAccountName={$escapedAccount})",
                ['cn', 'samaccountname', 'mail', 'memberof'],
            );

            if ($search === false) {
                throw new RuntimeException('No se pudo consultar la información del usuario en LDAP.');
            }

            $entries = ldap_get_entries($connection, $search);
            if (! is_array($entries) || (int) ($entries['count'] ?? 0) < 1) {
                return null;
            }

            $entry = $entries[0];
            if (! is_array($entry)) {
                return null;
            }

            $groups = $this->memberOf($entry);
            if (! $this->belongsToAllowedGroup($groups)) {
                return null;
            }

            $canonicalUsername = trim((string) ($entry['samaccountname'][0] ?? $account));
            $displayName = trim((string) ($entry['cn'][0] ?? $canonicalUsername));
            $email = trim((string) ($entry['mail'][0] ?? ''));

            return [
                'username' => $canonicalUsername,
                'display_name' => $displayName !== '' ? $displayName : $canonicalUsername,
                'email' => $email,
                'groups' => $groups,
            ];
        } finally {
            @ldap_unbind($connection);
        }
    }

    private function bindUsername(string $username): string
    {
        if (str_contains($username, '\\') || str_contains($username, '@')) {
            return $username;
        }

        $domain = trim((string) config('ldap.domain'));

        return $domain !== '' ? $domain.'\\'.$username : $username;
    }

    private function accountName(string $username): string
    {
        if (str_contains($username, '\\')) {
            $parts = explode('\\', $username);

            return trim((string) end($parts));
        }

        if (str_contains($username, '@')) {
            return trim((string) explode('@', $username, 2)[0]);
        }

        return trim($username);
    }

    /** @param array<string|int, mixed> $entry @return list<string> */
    private function memberOf(array $entry): array
    {
        $memberOf = $entry['memberof'] ?? [];
        if (! is_array($memberOf)) {
            return [];
        }

        $groups = [];
        foreach ($memberOf as $key => $group) {
            if (is_int($key) && is_string($group) && $group !== '') {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /** @param list<string> $groups */
    private function belongsToAllowedGroup(array $groups): bool
    {
        $allowedGroup = config('ldap.allowed_group');
        if (! is_string($allowedGroup) || trim($allowedGroup) === '') {
            return true;
        }

        foreach ($groups as $group) {
            if (strcasecmp($group, $allowedGroup) === 0) {
                return true;
            }
        }

        return false;
    }

    private function assertConfigured(): void
    {
        foreach (['host', 'base_dn'] as $key) {
            if (blank(config('ldap.'.$key))) {
                throw new RuntimeException('LDAP no está configurado completamente en portal.env.');
            }
        }
    }
}
