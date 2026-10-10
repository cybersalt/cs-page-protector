<?php

/**
 * @package    pkg_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

return new class () implements InstallerScriptInterface {
    /**
     * Minimum PHP version (Joomla 5's floor).
     */
    private const MIN_PHP = '8.1.0';

    /**
     * Set during postflight when we switched the core Proof-of-Work captcha on.
     */
    private bool $enabledPow = false;

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type !== 'uninstall' && version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
            Factory::getApplication()->enqueueMessage(
                htmlspecialchars(sprintf('Cybersalt Page Protector needs PHP %s or later (this server runs %s).', self::MIN_PHP, PHP_VERSION), ENT_QUOTES, 'UTF-8'),
                'error'
            );

            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        // Joomla also calls postflight() on uninstall - no card, no side effects then.
        if (!\in_array($type, ['install', 'update', 'discover_install'], true)) {
            return true;
        }

        // The package .sys.ini was copied after the Language object was built
        // (Joomla-Brain gotcha #24) - load it so the card isn't raw constants.
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('pkg_cspageprotector.sys', JPATH_ADMINISTRATOR, null, true);
        $lang->load('com_cspageprotector', JPATH_ADMINISTRATOR, null, true);

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        if ($type !== 'update') {
            $this->safely(fn () => $this->enablePlugin($db, 'system', 'cspageprotector'));
            $this->safely(fn () => $this->enabledPow = $this->enablePlugin($db, 'captcha', 'powcaptcha'));
            $this->safely(fn () => $this->seedPermissions($db));
        }

        $this->safely(fn () => $this->ensureSalt($db));

        // On update too: a captcha that was disabled or removed since gets caught here.
        $captchaProblem = [];
        $noticeAccepted = false;
        $this->safely(function () use ($db, &$captchaProblem, &$noticeAccepted) {
            $params         = $this->loadParams($db);
            $captchaProblem = $this->findCaptchaProblem($db, $params);
            $noticeAccepted = (string) $params->get('notice_accepted_at', '') !== '';
        });

        $this->renderInstallCard($type, $captchaProblem, $noticeAccepted);

        // Joomla shows the manifest description in its own box above the card,
        // and the card already leads with it. It sets that message before the
        // install and reads it after postflight, so clearing it here leaves
        // just the card.
        $adapter->getParent()->message = '';

        return true;
    }

    /**
     * The component params, straight from the database (ComponentHelper may
     * still hold the pre-install copy).
     */
    private function loadParams(DatabaseInterface $db): Registry
    {
        return new Registry((string) $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_cspageprotector'))
        )->loadResult());
    }

    /**
     * Can the captcha chosen in Options (default: core Proof of Work) run?
     * Reads the database directly: PluginHelper's list was loaded before
     * postflight switched anything on.
     *
     * @return  array{problem?: string, plugin?: string}  Empty when it can run.
     */
    private function findCaptchaProblem(DatabaseInterface $db, Registry $params): array
    {
        // Same clean-up as CaptchaHelper::getConfiguredPlugin().
        $plugin = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $params->get('captcha_plugin', 'powcaptcha'))) ?: 'powcaptcha';

        $enabled = $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('enabled'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('captcha'))
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':element', $plugin)
        )->loadResult();

        if ($enabled === null) {
            return ['problem' => $plugin === 'powcaptcha' ? 'pow_missing' : 'missing', 'plugin' => $plugin];
        }

        return (int) $enabled === 1 ? [] : ['problem' => 'disabled', 'plugin' => $plugin];
    }

    /**
     * Enable a plugin if it's installed and currently disabled.
     *
     * @return  bool  True when we changed it.
     */
    private function enablePlugin(DatabaseInterface $db, string $folder, string $element): bool
    {
        $query = $db->createQuery()
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = :folder')
            ->where($db->quoteName('element') . ' = :element')
            ->where($db->quoteName('enabled') . ' = 0')
            ->bind(':folder', $folder)
            ->bind(':element', $element);

        $db->setQuery($query)->execute();

        return $db->getAffectedRows() > 0;
    }

    /**
     * Give Managers view access and Administrators view + manage access to the
     * dashboard and log, unless the site already has rules for those actions.
     * Super Users always have everything via core.admin.
     */
    private function seedPermissions(DatabaseInterface $db): void
    {
        $query = $db->createQuery()
            ->select([$db->quoteName('id'), $db->quoteName('rules')])
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = ' . $db->quote('com_cspageprotector'));

        $asset = $db->setQuery($query)->loadObject();

        if (!$asset) {
            return;
        }

        $rules = json_decode((string) $asset->rules, true) ?: [];

        if (isset($rules['cspageprotector.view']) || isset($rules['cspageprotector.write'])) {
            return;
        }

        $groups = $db->setQuery(
            $db->createQuery()
                ->select([$db->quoteName('id'), $db->quoteName('title')])
                ->from($db->quoteName('#__usergroups'))
                ->whereIn($db->quoteName('title'), ['Manager', 'Administrator'], ParameterType::STRING)
        )->loadAssocList('title', 'id');

        $view  = [];
        $write = [];

        if (isset($groups['Manager'])) {
            $view[(string) $groups['Manager']] = 1;
        }

        if (isset($groups['Administrator'])) {
            $view[(string) $groups['Administrator']]  = 1;
            $write[(string) $groups['Administrator']] = 1;
        }

        $rules['cspageprotector.view']  = (object) $view;
        $rules['cspageprotector.write'] = (object) $write;

        $json    = json_encode($rules);
        $assetId = (int) $asset->id;

        $db->setQuery(
            $db->createQuery()
                ->update($db->quoteName('#__assets'))
                ->set($db->quoteName('rules') . ' = :rules')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':rules', $json)
                ->bind(':id', $assetId, ParameterType::INTEGER)
        )->execute();
    }

    /**
     * Make sure the pass-signing salt exists (so passes can later be reset
     * without leaving an empty salt behind).
     */
    private function ensureSalt(DatabaseInterface $db): void
    {
        $query = $db->createQuery()
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('com_cspageprotector'));

        $params = new Registry((string) $db->setQuery($query)->loadResult());

        if ((string) $params->get('pass_salt', '') !== '') {
            return;
        }

        $params->set('pass_salt', bin2hex(random_bytes(16)));
        $json = $params->toString();

        $db->setQuery(
            $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = :params')
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_cspageprotector'))
                ->bind(':params', $json)
        )->execute();
    }

    /**
     * Run a non-essential install step; never let it fail the install.
     */
    private function safely(callable $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            // The admin can fix any of these by hand; the dashboard health
            // checks will point at whatever didn't happen.
        }
    }

    /**
     * Cybersalt post-install card (JOOMLA-EXTENSION-WISHLIST.md spec).
     */
    /**
     * The red "no guarantee" box with its accept button (same as the one at
     * the top of the Page Protector pages, see NoticeHelper). The card sits
     * inside the installer's own form, so the button posts a separate form
     * built by a small script, with the session token, and lands on the
     * dashboard.
     *
     * @param   callable  $e  Translate + escape.
     */
    private function renderNotice(callable $e): string
    {
        $action = htmlspecialchars('index.php?option=com_cspageprotector&task=dashboard.acceptnotice', ENT_QUOTES, 'UTF-8');
        $token  = htmlspecialchars(Session::getFormToken(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div class="alert alert-danger mb-3" role="alert">
    <h4 class="alert-heading h5">{$e('COM_CSPAGEPROTECTOR_NO_GUARANTEE_LABEL')}</h4>
    <p>{$e('COM_CSPAGEPROTECTOR_NO_GUARANTEE_BODY')}</p>
    <button type="button" class="btn btn-danger btn-sm cspp-alert-btn" data-cspp-accept="{$action}" data-cspp-token="{$token}">{$e('COM_CSPAGEPROTECTOR_NOTICE_ACCEPT')}</button>
</div>
<script>
if (!window.csppAcceptBound) {
    window.csppAcceptBound = true;
    document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-cspp-accept]');
        if (!b) { return; }
        e.preventDefault();
        b.disabled = true;
        const f = document.createElement('form');
        f.method = 'post';
        f.action = b.dataset.csppAccept;
        const t = document.createElement('input');
        t.type = 'hidden';
        t.name = b.dataset.csppToken;
        t.value = '1';
        f.appendChild(t);
        document.body.appendChild(f);
        f.submit();
    });
}
</script>
HTML;
    }

    /**
     * @param   string   $type            install|update|discover_install
     * @param   array    $captchaProblem  From findCaptchaProblem(); empty when the captcha can run.
     * @param   boolean  $noticeAccepted  The "no guarantee" notice was accepted on this site.
     */
    private function renderInstallCard(string $type, array $captchaProblem, bool $noticeAccepted): void
    {
        $e = static fn (string $key): string => htmlspecialchars(Text::_($key), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $title       = $type === 'update' ? $e('PKG_CSPAGEPROTECTOR_CARD_TITLE_UPDATE') : $e('PKG_CSPAGEPROTECTOR_CARD_TITLE_INSTALL');
        // Description may carry the vendor <strong><a> prefix, so it's not escaped (Brain spec).
        $description = Text::_('PKG_CSPAGEPROTECTOR_XML_DESCRIPTION');
        $nextSteps   = $type === 'update' ? '' : '<p class="mb-3">' . $e('PKG_CSPAGEPROTECTOR_CARD_NEXT_STEPS') . '</p>';
        $powNote     = $this->enabledPow ? '<p class="mb-3">' . $e('PKG_CSPAGEPROTECTOR_CARD_POW_ENABLED') . '</p>' : '';
        $noGuarantee = $noticeAccepted ? '' : $this->renderNotice($e);

        $captchaWarning = '';
        $btnUpdate      = '';

        if ($captchaProblem !== []) {
            $plugin = (string) $captchaProblem['plugin'];
            $text   = match ($captchaProblem['problem']) {
                'pow_missing' => Text::sprintf('PKG_CSPAGEPROTECTOR_CARD_POW_MISSING', JVERSION),
                'missing'     => Text::sprintf('PKG_CSPAGEPROTECTOR_CARD_CAPTCHA_MISSING', $plugin),
                default       => Text::sprintf('PKG_CSPAGEPROTECTOR_CARD_CAPTCHA_DISABLED', $plugin),
            };

            $captchaWarning = '<div class="alert alert-warning mb-3"><strong>'
                . $e('PKG_CSPAGEPROTECTOR_CARD_NO_CAPTCHA_HEADING') . '</strong> '
                . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' '
                . $e('PKG_CSPAGEPROTECTOR_CARD_NO_CAPTCHA_UNTIL') . '</div>';

            if ($captchaProblem['problem'] === 'pow_missing') {
                $btnUpdate = '<a class="btn btn-sm cs-cybersalt-btn" href="index.php?option=com_joomlaupdate">'
                    . $e('PKG_CSPAGEPROTECTOR_CARD_JOOMLA_UPDATE') . '</a>';
            }
        }

        $btnDashboard = $e('PKG_CSPAGEPROTECTOR_CARD_OPEN_DASHBOARD');
        $btnPages     = $e('PKG_CSPAGEPROTECTOR_CARD_CHOOSE_PAGES');
        $btnCaptcha   = $e('PKG_CSPAGEPROTECTOR_CARD_CAPTCHA_SETTINGS');
        $support      = $e('PKG_CSPAGEPROTECTOR_CARD_SUPPORT');
        $reportBug    = $e('PKG_CSPAGEPROTECTOR_CARD_REPORT_BUG');

        $dashboardUrl = 'index.php?option=com_cspageprotector&amp;view=dashboard';
        $optionsUrl   = 'index.php?option=com_config&amp;view=component&amp;component=com_cspageprotector';
        $captchaUrl   = 'index.php?option=com_plugins&amp;view=plugins&amp;filter[folder]=captcha';
        $issuesUrl    = 'https://github.com/cybersalt/cs-page-protector/issues';
        $logoUrl      = htmlspecialchars(Uri::root() . 'media/com_cspageprotector/images/logo.svg', ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<style>
.cs-install-card {
    --cs-header-bg: #fff;
    --cs-header-title: #0102E1;
    --cs-header-border: rgba(0, 0, 0, 0.1);
}
html[data-bs-theme="dark"] .cs-install-card,
html[data-color-scheme="dark"] .cs-install-card {
    --cs-header-bg: #1f2937;
    --cs-header-title: #FE9904;
    --cs-header-border: rgba(255, 255, 255, 0.1);
}
.cs-install-card .cs-card-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    background-color: var(--cs-header-bg);
    padding: 0.9rem 1.25rem;
    border-bottom: 1px solid var(--cs-header-border);
}
.cs-install-card .cs-card-header img {
    height: 56px;
    width: 56px;
    flex: 0 0 56px;
}
html[data-bs-theme="dark"] .cs-install-card .cs-card-header img,
html[data-color-scheme="dark"] .cs-install-card .cs-card-header img {
    background-color: #fff;
    border-radius: 50%;
    padding: 6px;
    box-sizing: content-box;
}
.cs-install-card .cs-card-header h3 {
    margin: 0;
    font-size: 1.4rem;
    color: var(--cs-header-title);
}
.cs-install-card a.cs-cybersalt-btn,
.cs-install-card a.cs-cybersalt-btn:link,
.cs-install-card a.cs-cybersalt-btn:visited {
    background-color: #dc6b1a;
    border-color: #dc6b1a;
    color: #fff !important;
}
/* Button inside the red notice: same rules as DisplayHelper::ALERT_BUTTON_CSS. */
.cs-install-card .cspp-alert-btn {
    border: 2px solid #1f2937 !important;
    font-weight: 600;
}
.cs-install-card .cspp-alert-btn:hover,
.cs-install-card .cspp-alert-btn:focus {
    filter: brightness(0.9);
}
html[data-bs-theme="dark"] .cs-install-card .cspp-alert-btn,
html[data-color-scheme="dark"] .cs-install-card .cspp-alert-btn {
    background-color: var(--primary, #007db0) !important;
    color: #fff !important;
    border-color: #1f2937 !important;
}
html[data-bs-theme="dark"] .cs-install-card .cspp-alert-btn:hover,
html[data-color-scheme="dark"] .cs-install-card .cspp-alert-btn:hover,
html[data-bs-theme="dark"] .cs-install-card .cspp-alert-btn:focus,
html[data-color-scheme="dark"] .cs-install-card .cspp-alert-btn:focus {
    filter: brightness(1.15);
}
.cs-install-card a.cs-cybersalt-btn:hover,
.cs-install-card a.cs-cybersalt-btn:focus,
.cs-install-card a.cs-cybersalt-btn:active {
    background-color: #b85614;
    border-color: #b85614;
    color: #fff !important;
}
</style>
<div class="card my-3 cs-install-card">
    <div class="card-header cs-card-header">
        <img src="{$logoUrl}" alt="" />
        <h3>{$title}</h3>
    </div>
    <div class="card-body">
        <p class="lead mb-3">{$description}</p>
        {$noGuarantee}
        {$captchaWarning}
        {$powNote}
        {$nextSteps}
        <p class="mb-0 d-flex flex-wrap gap-2">
            <a class="btn btn-sm cs-cybersalt-btn" href="{$optionsUrl}">{$btnPages}</a>
            <a class="btn btn-sm cs-cybersalt-btn" href="{$dashboardUrl}">{$btnDashboard}</a>
            <a class="btn btn-sm cs-cybersalt-btn" href="{$captchaUrl}">{$btnCaptcha}</a>
            {$btnUpdate}
        </p>
        <hr>
        <p class="text-muted small mb-0">
            {$support}
            &middot;
            <a href="https://www.cybersalt.com" target="_blank" rel="noopener noreferrer">Cybersalt</a>
            &middot;
            <a href="mailto:support@cybersalt.com">support@cybersalt.com</a>
            &middot;
            <a href="{$issuesUrl}" target="_blank" rel="noopener noreferrer">{$reportBug}</a>
        </p>
    </div>
</div>
HTML;
    }
};
