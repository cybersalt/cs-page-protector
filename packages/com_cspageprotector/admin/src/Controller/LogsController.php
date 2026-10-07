<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Controller;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Model\LogsModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Event-log actions: CSV download, plain-text dump, delete selected, clear all.
 *
 * @since  0.1.0
 */
final class LogsController extends BaseController
{
    /**
     * Stream the currently filtered rows as CSV. Token-checked GET.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function download(): void
    {
        $this->checkToken('get');
        PermissionHelper::requireView();

        $csv  = $this->getLogsModel()->getCsv();
        $name = preg_replace('/[^A-Za-z0-9._-]/', '-', 'cspageprotector-log-' . gmdate('Ymd-His') . '.csv');

        $this->sendFile($csv, 'text/csv; charset=utf-8', 'attachment; filename="' . $name . '"');
    }

    /**
     * Show the currently filtered rows as plain text, for pasting into a support ticket.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function dump(): void
    {
        $this->checkToken('get');
        PermissionHelper::requireView();

        $this->sendFile($this->getLogsModel()->getTextDump(), 'text/plain; charset=utf-8', 'inline');
    }

    /**
     * Delete the ticked rows.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function delete(): void
    {
        $this->checkToken();
        PermissionHelper::requireWrite();

        $ids = array_values(array_filter(
            array_map('intval', (array) $this->input->get('cid', [], 'array')),
            static fn (int $id): bool => $id > 0
        ));

        if ($ids === []) {
            $this->setMessage(Text::_('JLIB_HTML_PLEASE_MAKE_A_SELECTION_FROM_THE_LIST'), 'warning');
        } else {
            $db    = $this->db();
            $query = $db->createQuery()
                ->delete($db->quoteName('#__cspageprotector_log'))
                ->whereIn($db->quoteName('id'), $ids, ParameterType::INTEGER);
            $db->setQuery($query)->execute();

            $this->setMessage(Text::plural('COM_CSPAGEPROTECTOR_LOGS_N_DELETED', \count($ids)));
        }

        $this->setRedirect(Route::_('index.php?option=com_cspageprotector&view=logs', false));
    }

    /**
     * Empty the whole log (confirmed in a modal, POST + token).
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function purge(): void
    {
        $this->checkToken();
        PermissionHelper::requireWrite();

        $db = $this->db();
        $db->setQuery('TRUNCATE TABLE ' . $db->quoteName('#__cspageprotector_log'))->execute();

        $this->setMessage(Text::_('COM_CSPAGEPROTECTOR_LOGS_PURGED'));
        $this->setRedirect(Route::_('index.php?option=com_cspageprotector&view=logs', false));
    }

    /**
     * The list model, populated from the same user state as the Logs view so
     * exports honour whatever filters are active.
     *
     * @return  LogsModel
     *
     * @since   0.1.0
     */
    private function getLogsModel(): LogsModel
    {
        /** @var LogsModel $model */
        $model = $this->getModel('Logs', 'Administrator', ['ignore_request' => false]);

        // Touch state so populateState() runs before we lift the page limit.
        $model->getState();
        $model->setState('list.start', 0);
        $model->setState('list.limit', 0);

        return $model;
    }

    /**
     * Send a body with safe headers and stop.
     *
     * @param   string  $body         Response body.
     * @param   string  $contentType  Content-Type header.
     * @param   string  $disposition  Content-Disposition header.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    private function sendFile(string $body, string $contentType, string $disposition): void
    {
        $app = Factory::getApplication();
        $app->setHeader('Content-Type', $contentType, true);
        $app->setHeader('Content-Disposition', $disposition, true);
        $app->setHeader('Cache-Control', 'no-store', true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $app->sendHeaders();

        echo $body;

        $app->close();
    }

    /**
     * @return  DatabaseInterface
     *
     * @since   0.1.0
     */
    private function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
