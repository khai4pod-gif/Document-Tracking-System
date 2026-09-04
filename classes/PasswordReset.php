<?php
/**
 * classes/PasswordReset.php
 * Issues and redeems the single-use links emailed when a user forgets their
 * password. Mirrors LoginOtp: only a hash is stored, links expire quickly,
 * they are single-use, and issuing a new one retires any outstanding link.
 *
 * The stored value is a SHA-256 of a 32-byte random token rather than a
 * bcrypt hash. The token must be looked up by value, which a salted hash
 * cannot do, and a 256-bit random secret has no low-entropy structure to
 * brute force — the property bcrypt exists to protect. Only the hash is
 * written, so a copy of the table cannot reset anyone's password.
 */

declare(strict_types=1);

class PasswordReset
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Hashing is centralised so issuing and lookup can never disagree. */
    private function fingerprint(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Creates a link for this user and returns the plaintext token so it can
     * be emailed. Any outstanding link for the same user is retired, so a
     * second request invalidates the first.
     */
    public function issue(int $userId, string $ip): string
    {
        $this->pdo->prepare(
            "UPDATE password_resets SET consumed_at = NOW()
              WHERE user_id = :u AND consumed_at IS NULL"
        )->execute(['u' => $userId]);

        $token = bin2hex(random_bytes(32));

        $this->pdo->prepare(
            "INSERT INTO password_resets (user_id, token_hash, ip_address, expires_at, created_at)
             VALUES (:u, :hash, :ip, DATE_ADD(NOW(), INTERVAL :ttl SECOND), NOW())"
        )->execute([
            'u'    => $userId,
            'hash' => $this->fingerprint($token),
            'ip'   => $ip,
            'ttl'  => PASSWORD_RESET_TTL_SECONDS,
        ]);

        return $token;
    }

    /**
     * Resolves a token to the account it belongs to, without consuming it —
     * used to decide whether to show the form at all.
     *
     * @return array|null The user row, or null if the link is unusable.
     */
    public function resolve(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT u.* FROM password_resets r
               JOIN users u ON u.id = r.user_id
              WHERE r.token_hash = :hash
                AND r.consumed_at IS NULL
                AND r.expires_at > NOW()
                AND u.is_active = 1
              LIMIT 1"
        );
        $stmt->execute(['hash' => $this->fingerprint($token)]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Sets the new password and burns the link, as one transaction so a
     * failure cannot leave a spent token with the old password still in place.
     *
     * Every other outstanding link for the account is retired too: if the
     * request was triggered by someone else, their link dies with this one.
     *
     * @return bool False if the link was already used, expired, or unknown.
     */
    public function redeem(string $token, string $newPassword): bool
    {
        $user = $this->resolve($token);
        if (!$user) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            // Consume by token, not by user, and require it to still be
            // unused — two simultaneous submissions cannot both succeed.
            $consume = $this->pdo->prepare(
                "UPDATE password_resets SET consumed_at = NOW()
                  WHERE token_hash = :hash AND consumed_at IS NULL AND expires_at > NOW()"
            );
            $consume->execute(['hash' => $this->fingerprint($token)]);

            if ($consume->rowCount() === 0) {
                $this->pdo->rollBack();
                return false;
            }

            $this->pdo->prepare(
                "UPDATE password_resets SET consumed_at = NOW()
                  WHERE user_id = :u AND consumed_at IS NULL"
            )->execute(['u' => (int)$user['id']]);

            $this->pdo->prepare(
                "UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id"
            )->execute([
                'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id'   => (int)$user['id'],
            ]);

            // A pending sign-in code is no longer meaningful once the password
            // behind it has changed.
            $this->pdo->prepare(
                "UPDATE login_otps SET consumed_at = NOW()
                  WHERE user_id = :u AND consumed_at IS NULL"
            )->execute(['u' => (int)$user['id']]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Seconds until this account may request another link, or 0 if it may
     * request one now. Throttles mail volume and repeated requests against a
     * single account.
     */
    public function cooldownRemaining(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(created_at, INTERVAL :cd SECOND))
               FROM password_resets
              WHERE user_id = :u
              ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute(['cd' => PASSWORD_RESET_COOLDOWN, 'u' => $userId]);
        $remaining = $stmt->fetchColumn();

        return $remaining === false ? 0 : max(0, (int)$remaining);
    }
}
