<?php
/**
 * classes/LoginOtp.php
 * Issues and verifies the one-time passcodes emailed during login.
 *
 * Codes are stored as hashes, expire quickly, allow a limited number of
 * wrong guesses, and are single-use. Issuing a new code invalidates any
 * outstanding one for that user.
 */

declare(strict_types=1);

class LoginOtp
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Generates a fresh passcode, storing only its hash, and returns the
     * plaintext so it can be emailed. Any previous unused code for this
     * user is retired first.
     */
    public function issue(int $userId, string $ip): string
    {
        $this->pdo->prepare(
            "UPDATE login_otps SET consumed_at = NOW()
             WHERE user_id = :u AND consumed_at IS NULL"
        )->execute(['u' => $userId]);

        $code = $this->generateCode();

        $this->pdo->prepare(
            "INSERT INTO login_otps (user_id, code_hash, ip_address, expires_at, created_at)
             VALUES (:u, :hash, :ip, DATE_ADD(NOW(), INTERVAL :ttl SECOND), NOW())"
        )->execute([
            'u'    => $userId,
            'hash' => password_hash($code, PASSWORD_DEFAULT),
            'ip'   => $ip,
            'ttl'  => OTP_TTL_SECONDS,
        ]);

        return $code;
    }

    /**
     * Checks a submitted code against the user's active passcode.
     *
     * @return array{ok: bool, message: string}
     */
    public function verify(int $userId, string $code): array
    {
        $row = $this->activeRecord($userId);

        if (!$row) {
            return ['ok' => false, 'message' => 'That code has expired or was already used. Please request a new one.'];
        }

        if ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
            $this->consume((int)$row['id']);
            return ['ok' => false, 'message' => 'Too many incorrect attempts. Please request a new code.'];
        }

        if (!password_verify($code, $row['code_hash'])) {
            $this->pdo->prepare("UPDATE login_otps SET attempts = attempts + 1 WHERE id = :id")
                ->execute(['id' => $row['id']]);

            $remaining = OTP_MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
            if ($remaining <= 0) {
                // Retire it now rather than leaving a spent code marked active.
                $this->consume((int)$row['id']);
            }

            return [
                'ok'      => false,
                'message' => $remaining > 0
                    ? 'Incorrect code. You have ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' left.'
                    : 'Too many incorrect attempts. Please request a new code.',
            ];
        }

        $this->consume((int)$row['id']);
        return ['ok' => true, 'message' => ''];
    }

    /**
     * Seconds the user must wait before another code may be sent, or 0 if
     * a resend is allowed now.
     */
    public function resendCooldownRemaining(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
             FROM login_otps WHERE user_id = :u ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['u' => $userId]);
        $age = $stmt->fetchColumn();

        if ($age === false || $age === null) {
            return 0;
        }

        $remaining = OTP_RESEND_COOLDOWN - (int)$age;
        return $remaining > 0 ? $remaining : 0;
    }

    /** Drops any outstanding code, e.g. when the user abandons the login. */
    public function clearFor(int $userId): void
    {
        $this->pdo->prepare(
            "UPDATE login_otps SET consumed_at = NOW()
             WHERE user_id = :u AND consumed_at IS NULL"
        )->execute(['u' => $userId]);
    }

    // -----------------------------------------------------------------

    private function activeRecord(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, code_hash, attempts FROM login_otps
             WHERE user_id = :u AND consumed_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['u' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function consume(int $id): void
    {
        $this->pdo->prepare("UPDATE login_otps SET consumed_at = NOW() WHERE id = :id")
            ->execute(['id' => $id]);
    }

    /** Cryptographically random, zero-padded so every code is full length. */
    private function generateCode(): string
    {
        $max = (10 ** OTP_LENGTH) - 1;
        return str_pad((string)random_int(0, $max), OTP_LENGTH, '0', STR_PAD_LEFT);
    }
}
