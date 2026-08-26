<?php

declare(strict_types=1);

namespace MfaDispatcher\Libraries;

use CodeIgniter\Shield\Entities\User;

/**
 * Stores each user's chosen MFA method via CodeIgniter's own Settings
 * library (https://settings.codeigniter.com/ - the same library Shield
 * itself uses for its permissions matrix, already installed and
 * migrated in any Shield app), using a per-user CONTEXT rather than as
 * a Shield "identity" record.
 *
 * CONFIRMED, REAL BUG THIS FIXES: an earlier version stored the
 * preference as an identity record with the METHOD KEY itself in the
 * 'secret' column (e.g. 'totp'). Shield's own auth_identities table
 * has a UNIQUE constraint on (type, secret) - NOT (user_id, type,
 * secret) - so any two users choosing the SAME method (e.g. both
 * picking 'totp') collided directly on that constraint, throwing a
 * duplicate-key database error on the second user's own, entirely
 * unrelated choice. This is Shield's own base schema, not something
 * this package should (or safely could) alter.
 *
 * Settings' own per-user CONTEXT mechanism sidesteps this entirely -
 * confirmed via Settings' own documentation as the first-class,
 * recommended way to "save settings on a user-by-user basis" (their
 * own example is a per-user theme preference, structurally identical
 * to what this class needs): each user's value is stored and looked up
 * under its own context string, with no shared uniqueness constraint
 * between different users' rows at all - two users can both hold the
 * value 'totp' simultaneously without any collision, since they're
 * stored as entirely separate rows scoped by context, not sharing a
 * single (type, secret) pair the way the identity-based approach did.
 *
 * Contexts fall back to a general (non-contextual) value, and then to
 * a config file default, if neither the context nor the general value
 * exists - neither of those fallback layers is used here (this
 * package's own Config\MfaDispatcher declares no matching property),
 * so a user who has never chosen a preference simply gets null, the
 * same behavior the previous, identity-based implementation had.
 */
class MfaPreference
{
    private const SETTING_KEY = 'MfaDispatcher.preference';

    private function contextFor(User $user): string
    {
        return 'user:' . $user->id;
    }

    /**
     * @return string|null The method key, or null if the user has
     *                      never chosen one.
     */
    public function get(User $user): ?string
    {
        $value = service('settings')->get(self::SETTING_KEY, $this->contextFor($user));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(User $user, string $method): void
    {
        service('settings')->set(self::SETTING_KEY, $method, $this->contextFor($user));
    }

    public function clear(User $user): void
    {
        service('settings')->forget(self::SETTING_KEY, $this->contextFor($user));
    }
}
