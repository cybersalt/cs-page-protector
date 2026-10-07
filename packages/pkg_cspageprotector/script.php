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

        $this->renderInstallCard($type);

        return true;
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
    private function renderInstallCard(string $type): void
    {
        $e = static fn (string $key): string => htmlspecialchars(Text::_($key), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $title       = $type === 'update' ? $e('PKG_CSPAGEPROTECTOR_CARD_TITLE_UPDATE') : $e('PKG_CSPAGEPROTECTOR_CARD_TITLE_INSTALL');
        // Description may carry the vendor <strong><a> prefix, so it's not escaped (Brain spec).
        $description = Text::_('PKG_CSPAGEPROTECTOR_XML_DESCRIPTION');
        $nextSteps   = $type === 'update' ? '' : '<p class="mb-3">' . $e('PKG_CSPAGEPROTECTOR_CARD_NEXT_STEPS') . '</p>';
        $powNote     = $this->enabledPow ? '<p class="mb-3">' . $e('PKG_CSPAGEPROTECTOR_CARD_POW_ENABLED') . '</p>' : '';

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
        {$powNote}
        {$nextSteps}
        <p class="mb-0 d-flex flex-wrap gap-2">
            <a class="btn btn-sm cs-cybersalt-btn" href="{$optionsUrl}">{$btnPages}</a>
            <a class="btn btn-sm cs-cybersalt-btn" href="{$dashboardUrl}">{$btnDashboard}</a>
            <a class="btn btn-sm cs-cybersalt-btn" href="{$captchaUrl}">{$btnCaptcha}</a>
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
