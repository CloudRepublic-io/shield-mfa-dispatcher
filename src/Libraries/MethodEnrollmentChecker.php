<?php

declare(strict_types=1);

namespace MfaDispatcher\Libraries;

use CodeIgniter\Shield\Entities\User;
use Config\MfaDispatcher as MfaDispatcherConfig;

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
 *
 * EXTENSIBLE via Config\MfaDispatcher::$customEnrollmentCheckers -
 * CONFIRMED, REAL GAP FIXED HERE: this class previously only knew how
 * to check the four method keys this package and its sibling packages
 * already recognize natively. A developer's own custom method, used in
 * $requiredMethodsForGroups without a matching entry there, would
 * always resolve as "not enrolled" here - routing every affected user
 * into forced setup on every single login, forever, even immediately
 * after they'd genuinely completed it. See that config property's own
 * doc comment for the full explanation and example.
 */
class MethodEnrollmentChecker
{
    protected MfaDispatcherConfig $config;

    public function __construct()
    {
        $this->config = config('MfaDispatcher');
    }

    public function isAvailable(string $method): bool
    {
        $native = match ($method) {
            'email'    => true,
            'totp'     => class_exists('\TotpMfa\Libraries\TotpIdentityStore'),
            'whatsapp' => class_exists('\WhatsAppMfa\Libraries\PhoneNumberStore'),
            'passkey'  => class_exists('\PasskeyMfa\Libraries\PasskeyIdentityStore'),
            default    => null,
        };

        if ($native !== null) {
            return $native;
        }

        // Only reached for a method key none of the four cases above
        // matched - a registered custom checker never overrides a
        // natively-known method's own, already-tested answer.
        return isset($this->config->customEnrollmentCheckers[$method]);
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
     * having a stored credential. Logged specifically at the distinct
     * places a false result could come from (isAvailable() being false
     * vs. a store's own hasEnrolled()/hasVerifiedPhoneNumber()/custom
     * checker being false) so those genuinely different causes -
     * "this package isn't being detected as installed at all" vs. "the
     * database genuinely has no matching row for this user" - aren't
     * conflated into one ambiguous log line. Gated to only ever write
     * when ENVIRONMENT is 'development' (see DiagnosticLog's own doc
     * comment) so this never accumulates user_id values in a
     * production log from ordinary logins.
     */
    public function isEnrolled(string $method, User $user): bool
    {
        if ($method === 'email') {
            return true;
        }

        if ($method === 'totp' || $method === 'whatsapp' || $method === 'passkey') {
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

            // Only 'passkey' left in this group.
            $class    = '\PasskeyMfa\Libraries\PasskeyIdentityStore';
            $store    = new $class();
            $enrolled = $store->hasEnrolled($user);

            DiagnosticLog::write('info', 'MethodEnrollmentChecker isEnrolled: PasskeyIdentityStore::hasEnrolled() returned {enrolled} for user_id {user_id}.', ['enrolled' => $enrolled ? 'true' : 'false', 'user_id' => $user->id]);

            return $enrolled;
        }

        // Not one of the four natively-known method keys - fall back
        // to a registered custom checker, if any (see
        // Config\MfaDispatcher::$customEnrollmentCheckers's own doc
        // comment). A custom checker registered under 'totp'/
        // 'whatsapp'/'passkey'/'email' is deliberately unreachable -
        // those are always resolved via the branches above instead,
        // never overridden.
        if (isset($this->config->customEnrollmentCheckers[$method])) {
            $enrolled = (bool) ($this->config->customEnrollmentCheckers[$method])($user);

            DiagnosticLog::write('info', 'MethodEnrollmentChecker isEnrolled: custom checker for method "{method}" returned {enrolled} for user_id {user_id}.', ['method' => $method, 'enrolled' => $enrolled ? 'true' : 'false', 'user_id' => $user->id]);

            return $enrolled;
        }

        DiagnosticLog::write('info', 'MethodEnrollmentChecker isEnrolled: unrecognized method key "{method}" for user_id {user_id} - returning false.', ['method' => $method, 'user_id' => $user->id]);

        return false;
    }
}
