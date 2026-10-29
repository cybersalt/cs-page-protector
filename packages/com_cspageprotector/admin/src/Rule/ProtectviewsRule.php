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
use Joomla\Registry\Registry;

/**
 * "Also protect these views": every entry must be component:view, so a typo
 * is refused on save instead of silently protecting nothing.
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
     * @since   __DEPLOY_VERSION__
     */
    public function test(\SimpleXMLElement $element, $value, $group = null, ?Registry $input = null, ?Form $form = null)
    {
        return ProtectionHelper::parseViewList((string) $value)['invalid'] === [];
    }
}
