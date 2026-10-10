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
use Joomla\CMS\Language\Text;

/**
 * Small rendering helpers shared by the admin templates.
 *
 * @since  0.1.0
 */
final class DisplayHelper
{
    /**
     * CSS for buttons that sit inside a coloured alert (the red "no
     * guarantee" box, the yellow "can't run its check" warning). A button the
     * same colour as its box disappears, worst in dark Atum, so they get a dark
     * border in light mode and, in dark mode, Atum's own action blue
     * (`--primary`, so a customised Atum colour carries over; Tim, 2026-10-10).
     * Must match the copy in pkg_cspageprotector/script.php (install card).
     *
     * @since  0.2.0
     */
    public const ALERT_BUTTON_CSS = '.cspp-alert-btn{border:2px solid #1f2937 !important;font-weight:600;}'
        . '.cspp-alert-btn:hover,.cspp-alert-btn:focus{filter:brightness(0.9);}'
        . 'html[data-bs-theme="dark"] .cspp-alert-btn,html[data-color-scheme="dark"] .cspp-alert-btn'
        . '{background-color:var(--primary,#007db0) !important;color:#fff !important;border-color:#1f2937 !important;}'
        . 'html[data-bs-theme="dark"] .cspp-alert-btn:hover,html[data-color-scheme="dark"] .cspp-alert-btn:hover,'
        . 'html[data-bs-theme="dark"] .cspp-alert-btn:focus,html[data-color-scheme="dark"] .cspp-alert-btn:focus'
        . '{filter:brightness(1.15);}';

    /**
     * Add ALERT_BUTTON_CSS to the page once (Web Asset Manager, so it gets a
     * CSP nonce when one is used). Safe to call from several places.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public static function addAlertButtonStyle(): void
    {
        static $added = false;

        if ($added) {
            return;
        }

        $added = true;

        Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineStyle(self::ALERT_BUTTON_CSS);
    }
    /**
     * Colour-coded Bootstrap badge for an event type.
     *
     * @param   string  $event  Event type.
     *
     * @return  string  Safe HTML.
     *
     * @since   0.1.0
     */
    public static function eventBadge(string $event): string
    {
        $class = match ($event) {
            LogHelper::EVENT_PASSED     => 'bg-success',
            LogHelper::EVENT_CHALLENGED => 'bg-info',
            LogHelper::EVENT_FAILED     => 'bg-warning text-dark',
            LogHelper::EVENT_BLOCKED,
            LogHelper::EVENT_ERROR      => 'bg-danger',
            default                     => 'bg-secondary',
        };

        return '<span class="badge ' . $class . '">' . htmlspecialchars(self::eventLabel($event), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /**
     * Translated label for an event type.
     *
     * @param   string  $event  Event type.
     *
     * @return  string  Plain text.
     *
     * @since   0.1.0
     */
    public static function eventLabel(string $event): string
    {
        return \in_array($event, LogHelper::EVENTS, true)
            ? Text::_('COM_CSPAGEPROTECTOR_EVENT_' . strtoupper($event))
            : $event;
    }

    /**
     * What to copy for a logged IP so it can be pasted into a firewall: the
     * address itself, or its range when the log stores shortened addresses
     * (IPv4 /24, IPv6 /48 - matching IpHelper::anonymize()).
     *
     * @param   string   $ip         Logged address.
     * @param   boolean  $shortened  Is IP shortening on?
     *
     * @return  string
     *
     * @since   0.1.0
     */
    public static function blockTarget(string $ip, bool $shortened): string
    {
        if (!$shortened || $ip === '') {
            return $ip;
        }

        return $ip . (str_contains($ip, ':') ? '/48' : '/24');
    }

    /**
     * Click-to-copy button (Cybersalt UX convention #1). Needs media/js/admin.js.
     *
     * @param   string  $value  Value to copy.
     * @param   string  $label  Accessible label.
     *
     * @return  string  Safe HTML.
     *
     * @since   0.1.0
     */
    public static function copyButton(string $value, string $label): string
    {
        return '<button type="button" class="btn btn-link btn-sm p-0 ms-1 cspp-copy" data-cspp-copy="'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="icon-copy" aria-hidden="true"></span></button>';
    }
}
