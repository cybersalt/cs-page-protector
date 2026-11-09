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
        $returnUrl = self::safeReturnUrl($this->input->post->getBase64('return', ''));

        $this->setRedirect($returnUrl);

        if (strtoupper($this->input->getMethod()) !== 'POST') {
            return;
        }

        // A form submit the check interrupted, carried by the check page
        // (see VerificationHelper::holdForm()).
        $form = $this->input->post->get('cspp_form', null, 'raw');

        // Then send it on, passed or not: without a pass it simply gets the
        // check once more, and a plain redirect would lose what the visitor
        // filled in. Only with a valid token, so another site can't use the
        // check page to post a form of its own.
        if ($this->checkAnswer($returnUrl) && \is_array($form) && $form !== []) {
            $this->sendForm($returnUrl, $form);
        }
    }

    /**
     * Check the captcha answer and, on success, issue the pass.
     *
     * @param   string  $returnUrl  Where the visitor goes next.
     *
     * @return  boolean  False when the form token wasn't valid.
     *
     * @since   0.3.1
     */
    private function checkAnswer(string $returnUrl): bool
    {
        $params = ProtectionHelper::getParams();

        if (!$this->checkToken('post', false)) {
            $this->app->enqueueMessage(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_EXPIRED'), 'warning');

            return false;
        }

        $ip          = IpHelper::getClientIp($params);
        $userAgent   = $this->input->server->getString('HTTP_USER_AGENT', '');
        $menuItemId  = max(0, $this->input->post->getInt('cspp_item', 0));
        $captchaName = CaptchaHelper::getConfiguredPlugin($params);
        $captcha     = CaptchaHelper::getInstance($captchaName);

        if ($captcha === null) {
            LogHelper::log($params, LogHelper::EVENT_ERROR, $ip, $userAgent, $returnUrl, $menuItemId, $captchaName, 'verify: captcha unavailable');
            $this->app->enqueueMessage(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_UNAVAILABLE'), 'warning');

            return true;
        }

        $raw    = $this->input->post->get(CaptchaHelper::FIELD_NAME, '', 'raw');
        $answer = \is_string($raw) ? substr($raw, 0, self::MAX_ANSWER_BYTES) : '';
        // Where the check was started: a protected module's inline check, or the page check.
        $source = $this->input->post->getCmd('cspp_source', '') === 'module' ? 'module' : '';
        $detail = $source;

        try {
            // Legacy captcha plugins read their own POST field when given null.
            $passed = (bool) $captcha->checkAnswer($answer !== '' ? $answer : null);
        } catch (\Throwable $e) {
            $passed = false;
            $detail = trim($source . ' ' . mb_substr($e->getMessage(), 0, 200));
            Log::add('cs-page-protector captcha check threw: ' . $e->getMessage(), Log::WARNING, 'com_cspageprotector');
        }

        if (!$passed) {
            LogHelper::log($params, LogHelper::EVENT_FAILED, $ip, $userAgent, $returnUrl, $menuItemId, $captchaName, $detail);
            $this->app->enqueueMessage(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_FAILED'), 'warning');

            return true;
        }

        VerificationHelper::markVerified($this->app, $params, $ip, $userAgent);
        LogHelper::log($params, LogHelper::EVENT_PASSED, $ip, $userAgent, $returnUrl, $menuItemId, $captchaName, $source);

        return true;
    }

    /**
     * Answer with a page that posts the carried form to where it was sent, by
     * itself (a Continue button when scripts can't run), and stop.
     *
     * @param   string  $url   Where the form was sent (already made safe).
     * @param   array   $data  The posted fields.
     *
     * @return  void
     *
     * @since   0.3.1
     */
    private function sendForm(string $url, array $data): void
    {
        $esc   = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nonce = (string) $this->app->get('csp_nonce', '');
        $nonce = $nonce !== '' ? ' nonce="' . $esc($nonce) . '"' : '';

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
            . '<title>' . $esc(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_HEADING_DEFAULT')) . '</title></head><body>'
            . '<form id="cspp-form-again" method="post" action="' . $esc($url) . '">'
            . VerificationHelper::hiddenFields($data)
            . '<p>' . $esc(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_DONE')) . '</p>'
            . '<button type="submit">' . $esc(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_CONTINUE')) . '</button></form>'
            . '<script' . $nonce . '>document.getElementById("cspp-form-again").submit();</script>'
            . '</body></html>';

        $this->app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $this->app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $this->app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $this->app->sendHeaders();

        echo $html;

        $this->app->close();
    }

    /**
     * Decode the posted return URL and keep it on this site (no open redirect).
     *
     * @param   string  $encoded  Base64 URL.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    private static function safeReturnUrl(string $encoded): string
    {
        $url = $encoded !== '' ? base64_decode($encoded, true) : false;

        return ProtectionHelper::safeReturnUrl(\is_string($url) ? $url : '');
    }
}
