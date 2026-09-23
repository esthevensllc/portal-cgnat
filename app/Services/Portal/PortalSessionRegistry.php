<?php

namespace App\Services\Portal;

use App\Services\ClickHouse\PortalStoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PortalSessionRegistry
{
    public function __construct(private readonly PortalStoreService $store) {}

    public function claim(Request $request, string $username, bool $replace = false): bool
    {
        $username = $this->username($username);

        return $this->locked($username, function () use ($request, $username, $replace): bool {
            $current = $this->current($username);
            if (! $replace && $this->isConnected($current)) {
                return false;
            }

            $request->session()->regenerate();
            $this->write($username, $request->session()->getId(), true, (int) ($current['version'] ?? 0) + 1);

            return true;
        });
    }

    public function refresh(Request $request, string $username): bool
    {
        $username = $this->username($username);

        return $this->locked($username, function () use ($request, $username): bool {
            $current = $this->current($username);
            if (! $this->isConnected($current) || ! hash_equals((string) $current['session_hash'], $this->sessionHash($request->session()->getId()))) {
                return false;
            }

            $this->write($username, $request->session()->getId(), true, (int) $current['version'] + 1);

            return true;
        });
    }

    public function release(Request $request, string $username): void
    {
        $username = $this->username($username);

        $this->locked($username, function () use ($request, $username): void {
            $current = $this->current($username);
            if ($current !== null && hash_equals((string) $current['session_hash'], $this->sessionHash($request->session()->getId()))) {
                $this->write($username, $request->session()->getId(), false, (int) $current['version'] + 1);
            }
        });
    }

    /** @return array<string, mixed>|null */
    private function current(string $username): ?array
    {
        return $this->store->select(<<<'SQL'
SELECT session_hash, connected, expires_at, version
FROM portal_cgnat.user_sessions
WHERE username = {username:String}
ORDER BY version DESC
LIMIT 1
SQL, ['param_username' => $username])[0] ?? null;
    }

    /** @param array<string, mixed>|null $current */
    private function isConnected(?array $current): bool
    {
        return $current !== null
            && (int) $current['connected'] === 1
            && (string) $current['expires_at'] > now()->utc()->format('Y-m-d H:i:s.v');
    }

    private function write(string $username, string $sessionId, bool $connected, int $version): void
    {
        $this->store->insertJson('user_sessions', [
            'username' => $username,
            'session_hash' => $this->sessionHash($sessionId),
            'connected' => $connected ? 1 : 0,
            'last_seen_at' => now()->utc()->format('Y-m-d H:i:s.v'),
            'expires_at' => now()->addMinutes((int) config('session.lifetime', 120))->utc()->format('Y-m-d H:i:s.v'),
            'version' => $version,
        ]);
    }

    private function sessionHash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    private function username(string $username): string
    {
        return Str::lower(trim($username));
    }

    private function locked(string $username, callable $callback): mixed
    {
        return Cache::lock('portal-session:'.hash('sha256', $username), 60)->block(10, $callback);
    }
}
