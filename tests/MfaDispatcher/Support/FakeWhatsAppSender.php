<?php

declare(strict_types=1);

namespace Tests\MfaDispatcher\Support;

use Config\WhatsAppMfa as WhatsAppMfaConfig;
use WhatsAppMfa\Sender\WhatsAppSenderInterface;

/**
 * Records what would have been sent instead of making a real network
 * call - a local copy of shield-whatsapp-mfa's own FakeWhatsAppSender
 * test double, duplicated rather than shared across packages' tests/
 * directories. This mirrors how this package's own src/ code avoids
 * depending on WhatsAppMfa's classes any more tightly than a
 * class_exists() check - its tests shouldn't need a closer coupling
 * than that either.
 */
class FakeWhatsAppSender implements WhatsAppSenderInterface
{
    public static ?string $lastPhoneNumber = null;
    public static ?string $lastCode        = null;
    public static int $sendCount           = 0;

    public function send(string $phoneNumber, string $code, WhatsAppMfaConfig $config): void
    {
        self::$lastPhoneNumber = $phoneNumber;
        self::$lastCode        = $code;
        self::$sendCount++;
    }

    public static function reset(): void
    {
        self::$lastPhoneNumber = null;
        self::$lastCode        = null;
        self::$sendCount       = 0;
    }
}
