<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Rule;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormRule;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;

/**
 * "Also protect these views": every entry must be component:view, so a typo
 * is refused on save instead of silently protecting nothing. Entries that
 * would lock visitors or payment providers out of pages they need (login,
 * registration, password reset, privacy, payment returns and callbacks), or
 * that could never do anything (com_ajax, this component), are refused with
 * the reason.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ProtectviewsRule extends FormRule
{
    /**
     * @param   \SimpleXMLElement  $element  The field's XML element.
     * @param   mixed              $value    The field value.
     * @param   string|null        $group    Field group.
     * @param   Registry|null      $input    All submitted values.
     * @param   Form|null          $form     The form.
     *
     * @return  boolean
     *
     * @throws  \UnexpectedValueException  With the reason, for refused entries (Form shows its message).
     *
     * @since   __DEPLOY_VERSION__
     */
    public function test(\SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        if ($value === null || $value === '') {
            return true;
        }

        // Options sends a string; the Web Services API can send anything.
        if (!\is_string($value)) {
            return false;
        }

        $parsed = ProtectionHelper::parseViewList($value);

        if ($parsed['invalid'] !== []) {
            return false;
        }

        // Entries in these lists matched VIEW_ENTRY_PATTERN, so they hold
        // nothing but [a-z0-9_:].
        if ($parsed['reserved'] !== []) {
            throw new \UnexpectedValueException(Text::sprintf('COM_CSPAGEPROTECTOR_FIELD_PROTECT_VIEWS_RESERVED', implode(', ', $parsed['reserved'])));
        }

        if ($parsed['unchecked'] !== []) {
            throw new \UnexpectedValueException(Text::sprintf('COM_CSPAGEPROTECTOR_FIELD_PROTECT_VIEWS_NEVER_CHECKED', implode(', ', $parsed['unchecked'])));
        }

        return true;
    }
}
