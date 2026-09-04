<?php

declare(strict_types=1);

final class PasswordResetTest extends TestCase
{
    private PasswordReset $reset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reset = new PasswordReset($this->pdo());
    }

    private function passwordHash(int $userId): string
    {
        $stmt = $this->pdo()->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (string)$stmt->fetchColumn();
    }

    private function tokenCountFor(int $userId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    public function testIssueStoresOnlyAHashNeverThePlaintext(): void
    {
        $token = $this->reset->issue($this->deptUserA, '127.0.0.1');

        $stmt = $this->pdo()->prepare('SELECT token_hash FROM password_resets WHERE user_id = :id');
        $stmt->execute(['id' => $this->deptUserA]);
        $stored = $stmt->fetchColumn();

        $this->assertNotSame($token, $stored);
        $this->assertSame(hash('sha256', $token), $stored);
    }

    public function testIssuingASecondLinkRetiresTheFirst(): void
    {
        $first = $this->reset->issue($this->deptUserA, '127.0.0.1');
        $this->reset->issue($this->deptUserA, '127.0.0.1');

        $this->assertNull($this->reset->resolve($first), 'The first link must stop working once a second is issued.');
    }

    public function testResolveAcceptsAFreshValidToken(): void
    {
        $token = $this->reset->issue($this->deptUserA, '127.0.0.1');

        $user = $this->reset->resolve($token);

        $this->assertNotNull($user);
        $this->assertSame($this->deptUserA, (int)$user['id']);
    }

    public function testResolveRejectsAnEmptyOrUnknownToken(): void
    {
        $this->assertNull($this->reset->resolve(''));
        $this->assertNull($this->reset->resolve('0000000000000000000000000000000000000000000000000000000000000000'));
    }

    public function testResolveRejectsAnExpiredToken(): void
    {
        $token = $this->reset->issue($this->deptUserA, '127.0.0.1');
        $this->pdo()->exec(
            "UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
              WHERE user_id = {$this->deptUserA}"
        );

        $this->assertNull($this->reset->resolve($token));
    }

    public function testResolveRejectsATokenForADeactivatedAccount(): void
    {
        $token = $this->reset->issue($this->deptUserA, '127.0.0.1');
        $this->pdo()->exec("UPDATE users SET is_active = 0 WHERE id = {$this->deptUserA}");

        $this->assertNull($this->reset->resolve($token));
    }

    public function testRedeemChangesThePasswordAndConsumesTheLink(): void
    {
        $token = $this->reset->issue($this->deptUserA, '127.0.0.1');
        $before = $this->passwordHash($this->deptUserA);

        $this->assertTrue($this->reset->redeem($token, 'BrandNewPassw0rd!'));

        $after = $this->passwordHash($this->deptUserA);
        $this->assertNotSame($before, $after);
        $this->assertTrue(password_verify('BrandNewPassw0rd!', $after));
    }

    public function testRedeemCannotBeUsedTwice(): void
    {
        $token = $this->reset->issue($this->deptUserA, '127.0.0.1');
        $this->assertTrue($this->reset->redeem($token, 'FirstPassw0rd!'));

        // Simulates two submissions racing for the same token: the atomic
        // UPDATE ... WHERE consumed_at IS NULL means only one can ever win,
        // and this is the observable, single-threaded proxy for that —
        // the second attempt with an already-spent token must fail.
        $this->assertFalse($this->reset->redeem($token, 'SecondPassw0rd!'));

        $this->assertTrue(password_verify('FirstPassw0rd!', $this->passwordHash($this->deptUserA)));
    }

    public function testRedeemRejectsAnExpiredToken(): void
    {
        $token = $this->reset->issue($this->deptUserA, '127.0.0.1');
        $this->pdo()->exec(
            "UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
              WHERE user_id = {$this->deptUserA}"
        );

        $this->assertFalse($this->reset->redeem($token, 'NewPassw0rd!'));
    }

    public function testRedeemRetiresEveryOtherOutstandingLinkForTheAccount(): void
    {
        // Two links can coexist directly in the table (issue() only retires
        // the previous one at issue time) — e.g. one requested, then the
        // account edited elsewhere in a way that inserts a second row.
        $tokenA = $this->reset->issue($this->deptUserA, '127.0.0.1');
        $stmt = $this->pdo()->prepare(
            "INSERT INTO password_resets (user_id, token_hash, ip_address, expires_at)
             VALUES (:u, :hash, '127.0.0.1', DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
        );
        $tokenB = bin2hex(random_bytes(32));
        $stmt->execute(['u' => $this->deptUserA, 'hash' => hash('sha256', $tokenB)]);

        $this->assertTrue($this->reset->redeem($tokenA, 'NewPassw0rd!'));

        $this->assertNull($this->reset->resolve($tokenB), 'Redeeming one link must retire every other outstanding link too.');
    }

    public function testRedeemClearsAnyPendingLoginOtp(): void
    {
        $otpStmt = $this->pdo()->prepare(
            "INSERT INTO login_otps (user_id, code_hash, ip_address, expires_at)
             VALUES (:u, :hash, '127.0.0.1', DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
        );
        $otpStmt->execute(['u' => $this->deptUserA, 'hash' => hash('sha256', '123456')]);

        $token = $this->reset->issue($this->deptUserA, '127.0.0.1');
        $this->reset->redeem($token, 'NewPassw0rd!');

        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM login_otps WHERE user_id = :u AND consumed_at IS NULL'
        );
        $stmt->execute(['u' => $this->deptUserA]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'A password change must invalidate any pending sign-in code.');
    }

    public function testCooldownRemainingIsNonzeroRightAfterIssuingAndZeroLater(): void
    {
        $this->reset->issue($this->deptUserA, '127.0.0.1');
        $this->assertGreaterThan(0, $this->reset->cooldownRemaining($this->deptUserA));

        $this->pdo()->exec(
            "UPDATE password_resets SET created_at = DATE_SUB(NOW(), INTERVAL 1 HOUR)
              WHERE user_id = {$this->deptUserA}"
        );
        $this->assertSame(0, $this->reset->cooldownRemaining($this->deptUserA));
    }

    public function testCooldownRemainingIsZeroWithNoPriorRequest(): void
    {
        $this->assertSame(0, $this->reset->cooldownRemaining($this->deptUserA));
    }

    public function testIssuingAndRedeemingDoesNotAffectAnotherUsersToken(): void
    {
        $tokenA = $this->reset->issue($this->deptUserA, '127.0.0.1');
        $tokenB = $this->reset->issue($this->deptUserB, '127.0.0.1');

        $this->assertTrue($this->reset->redeem($tokenA, 'NewPassw0rd!'));
        $this->assertNotNull($this->reset->resolve($tokenB), "Redeeming one user's link must not touch another user's.");
        $this->assertSame(1, $this->tokenCountFor($this->deptUserB));
    }
}
