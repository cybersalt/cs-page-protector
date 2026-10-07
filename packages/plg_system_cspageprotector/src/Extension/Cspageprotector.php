<?php

/**
 * @package    plg_system_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Cspageprotector\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\CaptchaHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\IpHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\LogHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\VerificationHelper;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Result\ResultAwareInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

/**
 * Gatekeeper for Cybersalt Page Protector.
 *
 * On every front-end request it asks the component whether the page is
 * protected. If it is, and the visitor is neither exempt nor already holding
 * a valid pass, the request is handed to the component's challenge view
 * instead of the real content — same URL, same menu item, same template —
 * so a scraper fetching the page only ever receives the challenge.
 *
 * It also keeps protected pages out of Joomla's page cache, otherwise a
 * cached copy of the real content could be served to an unverified visitor
 * (or a cached challenge served to a verified one).
 *
 * All settings live in the component's Options; this plugin has none of its own.
 *
 * @since  0.1.0
 */
final class Cspageprotector extends CMSPlugin implements SubscriberInterface
{
    /**
     * @var    boolean
     * @since  0.1.0
     */
    protected $autoloadLanguage = true;

    /**
     * @return  array<string, string>
     *
     * @since   0.1.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute'           => 'onAfterRoute',
            'onPageCacheSetCaching'  => 'onPageCacheSetCaching',
            'onPageCacheIsExcluded'  => 'onPageCacheIsExcluded',
            'onExtensionAfterSave'   => 'onExtensionAfterSave',
            'onAfterModuleList'      => 'onAfterModuleList',
            'onRenderModule'         => 'onRenderModule',
        ];
    }

    /**
     * Set once a protected module is part of this page, so the page is kept
     * out of the page cache whoever is viewing it.
     *
     * @var    boolean
     * @since  0.1.0
     */
    private bool $protectedModuleOnPage = false;

