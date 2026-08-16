<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Rate limiting for the sign-in form.
 *
 * Staff addresses follow a predictable pattern and the login form accepted
 * guesses as fast as they could be sent, so a single account could be worked
 * through a password list unopposed and nothing anywhere recorded that it had
 * happened. Failures are now counted, and once there are too many in a short
 * window the account stops answering guesses for a while.
 *
 * Counted per email and address together. Per email alone lets anybody lock a
 * colleague out of their own account by failing at it on purpose; per address
 * alone lets one office behind a shared connection lock out the rest.
 *
 * Successful sign-ins clear the count, so the limit is never felt by somebody
 * who simply mistyped once or twice.
 */
class LoginThrottle {
    /** Failures allowed inside the window before the door closes. */
    const MAX_ATTEMPTS = 5;
    /** How long failures are remembered, and how long a lockout lasts. */
    const WINDOW_SECONDS = 900; // 15 minutes

    private PDO $db;
    /** Cached answer to "has migration 004 run?", per request. */
    private ?bool $hasTable = null;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /**
     * Seconds a caller must wait before another attempt is accepted. Zero means
     * they may try now.
     *
     * Pure, so the boundary can be tested without waiting fifteen minutes. The
     * lockout runs from the most recent failure, not the first: somebody still
     * guessing keeps the door shut, while somebody who walked away finds it open
     * again once the window has passed.
     */
    public static function secondsToWait(
        int $failures,
        ?string $lastFailureAt,
        ?string $now = null,
        int $maxAttempts = self::MAX_ATTEMPTS,
        int $windowSeconds = self::WINDOW_SECONDS
    ): int {
        if ($failures < $maxAttempts || $lastFailureAt === null) {
            return 0;
        }

        $nowTs  = strtotime($now ?? 'now');
        $lastTs = strtotime($lastFailureAt);
        if ($nowTs === false || $lastTs === false) {
            return 0;
        }

        $remaining = ($lastTs + $windowSeconds) - $nowTs;
        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * How a wait reads to somebody staring at the form.
     */
    public static function waitLabel(int $seconds): string {
        if ($seconds <= 60) {
            return 'a minute';
        }
        $minutes = (int)ceil($seconds / 60);
        return $minutes . ' minutes';
    }

    /**
     * Whether the attempts table exists.
     *
     * An installation that has pulled this code without running migration 004
     * must still be able to sign in - refusing every attempt because the audit
     * table is missing would lock the organisation out of its own system. Until
     * the migration is applied, throttling is simply inactive.
     */
    private function tableAvailable(): bool {
        if ($this->hasTable === null) {
            try {
                $stmt = $this->db->query("SHOW TABLES LIKE 'login_attempts'");
                $this->hasTable = $stmt !== false && $stmt->fetch() !== false;
            } catch (Throwable $e) {
                $this->hasTable = false;
            }
        }
        return $this->hasTable;
    }

    /**
     * Recent failures for an email and address.
     *
     * @return array{failures:int, last_at:string|null}
     */
    public function recentFailures(string $email, string $ipAddress): array {
        if (!$this->tableAvailable()) {
            return ['failures' => 0, 'last_at' => null];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) AS failures, MAX(attempted_at) AS last_at
                FROM login_attempts
                WHERE email = :email
                  AND ip_address = :ip
                  AND attempted_at >= (NOW() - INTERVAL " . self::WINDOW_SECONDS . " SECOND)
            ");
            $stmt->execute([
                'email'  => $email,
                'ip'     => $ipAddress,
            ]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            return ['failures' => 0, 'last_at' => null];
        }

        return [
            'failures' => (int)($row['failures'] ?? 0),
            'last_at'  => $row['last_at'] ?? null,
        ];
    }

    /**
     * Seconds this caller must wait, zero when they may try now.
     */
    public function secondsToWaitFor(string $email, string $ipAddress): int {
        $recent = $this->recentFailures($email, $ipAddress);
        return self::secondsToWait($recent['failures'], $recent['last_at']);
    }

    /**
     * Record a failed attempt. Never throws: a throttling problem must not stop
     * the form telling somebody their password was wrong.
     */
    public function recordFailure(string $email, string $ipAddress): void {
        if (!$this->tableAvailable()) {
            return;
        }
        try {
            $stmt = $this->db->prepare("
                INSERT INTO login_attempts (email, ip_address) VALUES (:email, :ip)
            ");
            $stmt->execute(['email' => $email, 'ip' => $ipAddress]);
        } catch (Throwable $e) {
            // Deliberately swallowed.
        }
    }

    /**
     * Forget this caller's failures after a successful sign-in, and sweep away
     * anything older than the window so the table stays small on its own.
     */
    public function clear(string $email, string $ipAddress): void {
        if (!$this->tableAvailable()) {
            return;
        }
        try {
            $stmt = $this->db->prepare("
                DELETE FROM login_attempts
                WHERE (email = :email AND ip_address = :ip)
                   OR attempted_at < (NOW() - INTERVAL " . self::WINDOW_SECONDS . " SECOND)
            ");
            $stmt->execute([
                'email'  => $email,
                'ip'     => $ipAddress,
            ]);
        } catch (Throwable $e) {
            // Deliberately swallowed.
        }
    }

    /**
     * The caller's address, as far as it can be trusted.
     *
     * Proxy headers are ignored on purpose: they are attacker-controlled unless
     * a reverse proxy is known to rewrite them, and trusting them would let one
     * caller spread their guesses across a million invented addresses.
     */
    public static function callerAddress(array $server): string {
        $address = $server['REMOTE_ADDR'] ?? '';
        return is_string($address) && $address !== '' ? substr($address, 0, 45) : 'unknown';
    }
}
