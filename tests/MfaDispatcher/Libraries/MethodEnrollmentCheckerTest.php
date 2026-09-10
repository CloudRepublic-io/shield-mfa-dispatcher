<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Libraries;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use MfaDispatcher\Libraries\MethodEnrollmentChecker;
use Tests\MfaDispatcher\Support\FakeCustomMethodStore;

/**
 * Tests MethodEnrollmentChecker directly, in isolation. Uses fakes/
 * closures throughout for the custom-checker coverage - no real
 * TotpMfa/WhatsAppMfa/PasskeyMfa package needed, since 'totp'/
 * 'whatsapp'/'passkey' simply aren't installed in this package's own
 * isolated test environment, and isAvailable() correctly reflects
 * that (covered below) rather than needing those packages present.
 */
final class MethodEnrollmentCheckerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;

    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();

        config('MfaDispatcher')->customEnrollmentCheckers = [];
        FakeCustomMethodStore::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        FakeCustomMethodStore::reset();
    }

    private function makeUser(): User
    {
        return fake(UserModel::class);
    }

    public function testEmailIsAlwaysAvailableAndEnrolled(): void
    {
        $checker = new MethodEnrollmentChecker();
        $user    = $this->makeUser();

        $this->assertTrue($checker->isAvailable('email'));
        $this->assertTrue($checker->isEnrolled('email', $user));
    }

    public function testAnUnrecognizedMethodWithNoCustomCheckerIsNeitherAvailableNorEnrolled(): void
    {
        $checker = new MethodEnrollmentChecker();
        $user    = $this->makeUser();

        $this->assertFalse($checker->isAvailable('yubikey'));
        $this->assertFalse($checker->isEnrolled('yubikey', $user));
    }

    // -------------------------------------------------------------------
    // Config\MfaDispatcher::$customEnrollmentCheckers - THE fix for a
    // confirmed, real gap: a developer's own custom method used in
    // $requiredMethodsForGroups had no way to ever resolve as enrolled,
    // regardless of what that method's own store actually said.
    // -------------------------------------------------------------------

    public function testACustomMethodWithARegisteredCheckerIsAvailable(): void
    {
        config('MfaDispatcher')->customEnrollmentCheckers = [
            'yubikey' => static fn (User $user): bool => true,
        ];

        $checker = new MethodEnrollmentChecker();

        $this->assertTrue($checker->isAvailable('yubikey'));
    }

    public function testACustomCheckerReturningTrueIsReflectedByIsEnrolled(): void
    {
        config('MfaDispatcher')->customEnrollmentCheckers = [
            'yubikey' => static fn (User $user): bool => true,
        ];

        $checker = new MethodEnrollmentChecker();
        $user    = $this->makeUser();

        $this->assertTrue($checker->isEnrolled('yubikey', $user));
    }

    public function testACustomCheckerReturningFalseIsReflectedByIsEnrolled(): void
    {
        config('MfaDispatcher')->customEnrollmentCheckers = [
            'yubikey' => static fn (User $user): bool => false,
        ];

        $checker = new MethodEnrollmentChecker();
        $user    = $this->makeUser();

        $this->assertFalse($checker->isEnrolled('yubikey', $user));
    }

    /**
     * Confirms the custom checker actually receives the SAME user
     * being checked, not some other value - a closure capturing the
     * expected user_id and asserting on it directly is a more direct
     * confirmation than only checking the boolean result.
     */
    public function testTheCustomCheckerReceivesTheCorrectUser(): void
    {
        $user            = $this->makeUser();
        $receivedUserIds = [];

        config('MfaDispatcher')->customEnrollmentCheckers = [
            'yubikey' => static function (User $receivedUser) use (&$receivedUserIds): bool {
                $receivedUserIds[] = $receivedUser->id;

                return true;
            },
        ];

        $checker = new MethodEnrollmentChecker();
        $checker->isEnrolled('yubikey', $user);

        $this->assertSame([$user->id], $receivedUserIds);
    }

    /**
     * THE recommended form, confirmed working end to end - see
     * Config\MfaDispatcher::$customEnrollmentCheckers's own, corrected
     * doc comment for why a [ClassName::class, 'staticMethodName'] pair
     * is recommended over a Closure: unlike a Closure, it's a valid
     * compile-time constant, usable directly as that property's own
     * default value with no constructor workaround needed - a real
     * report confirmed a Closure moved into a constructor still didn't
     * resolve reliably in practice.
     */
    public function testTheRecommendedStaticMethodReferenceFormWorksCorrectly(): void
    {
        config('MfaDispatcher')->customEnrollmentCheckers = [
            'yubikey' => [FakeCustomMethodStore::class, 'checkEnrollment'],
        ];

        $checker = new MethodEnrollmentChecker();
        $user    = $this->makeUser();

        $this->assertFalse($checker->isEnrolled('yubikey', $user));

        FakeCustomMethodStore::$enrolledUserIds[$user->id] = true;

        $this->assertTrue($checker->isEnrolled('yubikey', $user));
    }

    /**
     * THE regression test for the fix to my own fix: a custom checker
     * registered under an already-natively-known method key must NOT
     * override that method's own, already-tested answer - confirmed
     * here specifically for 'totp', which (with no real TotpMfa
     * package installed in this test environment) natively resolves to
     * "not available" via class_exists() - a custom checker registered
     * under the same key must not be able to override that to "true".
     */
    public function testACustomCheckerCannotOverrideANativelyKnownMethod(): void
    {
        config('MfaDispatcher')->customEnrollmentCheckers = [
            'totp' => static fn (User $user): bool => true,
        ];

        $checker = new MethodEnrollmentChecker();
        $user    = $this->makeUser();

        // TotpMfa isn't installed in this package's own isolated test
        // environment, so the native check must still say "not
        // available"/"not enrolled" - unaffected by the custom entry
        // above.
        $this->assertFalse($checker->isAvailable('totp'));
        $this->assertFalse($checker->isEnrolled('totp', $user));
    }
}
