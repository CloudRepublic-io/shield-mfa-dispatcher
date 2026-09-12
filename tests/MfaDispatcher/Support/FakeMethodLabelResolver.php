<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Support;

/**
 * A minimal stand-in for a real resolver like
 * WhatsAppMfa\Libraries\ChannelLabel::current() - confirms
 * Config\MfaDispatcher::$methodLabelResolvers' RECOMMENDED
 * [ClassName::class, 'staticMethodName'] form works end to end,
 * mirroring how FakeCustomMethodStore does the same job for
 * $customEnrollmentCheckers.
 */
class FakeMethodLabelResolver
{
    public static string $label = 'Fake Dynamic Label';

    public static function current(): string
    {
        return self::$label;
    }

    public static function reset(): void
    {
        self::$label = 'Fake Dynamic Label';
    }
}
