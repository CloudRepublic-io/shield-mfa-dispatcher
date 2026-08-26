<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('MfaDispatcher.heading') ?></h1>

<?php if (session('message')) : ?>
    <div class="alert alert-success"><?= esc(session('message')) ?></div>
<?php endif ?>
<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p><?= lang('MfaDispatcher.intro') ?></p>

<div class="list-group mb-3">
    <?php foreach ($methods as $key => $class) : ?>
        <div class="list-group-item d-flex justify-content-between align-items-center">
            <div>
                <strong><?= esc(lang('MfaDispatcher.methodLabel_' . $key) ?: ucfirst($key)) ?></strong>
                <?php if ($key === $current) : ?>
                    <span class="badge bg-primary ms-2">Active</span>
                <?php endif ?>

                <?php if ($key === 'totp' && $totpAvailable && ! $totpEnrolled) : ?>
                    <div class="text-muted small"><?= lang('MfaDispatcher.totpNeedsSetup') ?></div>
                <?php endif ?>
                <?php if ($key === 'whatsapp' && $whatsappAvailable && ! $whatsappVerified) : ?>
                    <div class="text-muted small"><?= lang('MfaDispatcher.whatsappNeedsSetup') ?></div>
                <?php endif ?>
            </div>

            <div>
                <?php if ($key === $current) : ?>
                    <?php if ($key === 'totp') : ?>
                        <form method="post" action="<?= url_to('mfa-settings-totp-disable') ?>" class="d-inline" onsubmit="return confirm('Remove your authenticator app?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <?= lang('MfaDispatcher.totpDisableButton') ?>
                            </button>
                        </form>
                    <?php endif ?>
                    <?php if ($key === 'whatsapp') : ?>
                        <form method="post" action="<?= url_to('mfa-settings-whatsapp-disable') ?>" class="d-inline" onsubmit="return confirm('Remove your verified WhatsApp number?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <?= lang('MfaDispatcher.whatsappDisableButton') ?>
                            </button>
                        </form>
                    <?php endif ?>
                <?php elseif ($key === 'totp' && ! $totpAvailable) : ?>
                    <span class="text-muted small"><?= lang('MfaDispatcher.totpNotInstalled') ?></span>
                <?php elseif ($key === 'totp' && ! $totpEnrolled) : ?>
                    <a href="<?= url_to('mfa-settings-totp-enroll') ?>" class="btn btn-sm btn-primary">
                        <?= lang('MfaDispatcher.totpSetupButton') ?>
                    </a>
                <?php elseif ($key === 'whatsapp' && ! $whatsappAvailable) : ?>
                    <span class="text-muted small"><?= lang('MfaDispatcher.whatsappNotInstalled') ?></span>
                <?php elseif ($key === 'whatsapp' && ! $whatsappVerified) : ?>
                    <a href="<?= url_to('mfa-settings-whatsapp-enroll') ?>" class="btn btn-sm btn-primary">
                        <?= lang('MfaDispatcher.whatsappSetupButton') ?>
                    </a>
                <?php else : ?>
                    <form method="post" action="<?= url_to('mfa-settings-choose') ?>" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="method" value="<?= esc($key) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            <?= lang('MfaDispatcher.chooseButton') ?>
                        </button>
                    </form>
                <?php endif ?>
            </div>
        </div>
    <?php endforeach ?>
</div>

<?= $this->endSection() ?>