    /**
     * Protected modules: in "hide" mode (and always on the challenge page
     * itself, so there is never a second captcha) drop them from the list
     * before anything renders, so empty positions collapse as usual.
     *
     * @param   EventInterface  $event  onAfterModuleList
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onAfterModuleList(EventInterface $event): void
    {
        $app = $this->getApplication();

        if (!$this->isActive($app)) {
            return;
        }

        try {
            $params = ProtectionHelper::getParams();

            if (!ProtectionHelper::hasModuleProtection($params)) {
                return;
            }

            $modules   = (array) $event->getArgument('modules', []);
            $protected = array_filter($modules, static fn ($m): bool => \is_object($m) && ProtectionHelper::isProtectedModule($m, $params));

            if ($protected === []) {
                return;
            }

            // Whatever this visitor sees, the page differs per visitor now.
            $this->protectedModuleOnPage = true;
            $app->allowCache(false);

            if (ProtectionHelper::visitorHasAccess($app, $params)) {
                return;
            }

            $onChallengePage = $app->getInput()->getCmd('option') === 'com_cspageprotector';
            $hide            = (string) $params->get('module_mode', ProtectionHelper::MODULE_MODE_PLACEHOLDER) === ProtectionHelper::MODULE_MODE_HIDE;

            if (!$hide && !$onChallengePage) {
                // Placeholder mode: onRenderModule swaps the content.
                return;
            }

            $kept = array_values(array_filter($modules, static fn ($m): bool => !\is_object($m) || !ProtectionHelper::isProtectedModule($m, $params)));

            if (method_exists($event, 'updateModules')) {
                $event->updateModules($kept);
            } else {
                $event->setArgument('modules', $kept);
            }
        } catch (\Throwable $e) {
            Log::add('cs-page-protector module filter error: ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
        }
    }

    /**
     * Placeholder mode: replace a protected module's content with a short
     * notice and a "Show content" button that runs the one site-wide check.
     * Runs before the module chrome, so the module keeps its title and box.
     * The captcha itself is never rendered inside a module.
     *
     * @param   EventInterface  $event  onRenderModule
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onRenderModule(EventInterface $event): void
    {
        if (!$this->protectedModuleOnPage) {
            return;
        }

        $app    = $this->getApplication();
        $module = $event->getArgument('subject') ?? $event->getArgument(0);

        if (!\is_object($module) || !isset($module->content)) {
            return;
        }

        try {
            $params = ProtectionHelper::getParams();

            if (!ProtectionHelper::isProtectedModule($module, $params) || ProtectionHelper::visitorHasAccess($app, $params)) {
                return;
            }

            $module->content = $this->renderModulePlaceholder($app, $params);
        } catch (\Throwable $e) {
            // Fail closed for the module: never leak its content on an error.
            $module->content = '';
            Log::add('cs-page-protector module placeholder error: ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
        }
    }

    /**
     * The placeholder shown instead of a protected module's content.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  string  Safe HTML.
     *
     * @since   0.1.0
     */
    private function renderModulePlaceholder(CMSApplicationInterface $app, Registry $params): string
    {
        $document = $app->getDocument();

        if ($document && method_exists($document, 'getWebAssetManager')) {
            $wa = $document->getWebAssetManager();
            $wa->getRegistry()->addExtensionRegistryFile('com_cspageprotector');
            $wa->useStyle('com_cspageprotector.module');
            $wa->useScript('com_cspageprotector.module');
        }

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $text      = trim((string) $params->get('module_placeholder_text', '')) ?: Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_MODULE_LOCKED_TEXT');
        $button    = trim((string) $params->get('module_placeholder_button', '')) ?: Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_MODULE_LOCKED_BUTTON');
        $itemId    = ProtectionHelper::getActiveItemId($app);
        $returnB64 = base64_encode(Uri::getInstance()->toString());
        $itemParam = $itemId > 0 ? '&Itemid=' . $itemId : '';

        // Fallback (no JavaScript, or a captcha that can't run inline): the
        // check on its own clean page (tmpl=component: template styling, no
        // other modules), which then comes back here. Plain non-SEF links are
        // always routable and keep the base64 intact.
        $fallbackUrl = Uri::base() . 'index.php?option=com_cspageprotector&view=challenge&tmpl=component'
            . $itemParam . '&cspp_return=' . rawurlencode($returnB64);
        $verifyUrl   = Uri::base() . 'index.php?option=com_cspageprotector&task=challenge.verify' . $itemParam;

        // Inline check: the Proof-of-Work widget is placed in the clicked box,
        // solved, and the form posts; the page reloads with the module shown.
        // The widget markup is printed once per page inside an inert
        // <template>, so nothing runs (and only one captcha ever exists)
        // until a visitor clicks.
        $captchaName = CaptchaHelper::getConfiguredPlugin($params);
        $inline      = $captchaName === CaptchaHelper::DEFAULT_PLUGIN && CaptchaHelper::isAvailable($captchaName);
        $template    = '';

        if ($inline && !$this->captchaTemplatePrinted) {
            $captcha = CaptchaHelper::getInstance($captchaName);
            $widget  = $captcha ? (string) $captcha->display(CaptchaHelper::FIELD_NAME, 'cspp-inline-captcha', 'cspp-captcha') : '';

            if ($widget === '') {
                $inline = false;
            } else {
                $template                     = '<template class="cspp-captcha-template">' . $widget . '</template>';
                $this->captchaTemplatePrinted = true;
            }
        }

        return '<div class="cspp-module-locked" data-cspp-inline="' . ($inline ? '1' : '0') . '">'
            . '<p class="cspp-module-locked-text">' . $e($text) . '</p>'
            . '<form class="cspp-module-locked-form" method="post" action="' . $e($verifyUrl) . '">'
            . '<div class="cspp-module-locked-captcha"></div>'
            . '<a class="btn btn-secondary btn-sm cspp-module-locked-button" href="' . $e($fallbackUrl) . '" rel="nofollow">' . $e($button) . '</a>'
            . '<p class="cspp-module-locked-status" aria-live="polite"'
            . ' data-working="' . $e(Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_MODULE_WORKING')) . '"'
            . ' data-done="' . $e(Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_MODULE_DONE')) . '"'
            . ' data-error="' . $e(Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_MODULE_ERROR')) . '"></p>'
            . '<input type="hidden" name="return" value="' . $e($returnB64) . '">'
            . '<input type="hidden" name="cspp_item" value="' . (int) $itemId . '">'
            . '<input type="hidden" name="cspp_source" value="module">'
            . '<input type="hidden" name="' . $e(Session::getFormToken()) . '" value="1">'
            . '</form>'
            . $template
            . '</div>';
    }

    /**
     * The inline captcha <template> is printed once per page.
     *
     * @var    boolean
     * @since  0.1.0
     */
    private bool $captchaTemplatePrinted = false;

    /**
     * When the component Options are saved with "Shorten IP addresses" on,
     * shorten the IPs already in the log too, not just future entries.
     *
     * @param   EventInterface  $event  onExtensionAfterSave (context, table, isNew)
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onExtensionAfterSave(EventInterface $event): void
    {
        $args    = $event->getArguments();
        $context = (string) ($args['context'] ?? $args[0] ?? '');
        $table   = $args['subject'] ?? $args[1] ?? null;

        if ($context !== 'com_config.component' || !\is_object($table) || ($table->element ?? '') !== 'com_cspageprotector') {
            return;
        }

        if (!class_exists(LogHelper::class)) {
            return;
        }

        try {
            if (LogHelper::anonymizeEnabled(new Registry((string) ($table->params ?? '')))) {
                LogHelper::anonymizeStored(Factory::getContainer()->get(DatabaseInterface::class));
            }

            // Module protection changes what a page contains per visitor, so drop
            // any page-cache entries created before the new settings.
            // The site page cache lives in /cache on Joomla 5 but in
            // administrator/cache on Joomla 6 (and wherever cache_path points).
            $bases = array_unique(array_filter([
                (string) $this->getApplication()->get('cache_path', ''),
                JPATH_SITE . '/cache',
                JPATH_ADMINISTRATOR . '/cache',
            ]));

            foreach ($bases as $base) {
                if (is_dir($base)) {
                    Factory::getContainer()->get(CacheControllerFactoryInterface::class)
                        ->createCacheController('output', ['defaultgroup' => 'page', 'cachebase' => $base])
                        ->clean('page');
                }
            }
        } catch (\Throwable $e) {
            Log::add('cs-page-protector: after-save tasks failed: ' . $e->getMessage(), Log::WARNING, 'com_cspageprotector');
        }
    }

    /**
     * Challenge unverified visitors on protected pages.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onAfterRoute(): void
    {
        $app = $this->getApplication();

        if (!$this->isActive($app)) {
            return;
        }

        try {
            $params = ProtectionHelper::getParams();

            if (!ProtectionHelper::isProtectedRequest($app, $params)) {
                return;
            }

            $this->guard($app, $params);
        } catch (\Throwable $e) {
            // A bug in the protector must never take the site down. Log it and
            // let the page render normally.
            Log::add('cs-page-protector gatekeeper error: ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
        }
    }

    /**
     * Tell the page cache not to serve or store protected pages.
     *
     * @param   EventInterface  $event  onPageCacheSetCaching
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onPageCacheSetCaching(EventInterface $event): void
    {
        if ($this->isProtectedSafe()) {
            $this->addResult($event, false);
        }
    }

    /**
     * Belt and braces: also mark protected pages as excluded from the page cache.
     *
     * @param   EventInterface  $event  onPageCacheIsExcluded
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onPageCacheIsExcluded(EventInterface $event): void
    {
        // Protected page, or a page carrying a protected module: either way the
        // output depends on the visitor and must never be cached.
        if ($this->protectedModuleOnPage || $this->isProtectedSafe()) {
            $this->addResult($event, true);
        }
    }

    /**
     * The actual protection flow for a protected request.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    private function guard(CMSApplicationInterface $app, Registry $params): void
    {
        // Protected pages must never land in a browser or proxy shared cache.
        $app->allowCache(false);

        $input     = $app->getInput();
        $ip        = IpHelper::getClientIp($params);
        $userAgent = $input->server->getString('HTTP_USER_AGENT', '');
        $itemId    = ProtectionHelper::getActiveItemId($app);
        $url       = Uri::getInstance()->toString();
        $captcha   = CaptchaHelper::getConfiguredPlugin($params);

        $exemption = ProtectionHelper::getExemption($app, $params, $ip, $userAgent);

        if ($exemption !== ProtectionHelper::EXEMPT_NONE) {
            if ($exemption === ProtectionHelper::EXEMPT_BOT && (int) $params->get('log_bots', 0) === 1) {
                LogHelper::log($params, LogHelper::EVENT_BOT, $ip, $userAgent, $url, $itemId, $captcha);
            }

            return;
        }

        if (VerificationHelper::isVerified($app, $params, $ip, $userAgent)) {
            return;
        }

        if (!CaptchaHelper::isAvailable($captcha)) {
            LogHelper::log(
                $params,
                LogHelper::EVENT_ERROR,
                $ip,
                $userAgent,
                $url,
                $itemId,
                $captcha,
                Text::sprintf('PLG_SYSTEM_CSPAGEPROTECTOR_LOG_CAPTCHA_UNAVAILABLE', $captcha)
            );

            if ((string) $params->get('captcha_unavailable', 'allow') === 'allow') {
                return;
            }

            $this->sendBlocked($app);
        }

        // Feeds, JSON, raw and other non-HTML formats can't show a challenge,
        // and are an easy way to pull a protected category's content.
        $format = strtolower($input->getWord('format', 'html'));

        if ($format !== 'html') {
            LogHelper::log($params, LogHelper::EVENT_BLOCKED, $ip, $userAgent, $url, $itemId, $captcha, 'format=' . $format);
            $this->sendBlocked($app);
        }

        LogHelper::log($params, LogHelper::EVENT_CHALLENGED, $ip, $userAgent, $url, $itemId, $captcha);

        // Hand the request to the challenge page. The URL and the active menu
        // item stay the same, so the visitor sees the challenge inside the
        // site's normal template and lands back here once they pass.
        $input->set('cspp_return', base64_encode($url));
        $input->set('cspp_inplace', 1);
        $input->set('option', 'com_cspageprotector');
        $input->set('view', 'challenge');
        $input->set('layout', 'default');
        $input->set('task', 'display');
        $input->set('format', 'html');
        $input->set('id', null);
    }

    /**
     * Stop with a plain 403 — used for non-HTML formats and when the captcha is
     * unavailable and the site is set to fail closed.
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  never
     *
     * @since   0.1.0
     */
    private function sendBlocked(CMSApplicationInterface $app): never
    {
        $app->setHeader('Status', '403', true);
        $app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store, private', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $app->sendHeaders();

        echo Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_BLOCKED_MESSAGE');

        $app->close();

        exit;
    }

    /**
     * Should the gatekeeper run at all on this request?
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private function isActive(CMSApplicationInterface $app): bool
    {
        if (!$app->isClient('site')) {
            return false;
        }

        // The plugin can outlive the component (e.g. a half-finished uninstall).
        if (!class_exists(ProtectionHelper::class)) {
            return false;
        }

        return ComponentHelper::isEnabled('com_cspageprotector');
    }

    /**
     * isProtectedRequest() wrapped for the page-cache events, which may fire
     * before onAfterRoute's own check.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private function isProtectedSafe(): bool
    {
        $app = $this->getApplication();

        if (!$this->isActive($app)) {
            return false;
        }

        try {
            return ProtectionHelper::isProtectedRequest($app, ProtectionHelper::getParams());
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Add a value to a result-aware event (J5 typed events and generic events alike).
     *
     * @param   EventInterface  $event  The event.
     * @param   mixed           $value  The result to add.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    private function addResult(EventInterface $event, mixed $value): void
    {
        if ($event instanceof ResultAwareInterface) {
            $event->addResult($value);

            return;
        }

        $result   = (array) $event->getArgument('result', []);
        $result[] = $value;
        $event->setArgument('result', $result);
    }
}
