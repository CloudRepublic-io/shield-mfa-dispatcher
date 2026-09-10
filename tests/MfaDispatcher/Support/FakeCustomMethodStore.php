<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Support;

use CodeIgniter\Shield\Entities\User;

/**
 * A minimal stand-in for a developer's own custom method's store
 * class, used to confirm Config\MfaDispatcher::$customEnrollmentCheckers'
 * RECOMMENDED [ClassName::class, 'staticMethodName'] form actually
 * works end to end - not just the Closure form, which is also valid
 * but (per that config property's own, corrected doc comment) cannot
 * be used as the property's own default value directly.
 */
class FakeCustomMethodStore
{
    /** @var array<int, bool> */
    public static array $enrolledUserIds = [];

    public static function checkEnrollment(User $user): bool
    {
        return self::$enrolledUserIds[$user->id] ?? false;
    }

    public static function reset(): void
    {
        self::$enrolledUserIds = [];
    }
}
