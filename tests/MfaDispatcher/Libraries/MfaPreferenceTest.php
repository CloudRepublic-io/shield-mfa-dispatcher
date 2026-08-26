<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Libraries;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use MfaDispatcher\Libraries\MfaPreference;

/**
 * Tests MfaPreference's storage directly, via CodeIgniter's own
 * Settings library (per-user context, not a Shield identity record) -
 * see that class's own doc comment for the full explanation of why
 * this changed, including the real, confirmed bug this fixes.
 *
 * testTwoUsersCanChooseTheSamePreferenceWithoutCollision() is the
 * regression test for that bug specifically: an earlier
 * identity-based implementation stored the method key itself in
 * Shield's own auth_identities.secret column, which has a UNIQUE
 * constraint on (type, secret) rather than (user_id, type, secret) -
 * so any two users choosing the SAME method (not just any two users,
 * which testPreferenceIsPerUser below already covered with two
 * DIFFERENT methods) threw a duplicate-key database error on the
 * second user's own, entirely unrelated choice.
 */
final class MfaPreferenceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;

    // DatabaseTestTrait's own default ($namespace = 'Tests\Support')
    // does NOT migrate Shield's own tables - it only looks in that one
    // namespace. null triggers the same behavior as
    // `php spark migrate --all`, which also picks up CodeIgniter's own
    // Settings library migrations (CreateSettingsTable, AddContextColumn)
    // that this class's new storage mechanism depends on.
    protected $namespace = null;

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'preference-test-' . uniqid() . '@example.com',
            'username' => 'preferencetest' . uniqid(),
            'password' => 'secret123456',
        ]);
    }

    private function preference(): MfaPreference
    {
        return new MfaPreference();
    }

    public function testGetReturnsNullForAUserWithNoPreferenceSet(): void
    {
        $user = $this->makeUser();

        $this->assertNull($this->preference()->get($user));
    }

    public function testSetThenGetReturnsTheChosenMethod(): void
    {
        $user = $this->makeUser();

        $this->preference()->set($user, 'whatsapp');

        $this->assertSame('whatsapp', $this->preference()->get($user));
    }

    public function testSetTwiceReplacesRatherThanAccumulating(): void
    {
        $user = $this->makeUser();

        $this->preference()->set($user, 'email');
        $this->preference()->set($user, 'totp');

        // A settings context holds exactly one current value per key,
        // not a history of every value ever set - re-fetching a fresh
        // MfaPreference instance (rather than reusing the same one)
        // rules out the possibility of this only appearing correct
        // because of some in-memory state on the object itself.
        $this->assertSame('totp', $this->preference()->get($user));
    }

    public function testClearRemovesTheStoredPreference(): void
    {
        $user = $this->makeUser();
        $this->preference()->set($user, 'whatsapp');

        $this->preference()->clear($user);

        $this->assertNull($this->preference()->get($user));
    }

    public function testPreferenceIsPerUser(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        $this->preference()->set($userA, 'totp');
        $this->preference()->set($userB, 'email');

        $this->assertSame('totp', $this->preference()->get($userA));
        $this->assertSame('email', $this->preference()->get($userB));
    }

    /**
     * THE regression test for the actual bug being fixed - see this
     * class's own doc comment. Two DIFFERENT users both choosing the
     * SAME method is exactly the scenario the old, identity-based
     * storage broke on; testPreferenceIsPerUser above (using two
     * DIFFERENT methods for two different users) would NOT have caught
     * this, since that scenario never produced two identical (type,
     * secret) rows in the first place.
     */
    public function testTwoUsersCanChooseTheSamePreferenceWithoutCollision(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        // Neither of these should throw - a duplicate-key database
        // exception here would mean the regression is back.
        $this->preference()->set($userA, 'totp');
        $this->preference()->set($userB, 'totp');

        $this->assertSame('totp', $this->preference()->get($userA));
        $this->assertSame('totp', $this->preference()->get($userB));
    }

    /**
     * Same scenario as above, but for three users and including a
     * change-then-re-share sequence, since the original bug could also
     * plausibly resurface specifically on an UPDATE (not just an
     * initial INSERT) depending on how a future implementation might
     * be written.
     */
    public function testMultipleUsersSharingAndChangingPreferencesDoNotCollide(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $userC = $this->makeUser();

        $this->preference()->set($userA, 'totp');
        $this->preference()->set($userB, 'totp');
        $this->preference()->set($userC, 'whatsapp');

        // userB changes their mind to match userC's existing choice.
        $this->preference()->set($userB, 'whatsapp');

        $this->assertSame('totp', $this->preference()->get($userA));
        $this->assertSame('whatsapp', $this->preference()->get($userB));
        $this->assertSame('whatsapp', $this->preference()->get($userC));
    }
}
