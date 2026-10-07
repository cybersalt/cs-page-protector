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
 * Event-log filter: the menu items that actually appear in the log, so the
 * dropdown never offers a page with zero matching rows.
 *
 * @since  0.1.0
 */
final class LogmenuitemField extends ListField
{
    /**
     * @var    string
     * @since  0.1.0
     */
    protected $type = 'Logmenuitem';

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
                ->select('DISTINCT ' . $db->quoteName('l.menu_item_id', 'value'))
                ->select($db->quoteName('m.title', 'title'))
                ->from($db->quoteName('#__cspageprotector_log', 'l'))
                ->join('LEFT', $db->quoteName('#__menu', 'm'), $db->quoteName('m.id') . ' = ' . $db->quoteName('l.menu_item_id'))
                ->order($db->quoteName('m.title') . ' ASC');

            foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
                $id    = (int) $row->value;
                $label = $id === 0
                    ? Text::_('COM_CSPAGEPROTECTOR_NO_MENU_ITEM')
                    : ($row->title !== null ? $row->title . ' (#' . $id . ')' : Text::sprintf('COM_CSPAGEPROTECTOR_DELETED_MENU_ITEM', $id));

                $options[] = HTMLHelper::_('select.option', (string) $id, $label);
            }
        } catch (\Throwable $e) {
            // Table missing mid-install: an empty dropdown is fine.
        }

        return array_merge(parent::getOptions(), $options);
    }
}
