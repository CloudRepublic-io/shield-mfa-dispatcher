<?= $this->extend(setting('Auth.views')['layout'] ?? 'CodeIgniter\Shield\Views\layout') ?>

<?= $this->section('main') ?>

<h1 class="h3 mb-3"><?= lang('MfaDispatcher.whatsappSetupButton') ?></h1>

<?php if (session('error')) : ?>
    <div class="alert alert-danger"><?= esc(session('error')) ?></div>
<?php endif ?>

<p><?= lang('MfaDispatcher.whatsappEnrollIntro') ?></p>

<form method="post" action="<?= url_to('mfa-settings-whatsapp-send') ?>">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="phone" class="form-label"><?= lang('MfaDispatcher.whatsappPhoneLabel') ?></label>
        <input
            type="tel"
            id="phone"
            name="phone"
            class="form-control"
            placeholder="<?= lang('MfaDispatcher.whatsappPhonePlaceholder') ?>"
            value="<?= esc(old('phone')) ?>"
            required
        >
    </div>

    <button type="submit" class="btn btn-primary">
        <?= lang('MfaDispatcher.whatsappSendCodeButton') ?>
    </button>
</form>

<a href="<?= url_to('mfa-settings') ?>" class="btn btn-link">Back</a>

<?= $this->endSection() ?>
