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
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Result\ResultAwareInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
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
        ];
    }

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
        } catch (\Throwable $e) {
            Log::add('cs-page-protector: shortening stored IPs failed: ' . $e->getMessage(), Log::WARNING, 'com_cspageprotector');
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
        if ($this->isProtectedSafe()) {
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
