<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Captcha\Captcha;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Thin wrapper over Joomla's own captcha framework.
 *
 * The challenge page never talks to a specific captcha directly — it asks
 * Joomla for "the captcha plugin named X" and calls display() / checkAnswer().
 * That makes Joomla 6.1's core Proof-of-Work captcha the default today, and
 * means any other Joomla captcha plugin (reCAPTCHA, Turnstile, hCaptcha, ...)
 * slots in later with no change to the protection logic.
 *
 * @since  0.1.0
 */
final class CaptchaHelper
{
    public const DEFAULT_PLUGIN = 'powcaptcha';

    /**
     * Name of the form field the captcha answer is posted in.
     */
    public const FIELD_NAME = 'cspp_captcha';

    /**
     * The captcha plugin element configured for the challenge page.
     *
     * @param   Registry  $params  Component params.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    public static function getConfiguredPlugin(Registry $params): string
    {
        $plugin = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $params->get('captcha_plugin', self::DEFAULT_PLUGIN)));

        return $plugin !== '' ? $plugin : self::DEFAULT_PLUGIN;
    }

    /**
     * Is the named captcha plugin installed and enabled?
     *
     * @param   string  $plugin  Plugin element.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function isAvailable(string $plugin): bool
    {
        return $plugin !== '' && PluginHelper::isEnabled('captcha', $plugin);
    }

    /**
     * Get a captcha instance for the challenge page, or null if it can't be loaded.
     *
     * @param   string  $plugin  Plugin element.
     *
     * @return  Captcha|null
     *
     * @since   0.1.0
     */
    public static function getInstance(string $plugin): ?Captcha
    {
        if (!self::isAvailable($plugin)) {
            return null;
        }

        try {
            return Captcha::getInstance($plugin, ['namespace' => 'cspageprotector']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Every installed captcha plugin, enabled or not — feeds the Options picker
     * and the dashboard health check.
     *
     * @return  array<int, object{element: string, name: string, enabled: int}>
     *
     * @since   0.1.0
     */
    public static function getInstalledPlugins(): array
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select([$db->quoteName('element'), $db->quoteName('name'), $db->quoteName('enabled')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('captcha'))
                ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('name') . ' ASC');

            return $db->setQuery($query)->loadObjectList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Extension id of a captcha plugin, for "open plugin settings" links.
     *
     * @param   string  $plugin  Plugin element.
     *
     * @return  integer  0 when not installed.
     *
     * @since   0.1.0
     */
    public static function getExtensionId(string $plugin): int
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('captcha'))
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':element', $plugin);

            return (int) $db->setQuery($query)->loadResult();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
