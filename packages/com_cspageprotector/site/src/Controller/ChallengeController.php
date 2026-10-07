<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Site\Controller;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\CaptchaHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\IpHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\LogHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\VerificationHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;

/**
 * Receives the challenge form, checks the captcha answer and, on success,
 * issues the visitor a pass before sending them back to the page they wanted.
 *
 * @since  0.1.0
 */
final class ChallengeController extends BaseController
{
    /**
     * Largest captcha payload we'll hand to a captcha plugin. An ALTCHA
     * payload is a few hundred bytes; anything huge is junk.
     */
    private const MAX_ANSWER_BYTES = 8192;

    /**
     * POST target of the challenge form.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function verify(): void
    {
        $params    = ProtectionHelper::getParams();
        $returnUrl = self::safeReturnUrl($this->input->post->getBase64('return', ''));

        $this->setRedirect($returnUrl);

        if (strtoupper($this->input->getMethod()) !== 'POST') {
            return;
        }

        if (!$this->checkToken('post', false)) {
            $this->app->enqueueMessage(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_EXPIRED'), 'warning');

            return;
        }

        $ip          = IpHelper::getClientIp($params);
        $userAgent   = $this->input->server->getString('HTTP_USER_AGENT', '');
        $menuItemId  = $this->input->post->getInt('cspp_item', 0);
        $captchaName = CaptchaHelper::getConfiguredPlugin($params);
        $captcha     = CaptchaHelper::getInstance($captchaName);

        if ($captcha === null) {
            LogHelper::log($params, LogHelper::EVENT_ERROR, $ip, $userAgent, $returnUrl, $menuItemId, $captchaName, 'verify: captcha unavailable');
            $this->app->enqueueMessage(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_UNAVAILABLE'), 'warning');

            return;
        }

        $raw    = $this->input->post->get(CaptchaHelper::FIELD_NAME, '', 'raw');
        $answer = \is_string($raw) ? substr($raw, 0, self::MAX_ANSWER_BYTES) : '';
        $detail = '';

        try {
            // Legacy captcha plugins read their own POST field when given null.
            $passed = (bool) $captcha->checkAnswer($answer !== '' ? $answer : null);
        } catch (\Throwable $e) {
            $passed = false;
            $detail = mb_substr($e->getMessage(), 0, 200);
            Log::add('cs-page-protector captcha check threw: ' . $e->getMessage(), Log::WARNING, 'com_cspageprotector');
        }

        if (!$passed) {
            LogHelper::log($params, LogHelper::EVENT_FAILED, $ip, $userAgent, $returnUrl, $menuItemId, $captchaName, $detail);
            $this->app->enqueueMessage(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_FAILED'), 'warning');

            return;
        }

        VerificationHelper::markVerified($this->app, $params, $ip, $userAgent);
        LogHelper::log($params, LogHelper::EVENT_PASSED, $ip, $userAgent, $returnUrl, $menuItemId, $captchaName);
    }

    /**
     * Decode and validate the return URL. Only same-site URLs are accepted, so
     * the form can't be abused as an open redirect.
     *
     * @param   string  $encoded  Base64 URL.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    private static function safeReturnUrl(string $encoded): string
    {
        $fallback = Uri::root();
        $url      = $encoded !== '' ? base64_decode($encoded, true) : false;

        if (!\is_string($url) || $url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return $fallback;
        }

        // Protocol-relative and backslash tricks ("//evil.tld", "/\evil.tld").
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\') || str_contains($url, '\\')) {
            return $fallback;
        }

        return Uri::isInternal($url) ? $url : $fallback;
    }
}
