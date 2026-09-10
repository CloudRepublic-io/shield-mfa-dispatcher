<?php

declare(strict_types=1);

return [
    'heading'          => 'Two-factor verification',
    'intro'            => 'Choose how you\'d like to verify it\'s you when signing in.',
    'currentLabel'     => 'Currently using: {method}',
    'chooseButton'     => 'Use this method',
    'unknownMethod'    => 'That verification method isn\'t available.',
    'updated'          => 'Your verification method has been updated.',

    // Config\MfaDispatcher::$requiredMethodsForGroups - shown/enforced
    // when the signed-in user belongs to a group that mandates a
    // specific method, overriding their own preference entirely.
    'requiredMethodBanner'         => 'Your account requires {method} for verification. This is set by policy and can\'t be changed here - the options below won\'t take effect.',
    'requiredMethodBadge'          => 'Required',
    'requiredMethodDisabledNote'   => 'Not available - {method} is required for your account.',
    'cannotChooseRequiredOverride' => 'Your account requires a specific verification method, so this choice won\'t be used. No change was made.',

    'methodLabel_email'    => 'Email code',
    'methodLabel_whatsapp' => 'WhatsApp code',
    'methodLabel_totp'     => 'Authenticator app',
    'methodLabel_passkey'  => 'Passkey',

    // TOTP self-service enrollment
    'totpNotInstalled' => 'The authenticator app option isn\'t available on this install.',
    'totpNeedsSetup'   => 'Set up an authenticator app first to enable this option.',
    'totpSetupButton'  => 'Set up authenticator app',
    'totpEnrollIntro'  => 'Scan this QR code with Google Authenticator, Microsoft Authenticator, or a similar app, then enter the 6-digit code it shows to confirm.',
    'totpCodeLabel'    => 'Confirmation code',
    'totpConfirmButton' => 'Confirm and enable',
    'totpInvalidCode'  => 'That code is incorrect. Please try again.',
    'totpEnabled'      => 'Authenticator app enabled and set as your verification method.',
    'totpDisableButton' => 'Remove authenticator app',
    'totpDisabled'     => 'Authenticator app removed. Switched back to {method}.',
    'totpAlreadyEnrolled' => 'An authenticator app is already set up on this account.',

    // WhatsApp self-service phone verification
    'whatsappNotInstalled'        => 'The WhatsApp option isn\'t available on this install.',
    'whatsappNeedsSetup'          => 'Verify a WhatsApp number first to enable this option.',
    'whatsappSetupButton'         => 'Verify WhatsApp number',
    'whatsappEnrollIntro'         => 'Enter a WhatsApp number and we\'ll send a code to confirm you control it.',
    'whatsappPhoneLabel'          => 'WhatsApp number',
    'whatsappPhonePlaceholder'    => 'e.g. +14155551234',
    'whatsappSendCodeButton'      => 'Send code',
    'whatsappInvalidPhoneNumber'  => 'Please enter a valid phone number.',
    'whatsappVerifyIntro'         => 'Enter the 6-digit code we sent to the number ending in {phone}.',
    'whatsappCodeLabel'           => 'Verification code',
    'whatsappConfirmButton'       => 'Confirm and enable',
    'whatsappChangeNumberButton'  => 'Use a different number',
    'whatsappInvalidCode'         => 'That code is incorrect. Please try again.',
    'whatsappNoPendingCode'       => 'No pending verification found. Please start again.',
    'whatsappEnabled'             => 'WhatsApp number verified and set as your verification method.',
    'whatsappDisableButton'       => 'Remove WhatsApp number',
    'whatsappDisabled'            => 'WhatsApp number removed. Switched back to {method}.',
    'whatsappAlreadyVerified'     => 'A WhatsApp number is already verified on this account.',
];
