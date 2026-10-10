<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

/**
 * The "no guarantee" notice. A red box at the top of every Page Protector
 * admin page until someone who can change Options accepts it, once for the
 * whole site. Who accepted it and when is kept in the component params
 * (hidden fields in config.xml, so an Options save keeps them).
 *
 * @since  0.2.0
 */
final class NoticeHelper
{
    /**
     * Has the notice been accepted on this site?
     *
     * @param   Registry  $params  Component params.
     *
     * @return  boolean
     *
     * @since   0.2.0
     */
    public static function isAccepted(Registry $params): bool
    {
        return (string) $params->get('notice_accepted_at', '') !== '';
    }

    /**
     * Record that this user accepted the notice for the site.
     *
     * @param   User  $user  Who accepted it.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public static function accept(User $user): void
    {
        $params = clone ProtectionHelper::getParams();
        $params->set('notice_accepted_by', (int) $user->id);
        $params->set('notice_accepted_name', (string) $user->name);
        $params->set('notice_accepted_at', Factory::getDate()->toSql());

        ProtectionHelper::saveParams($params);
    }

    /**
     * "Accepted by Tim Davis on 9 October 2026." or '' when not accepted.
     *
     * @param   Registry  $params  Component params.
     *
     * @return  string  Plain text.
     *
     * @since   0.2.0
     */
    public static function acceptedText(Registry $params): string
    {
        if (!self::isAccepted($params)) {
            return '';
        }

        return Text::sprintf(
            'COM_CSPAGEPROTECTOR_NOTICE_ACCEPTED',
            (string) $params->get('notice_accepted_name', ''),
            HTMLHelper::_('date', (string) $params->get('notice_accepted_at'), Text::_('DATE_FORMAT_LC3'))
        );
    }

    /**
     * The red box, or '' once the notice is accepted.
     *
     * Options and the installer wrap everything in their own form, whose
     * hidden `task` field would win over ours, so the button doesn't submit
     * any surrounding form: a small script builds a separate POST form with
     * the session token (the same approach is used in the install card).
     *
     * @return  string  HTML.
     *
     * @since   0.2.0
     */
    public static function renderBox(): string
    {
        if (self::isAccepted(ProtectionHelper::getParams())) {
            return '';
        }

        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (PermissionHelper::canConfigure()) {
            $return = base64_encode(Uri::getInstance()->toString(['path', 'query']));
            $action = 'index.php?option=com_cspageprotector&task=dashboard.acceptnotice&return=' . urlencode($return);

            DisplayHelper::addAlertButtonStyle();

            $footer = '<button type="button" class="btn btn-danger btn-sm cspp-alert-btn" data-cspp-accept="' . $e($action) . '"'
                . ' data-cspp-token="' . $e(Session::getFormToken()) . '">'
                . $e(Text::_('COM_CSPAGEPROTECTOR_NOTICE_ACCEPT')) . '</button>'
                . self::acceptScript();
        } else {
            $footer = '<p class="mb-0 small">' . $e(Text::_('COM_CSPAGEPROTECTOR_NOTICE_ADMIN_ONLY')) . '</p>';
        }

        return '<div class="alert alert-danger cspp-notice" role="alert">'
            . '<h2 class="alert-heading h5"><span class="icon-warning" aria-hidden="true"></span> '
            . $e(Text::_('COM_CSPAGEPROTECTOR_NO_GUARANTEE_LABEL')) . '</h2>'
            . '<p>' . $e(Text::_('COM_CSPAGEPROTECTOR_NO_GUARANTEE_BODY')) . '</p>'
            . $footer
            . '</div>';
    }

    /**
     * Click handler for every accept button, printed once per page. Added
     * through the Web Asset Manager so it gets a CSP nonce when one is used.
     *
     * @return  string  Always '' (the script goes in the document head).
     *
     * @since   0.2.0
     */
    private static function acceptScript(): string
    {
        static $added = false;

        if ($added) {
            return '';
        }

        $added = true;

        Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineScript(
            "if (!window.csppAcceptBound) { window.csppAcceptBound = true;"
            . "document.addEventListener('click', (e) => {"
            . "const b = e.target.closest('[data-cspp-accept]'); if (!b) { return; }"
            . "e.preventDefault(); b.disabled = true;"
            . "const f = document.createElement('form'); f.method = 'post'; f.action = b.dataset.csppAccept;"
            . "const t = document.createElement('input'); t.type = 'hidden'; t.name = b.dataset.csppToken; t.value = '1';"
            . "f.appendChild(t); document.body.appendChild(f); f.submit();"
            . "}); }"
        );

        return '';
    }
}
