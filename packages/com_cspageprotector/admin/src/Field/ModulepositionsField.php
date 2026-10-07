<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

/**
 * Picker for front-end module positions that have at least one module, with
 * the number of modules in each.
 *
 * @since  0.1.0
 */
final class ModulepositionsField extends ListField
{
    /**
     * @var    string
     * @since  0.1.0
     */
    protected $type = 'Modulepositions';

    /**
     * @return  object[]
     *
     * @since   0.1.0
     */
    protected function getOptions()
    {
        $options = [];

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select([$db->quoteName('position'), 'COUNT(*) AS ' . $db->quoteName('n')])
                ->from($db->quoteName('#__modules'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('published') . ' IN (0, 1)')
                ->where($db->quoteName('position') . ' <> ' . $db->quote(''))
                ->group($db->quoteName('position'))
                ->order($db->quoteName('position') . ' ASC');

            foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
                $options[] = HTMLHelper::_(
                    'select.option',
                    (string) $row->position,
                    Text::plural('COM_CSPAGEPROTECTOR_POSITION_WITH_N_MODULES', (int) $row->n, $row->position)
                );
            }
        } catch (\Throwable $e) {
            // Leave the picker empty rather than break the Options page.
        }

        return array_merge(parent::getOptions(), $options);
    }
}
