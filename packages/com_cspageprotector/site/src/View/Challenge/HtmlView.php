<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Site\View\Challenge;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\CaptchaHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\IpHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\LogHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * The challenge page. Rendered in place of a protected page's content (same
 * URL, same menu item, same template) for visitors without a pass.
 *
 * @since  0.1.0
 */
final class HtmlView extends BaseHtmlView
{
    /** @var string Safe HTML from the captcha plugin. */
    public $captchaHtml = '';

    /** @var string Plain text. */
    public $heading = '';

    /** @var string Admin-supplied HTML, filtered with safehtml on save. */
    public $message = '';

    /** @var string Base64 URL of the protected page. */
    public $returnB64 = '';

    /** @var integer */
    public $itemId = 0;

    /** @var boolean */
    public $autoStart = true;

    /** @var boolean */
    public $autoSubmit = true;

    /** @var string */
    public $formAction = '';

    /**
     * @param   string|null  $tpl  Template.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function display($tpl = null): void
    {
        $app    = Factory::getApplication();
        $params = ProtectionHelper::getParams();
        $input  = $app->getInput();

        $this->itemId     = ProtectionHelper::getActiveItemId($app);

        // Reached through a protected module's "Show content" button rather
        // than the in-place page check (which the plugin already logged).
        if ($input->getInt('cspp_inplace', 0) !== 1) {
            LogHelper::log(
                $params,
                LogHelper::EVENT_CHALLENGED,
                IpHelper::getClientIp($params),
                $input->server->getString('HTTP_USER_AGENT', ''),
                (string) (base64_decode($input->getBase64('cspp_return', ''), true) ?: Uri::getInstance()->toString()),
                $this->itemId,
                CaptchaHelper::getConfiguredPlugin($params),
                'module'
            );
        }
        $this->returnB64  = $input->getBase64('cspp_return', '') ?: base64_encode(Uri::root());
        $this->autoStart  = (int) $params->get('auto_start', 1) === 1;
        $this->autoSubmit = (int) $params->get('auto_submit', 1) === 1;
        $this->formAction = Route::_('index.php?option=com_cspageprotector&task=challenge.verify' . ($this->itemId ? '&Itemid=' . $this->itemId : ''));

        $heading       = trim((string) $params->get('challenge_heading', ''));
        $this->heading = $heading !== '' ? $heading : Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_HEADING_DEFAULT');

        $message       = trim((string) $params->get('challenge_message', ''));
        $this->message = $message !== '' ? $message : htmlspecialchars(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_MESSAGE_DEFAULT'), ENT_QUOTES, 'UTF-8');

        $captcha = CaptchaHelper::getInstance(CaptchaHelper::getConfiguredPlugin($params));

        if ($captcha !== null) {
            try {
                $this->captchaHtml = (string) $captcha->display(CaptchaHelper::FIELD_NAME, 'cspp-captcha', 'cspp-captcha');
            } catch (\Throwable $e) {
                $this->captchaHtml = '';
            }
        }

        $this->prepareResponse($params->get('challenge_status', 200));

        parent::display($tpl);
    }

    /**
     * Headers and document metadata: never cache, never index, optional 403.
     *
     * @param   mixed  $status  Configured HTTP status.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    private function prepareResponse($status): void
    {
        $app      = Factory::getApplication();
        $document = $this->getDocument();

        if ((int) $status === 403) {
            $app->setHeader('Status', '403', true);
        }

        $app->allowCache(false);
        $app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);

        $document->setMetaData('robots', 'noindex, nofollow');
        $document->setTitle($this->heading);
    }
}
