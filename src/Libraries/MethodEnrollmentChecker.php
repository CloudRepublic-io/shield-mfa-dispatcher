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
     *
     * DIAGNOSTIC LOGGING below, alongside
     * MfaDispatcher::resolveRequiredMethod()'s own - a real report
     * showed a user routed back into forced setup despite already
     * having a stored credential. Logged specifically at the two
     * distinct places a false result could come from
     * (isAvailable() being false vs. the store's own hasEnrolled()/
     * hasVerifiedPhoneNumber() being false) so those two genuinely
     * different causes - "this package isn't being detected as
     * installed at all" vs. "the database genuinely has no matching
     * row for this user" - aren't conflated into one ambiguous log
     * line. Gated to only ever write when ENVIRONMENT is 'development'
     * (see DiagnosticLog's own doc comment) so this never accumulates
     * user_id values in a production log from ordinary logins.
     */
    public function isEnrolled(string $method, User $user): bool
    {
        if ($method === 'email') {
            return true;
        }

        if (! $this->isAvailable($method)) {
            DiagnosticLog::write(
                'info',
                'MethodEnrollmentChecker isEnrolled: method "{method}" is not available (isAvailable() returned false) for user_id {user_id} - the matching class was not detected via class_exists().',
                ['method' => $method, 'user_id' => $user->id]
            );

            return false;
        }

        if ($method === 'totp') {
            $class    = '\TotpMfa\Libraries\TotpIdentityStore';
            $store    = new $class();
            $enrolled = $store->hasEnrolled($user);

            DiagnosticLog::write('info', 'MethodEnrollmentChecker isEnrolled: TotpIdentityStore::hasEnrolled() returned {enrolled} for user_id {user_id}.', ['enrolled' => $enrolled ? 'true' : 'false', 'user_id' => $user->id]);

            return $enrolled;
        }

        if ($method === 'whatsapp') {
            $class    = '\WhatsAppMfa\Libraries\PhoneNumberStore';
            $store    = new $class();
            $enrolled = $store->hasVerifiedPhoneNumber($user);

            DiagnosticLog::write('info', 'MethodEnrollmentChecker isEnrolled: PhoneNumberStore::hasVerifiedPhoneNumber() returned {enrolled} for user_id {user_id}.', ['enrolled' => $enrolled ? 'true' : 'false', 'user_id' => $user->id]);

            return $enrolled;
        }

        if ($method === 'passkey') {
            $class    = '\PasskeyMfa\Libraries\PasskeyIdentityStore';
            $store    = new $class();
            $enrolled = $store->hasEnrolled($user);

            DiagnosticLog::write('info', 'MethodEnrollmentChecker isEnrolled: PasskeyIdentityStore::hasEnrolled() returned {enrolled} for user_id {user_id}.', ['enrolled' => $enrolled ? 'true' : 'false', 'user_id' => $user->id]);

            return $enrolled;
        }

        DiagnosticLog::write('info', 'MethodEnrollmentChecker isEnrolled: unrecognized method key "{method}" for user_id {user_id} - returning false.', ['method' => $method, 'user_id' => $user->id]);

        return false;
    }
}
