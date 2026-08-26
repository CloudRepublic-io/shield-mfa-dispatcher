<?php

declare(strict_types=1);

namespace MfaDispatcher\Libraries;

use CodeIgniter\Shield\Entities\User;

/**
 * One shared "is this method's package installed, and has this user
 * actually set it up" check per method key - used by
 * MfaDispatcher::resolveAction() to decide whether a user in a group
 * with a required method (Config\MfaDispatcher::$requiredMethodsForGroups)
 * needs to be routed into forced setup.
 *
 * Deliberately NOT used to refactor MfaSettingsController's own
 * totpLibraryAvailable()/whatsappLibraryAvailable() checks, even
 * though they do the same job - those are already working and tested;
 * this class exists for the new group-based logic specifically, not
 * to eliminate that (small, and separately verified) duplication.
 *
 * 'email' is always considered both available and enrolled - it's
 * Shield's own built-in Email2FA, needs no setup, and has no optional
 * package that could be missing.
 */
class MethodEnrollmentChecker
{
    public function isAvailable(string $method): bool
    {
        return match ($method) {
            'email'    => true,
            'totp'     => class_exists('\TotpMfa\Libraries\TotpIdentityStore'),
            'whatsapp' => class_exists('\WhatsAppMfa\Libraries\PhoneNumberStore'),
            'passkey'  => class_exists('\PasskeyMfa\Libraries\PasskeyIdentityStore'),
            default    => false,
        };
    }

    /**
     * Has this specific user actually set this method up? Returns
     * false (not an error) for a method that isn't installed at all -
     * "not enrolled" is the correct answer either way from a caller's
     * perspective, except for 'email', which is always true regardless.
     */
    public function isEnrolled(string $method, User $user): bool
    {
        if ($method === 'email') {
            return true;
        }

        if ($method === 'totp' && $this->isAvailable('totp')) {
            $class = '\TotpMfa\Libraries\TotpIdentityStore';
            $store = new $class();

            return $store->hasEnrolled($user);
        }

        if ($method === 'whatsapp' && $this->isAvailable('whatsapp')) {
            $class = '\WhatsAppMfa\Libraries\PhoneNumberStore';
            $store = new $class();

            return $store->hasVerifiedPhoneNumber($user);
        }

        if ($method === 'passkey' && $this->isAvailable('passkey')) {
            $class = '\PasskeyMfa\Libraries\PasskeyIdentityStore';
            $store = new $class();

            return $store->hasEnrolled($user);
        }

        return false;
    }
}
