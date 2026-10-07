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
use Joomla\CMS\Form\Field\GroupedlistField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

/**
 * Picker for front-end modules, grouped by position (like Joomla's own
 * module lists). Trashed modules are left out; unpublished ones are marked.
 *
 * @since  0.1.0
 */
final class SitemodulesField extends GroupedlistField
{
    /**
     * @var    string
     * @since  0.1.0
     */
    protected $type = 'Sitemodules';

    /**
     * @return  array<string, object[]>
     *
     * @since   0.1.0
     */
    protected function getGroups()
    {
        $groups = parent::getGroups();

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select($db->quoteName(['id', 'title', 'position', 'module', 'published']))
                ->from($db->quoteName('#__modules'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('published') . ' IN (0, 1)')
                ->order([$db->quoteName('position') . ' ASC', $db->quoteName('ordering') . ' ASC', $db->quoteName('title') . ' ASC']);

            foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
                $group = (string) $row->position !== '' ? (string) $row->position : Text::_('COM_CSPAGEPROTECTOR_NO_POSITION');
                $label = $row->title . ' (' . $row->module . ', #' . (int) $row->id . ')';

                if ((int) $row->published === 0) {
                    $label .= ' - ' . Text::_('JUNPUBLISHED');
                }

                $groups[$group][] = HTMLHelper::_('select.option', (string) (int) $row->id, $label);
            }
        } catch (\Throwable $e) {
            // Leave the picker empty rather than break the Options page.
        }

        return $groups;
    }
}
