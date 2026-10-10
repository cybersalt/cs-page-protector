<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Field;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\NoticeHelper;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/**
 * Branded header card at the top of every Options tab: logo, extension name,
 * and a one-line description of what the tab is for. Renders no input.
 *
 * Follows the Cybersalt "Branded Tab Header on Every Settings Fieldset"
 * convention (white card + cobalt in light Atum, slate card + white-disc logo
 * in dark Atum).
 *
 * @since  0.1.0
 */
final class BrandheaderField extends FormField
{
    /**
     * @var    string
     * @since  0.1.0
     */
    protected $type = 'Brandheader';

    /**
     * The CSS only needs to be printed once per page, however many tabs use it.
     *
     * @var    boolean
     * @since  0.1.0
     */
    private static bool $cssPrinted = false;

    /**
     * @param   array  $options  Unused.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    public function renderField($options = [])
    {
        if ($this->hidden) {
            return '';
        }

        $logoUrl = htmlspecialchars(Uri::root() . 'media/com_cspageprotector/images/logo.svg', ENT_QUOTES, 'UTF-8');
        $title   = htmlspecialchars(Text::_('COM_CSPAGEPROTECTOR'), ENT_QUOTES, 'UTF-8');

        $subtitleKey  = (string) ($this->element['subtitle'] ?? '');
        $subtitleHtml = $subtitleKey !== ''
            ? '<small>' . htmlspecialchars(Text::_($subtitleKey), ENT_QUOTES, 'UTF-8') . '</small>'
            : '';

        $css = '';

        if (!self::$cssPrinted) {
            self::$cssPrinted = true;
            $css = <<<CSS
<style>
.cs-plugin-tab-header {
    --cs-tab-header-bg: #fff;
    --cs-tab-header-border: rgba(0, 0, 0, 0.1);
    --cs-tab-header-subtitle: rgba(0, 0, 0, 0.6);
    display: flex;
    align-items: center;
    gap: 1rem;
    margin: 0 0 1.5rem;
    padding: 0.75rem 1rem;
    border: 1px solid var(--cs-tab-header-border);
    border-radius: 0.375rem;
    background-color: var(--cs-tab-header-bg);
}
html[data-bs-theme="dark"] .cs-plugin-tab-header,
html[data-color-scheme="dark"] .cs-plugin-tab-header {
    --cs-tab-header-bg: #1f2937;
    --cs-tab-header-border: rgba(255, 255, 255, 0.1);
    --cs-tab-header-subtitle: rgba(255, 255, 255, 0.7);
}
.cs-plugin-tab-header img {
    height: 48px;
    width: 48px;
    flex: 0 0 48px;
}
html[data-bs-theme="dark"] .cs-plugin-tab-header img,
html[data-color-scheme="dark"] .cs-plugin-tab-header img {
    background-color: #fff;
    border-radius: 50%;
    padding: 6px;
    box-sizing: content-box;
}
.cs-plugin-tab-header-text h4 {
    margin: 0;
    font-size: 1.15rem;
    line-height: 1.2;
    color: inherit;
}
.cs-plugin-tab-header-text small {
    display: block;
    margin-top: 0.15rem;
    color: var(--cs-tab-header-subtitle);
    font-size: 0.85rem;
}
</style>
CSS;
        }

        // The "no guarantee" notice sits under the header on every tab until it's accepted.
        $notice = NoticeHelper::renderBox();

        return <<<HTML
{$css}
<div class="cs-plugin-tab-header">
    <img src="{$logoUrl}" alt="" />
    <div class="cs-plugin-tab-header-text">
        <h4>{$title}</h4>
        {$subtitleHtml}
    </div>
</div>
{$notice}
HTML;
    }

    /**
     * @return  string
     *
     * @since   0.1.0
     */
    protected function getInput()
    {
        return '';
    }
}
