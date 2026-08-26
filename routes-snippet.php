<?php
/**
 * Add these to app/Config/Routes.php.
 */

$routes->group('', ['filter' => 'session'], static function ($routes) {
    $routes->get(
        'account/mfa',
        '\MfaDispatcher\Controllers\MfaSettingsController::index',
        ['as' => 'mfa-settings']
    );

    $routes->post(
        'account/mfa/choose',
        '\MfaDispatcher\Controllers\MfaSettingsController::choose',
        ['as' => 'mfa-settings-choose']
    );

    // These three routes only do anything useful if the TotpMfa
    // package is also installed - see MfaSettingsController for the
    // graceful-degradation behaviour when it isn't.
    $routes->get(
        'account/mfa/totp/enroll',
        '\MfaDispatcher\Controllers\MfaSettingsController::totpEnroll',
        ['as' => 'mfa-settings-totp-enroll']
    );

    $routes->post(
        'account/mfa/totp/confirm',
        '\MfaDispatcher\Controllers\MfaSettingsController::totpConfirm',
        ['as' => 'mfa-settings-totp-confirm']
    );

    $routes->post(
        'account/mfa/totp/disable',
        '\MfaDispatcher\Controllers\MfaSettingsController::totpDisable',
        ['as' => 'mfa-settings-totp-disable']
    );

    // These five routes only do anything useful if the WhatsAppMfa
    // package is also installed - see MfaSettingsController for the
    // graceful-degradation behaviour when it isn't.
    $routes->get(
        'account/mfa/whatsapp/enroll',
        '\MfaDispatcher\Controllers\MfaSettingsController::whatsappEnroll',
        ['as' => 'mfa-settings-whatsapp-enroll']
    );

    $routes->post(
        'account/mfa/whatsapp/send',
        '\MfaDispatcher\Controllers\MfaSettingsController::whatsappSend',
        ['as' => 'mfa-settings-whatsapp-send']
    );

    $routes->get(
        'account/mfa/whatsapp/verify',
        '\MfaDispatcher\Controllers\MfaSettingsController::whatsappVerify',
        ['as' => 'mfa-settings-whatsapp-verify']
    );

    $routes->post(
        'account/mfa/whatsapp/confirm',
        '\MfaDispatcher\Controllers\MfaSettingsController::whatsappConfirm',
        ['as' => 'mfa-settings-whatsapp-confirm']
    );

    $routes->post(
        'account/mfa/whatsapp/disable',
        '\MfaDispatcher\Controllers\MfaSettingsController::whatsappDisable',
        ['as' => 'mfa-settings-whatsapp-disable']
    );
});
