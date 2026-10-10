<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Controller;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\CaptchaHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\NoticeHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\VerificationHelper;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * Dashboard actions.
 *
 * @since  0.1.0
 */
final class DashboardController extends BaseController
{
    /**
     * Invalidate every visitor's pass by rotating the signing salt. Everyone
     * gets challenged again on their next visit to a protected page.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function resetpasses(): void
    {
        $this->checkToken();
        PermissionHelper::requireAdmin();

        try {
            $params = clone ComponentHelper::getParams('com_cspageprotector');
            $params->set('pass_salt', bin2hex(random_bytes(16)));
            ProtectionHelper::saveParams($params);

            $this->setMessage(Text::_('COM_CSPAGEPROTECTOR_RESET_PASSES_DONE'));
        } catch (\Throwable $e) {
            Log::add('cs-page-protector reset passes failed: ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
            $this->setMessage(Text::_('COM_CSPAGEPROTECTOR_RESET_PASSES_FAILED'), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_cspageprotector&view=dashboard', false));
    }

    /**
     * Accept the "no guarantee" notice for the whole site, recording who and
     * when, then go back to the page the button was on.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public function acceptnotice(): void
    {
        $this->checkToken();
        PermissionHelper::requireConfigure();

        try {
            NoticeHelper::accept($this->app->getIdentity());
            $this->setMessage(Text::_('COM_CSPAGEPROTECTOR_NOTICE_ACCEPTED_DONE'));
        } catch (\Throwable $e) {
            Log::add('cs-page-protector accept notice failed: ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
            $this->setMessage(Text::_('COM_CSPAGEPROTECTOR_NOTICE_ACCEPT_FAILED'), 'error');
        }

        // Back to the admin page the button was on.
        $this->setRedirect($this->adminReturnUrl());
    }

    /**
     * Switch on the captcha plugin chosen in Options when it's installed but
     * disabled. Linked from the "can't run its check" admin warning. That
     * warning is a Joomla message, which is sanitised down to plain links, so
     * this is a GET with the token in the URL (checkToken('get')).
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public function enablecaptcha(): void
    {
        $this->checkToken('get');

        if (!$this->app->getIdentity()->authorise('core.edit.state', 'com_plugins')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $captcha = CaptchaHelper::getConfiguredPlugin(ProtectionHelper::getParams());

        try {
            if (CaptchaHelper::getProblem($captcha) === CaptchaHelper::PROBLEM_DISABLED) {
                CaptchaHelper::enable($captcha);
            }

            $this->setMessage(Text::sprintf('COM_CSPAGEPROTECTOR_CAPTCHA_ENABLED', $captcha));
        } catch (\Throwable $e) {
            Log::add('cs-page-protector enable captcha failed: ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
            $this->setMessage(Text::sprintf('COM_CSPAGEPROTECTOR_CAPTCHA_ENABLE_FAILED', $captcha), 'error');
        }

        $this->setRedirect($this->adminReturnUrl());
    }

    /**
     * The admin page in `return` (base64), same site only via safeReturnUrl;
     * anything else goes to the dashboard.
     *
     * @return  string
     *
     * @since   0.2.0
     */
    private function adminReturnUrl(): string
    {
        $return = ProtectionHelper::safeReturnUrl((string) base64_decode($this->input->getBase64('return', ''), true));

        return str_starts_with($return, Uri::base())
            ? $return
            : Route::_('index.php?option=com_cspageprotector&view=dashboard', false);
    }

    /**
     * Clear the pass in the admin's own browser so they can see the check
     * again while testing. Only affects this browser.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function clearmypass(): void
    {
        $this->checkToken();
        PermissionHelper::requireView();

        VerificationHelper::clearPass($this->app);

        $this->setMessage(Text::_('COM_CSPAGEPROTECTOR_CLEAR_MY_PASS_DONE'));
        $this->setRedirect(Route::_('index.php?option=com_cspageprotector&view=dashboard', false));
    }
}
