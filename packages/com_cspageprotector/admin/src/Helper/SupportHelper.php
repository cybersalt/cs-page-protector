<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;

/**
 * Builds the "please contact support" sentence used anywhere the extension
 * needs to point a site admin (or a stuck visitor) at a human.
 *
 * Reads the configurable support_email / support_url / support_label options
 * so a reseller can swap the defaults without forking the code.
 *
 * @since  0.1.0
 */
final class SupportHelper
{
    public const DEFAULT_EMAIL = 'support@cybersalt.com';
    public const DEFAULT_URL   = 'https://www.cybersalt.com/services/support-request-form';
    public const REPO_URL      = 'https://github.com/cybersalt/cs-page-protector';

    /**
     * "Please contact Cybersalt support (support@... / https://...) for assistance."
     *
     * @return  string  Plain text (escape before echoing).
     *
     * @since   0.1.0
     */
    public static function getContactSentence(): string
    {
        $parts = array_values(array_filter([self::getEmail(), self::getUrl()], 'strlen'));

        if ($parts === []) {
            return Text::sprintf('COM_CSPAGEPROTECTOR_SUPPORT_CONTACT_PLAIN', self::getLabel());
        }

        return Text::sprintf('COM_CSPAGEPROTECTOR_SUPPORT_CONTACT_WITH_DETAILS', self::getLabel(), implode(' / ', $parts));
    }

    /**
     * Configured support email.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    public static function getEmail(): string
    {
        return trim((string) ComponentHelper::getParams('com_cspageprotector')->get('support_email', self::DEFAULT_EMAIL));
    }

    /**
     * Configured support URL (only http/https URLs are returned).
     *
     * @return  string
     *
     * @since   0.1.0
     */
    public static function getUrl(): string
    {
        $url = trim((string) ComponentHelper::getParams('com_cspageprotector')->get('support_url', self::DEFAULT_URL));

        return preg_match('#^https?://#i', $url) ? $url : '';
    }

    /**
     * Configured support label.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    public static function getLabel(): string
    {
        $label = trim((string) ComponentHelper::getParams('com_cspageprotector')->get('support_label', ''));

        return $label !== '' ? $label : Text::_('COM_CSPAGEPROTECTOR_SUPPORT_FALLBACK_LABEL');
    }
}
