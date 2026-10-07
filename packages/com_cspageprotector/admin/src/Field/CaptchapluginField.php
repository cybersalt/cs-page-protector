<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Field;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\CaptchaHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * Picker listing every installed Joomla captcha plugin. Disabled plugins are
 * still listed (marked "disabled") so the admin can see Proof-of-Work is there
 * and go switch it on, rather than wondering why it's missing.
 *
 * @since  0.1.0
 */
final class CaptchapluginField extends ListField
{
    /**
     * @var    string
     * @since  0.1.0
     */
    protected $type = 'Captchaplugin';

    /**
     * @return  object[]
     *
     * @since   0.1.0
     */
    protected function getOptions()
    {
        $options = [];
        $lang    = Factory::getApplication()->getLanguage();
        $found   = false;

        foreach (CaptchaHelper::getInstalledPlugins() as $plugin) {
            $element = (string) $plugin->element;
            $found   = $found || $element === CaptchaHelper::DEFAULT_PLUGIN;

            $lang->load('plg_captcha_' . $element . '.sys', JPATH_ADMINISTRATOR)
                || $lang->load('plg_captcha_' . $element . '.sys', JPATH_PLUGINS . '/captcha/' . $element);

            $label = Text::_((string) $plugin->name);

            if ((int) $plugin->enabled !== 1) {
                $label = Text::sprintf('COM_CSPAGEPROTECTOR_CAPTCHA_PLUGIN_DISABLED', $label);
            }

            $options[] = HTMLHelper::_('select.option', $element, $label);
        }

        // Joomla 5 / 6.0 have no Proof-of-Work plugin; keep the default visible
        // so the saved value still means something after a 6.1 upgrade.
        if (!$found) {
            array_unshift(
                $options,
                HTMLHelper::_('select.option', CaptchaHelper::DEFAULT_PLUGIN, Text::_('COM_CSPAGEPROTECTOR_CAPTCHA_PLUGIN_POW_MISSING'))
            );
        }

        return array_merge(parent::getOptions(), $options);
    }
}
