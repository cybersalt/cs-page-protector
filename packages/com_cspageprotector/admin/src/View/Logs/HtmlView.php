<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\View\Logs;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\SupportHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Model\LogsModel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Event log view. Properties read by the searchtools layout must be public
 * (Joomla-Brain gotcha #23).
 *
 * @since  0.1.0
 */
final class HtmlView extends BaseHtmlView
{
    /** @var object[] */
    public $items;

    /** @var \Joomla\CMS\Pagination\Pagination */
    public $pagination;

    /** @var \Joomla\Registry\Registry */
    public $state;

    /** @var \Joomla\CMS\Form\Form|null */
    public $filterForm;

    /** @var array */
    public $activeFilters;

    /** @var array<string, int> */
    public $stats = [];

    /** @var boolean */
    public $canWrite = false;

    /**
     * @param   string|null  $tpl  Template.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function display($tpl = null): void
    {
        /** @var LogsModel $model */
        $model = $this->getModel();

        $this->items         = $model->getItems();
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();
        $this->stats         = $model->getStats();
        $this->canWrite      = PermissionHelper::canWrite();

        $this->addToolbar();
        $this->getDocument()->setTitle(Text::_('COM_CSPAGEPROTECTOR_TITLE_LOGS'));

        parent::display($tpl);
    }

    /**
     * Toolbar order per the Cybersalt log-viewer convention:
     * Refresh, Dump, Download CSV, Delete, Clear All.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    private function addToolbar(): void
    {
        $toolbar = $this->getDocument()->getToolbar();
        $token   = Session::getFormToken();

        ToolbarHelper::title(Text::_('COM_CSPAGEPROTECTOR_TITLE_LOGS'), 'list');

        $toolbar->linkButton('cspp-refresh', 'COM_CSPAGEPROTECTOR_BTN_REFRESH')
            ->url(Route::_('index.php?option=com_cspageprotector&view=logs', false))
            ->icon('icon-loop')
            ->buttonClass('btn btn-secondary');

        $toolbar->linkButton('cspp-dump', 'COM_CSPAGEPROTECTOR_BTN_DUMP')
            ->url(Route::_('index.php?option=com_cspageprotector&task=logs.dump&' . $token . '=1', false))
            ->icon('icon-copy')
            ->target('_blank')
            ->buttonClass('btn btn-secondary');

        $toolbar->linkButton('cspp-download', 'COM_CSPAGEPROTECTOR_BTN_DOWNLOAD_CSV')
            ->url(Route::_('index.php?option=com_cspageprotector&task=logs.download&' . $token . '=1', false))
            ->icon('icon-download')
            ->buttonClass('btn btn-secondary');

        if ($this->canWrite) {
            $toolbar->delete('logs.delete', 'JTOOLBAR_DELETE')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);

            $toolbar->customButton('cspp-clear-all')
                ->html(
                    '<button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#csppClearAllModal">'
                    . '<span class="icon-delete" aria-hidden="true"></span> '
                    . htmlspecialchars(Text::_('COM_CSPAGEPROTECTOR_BTN_CLEAR_ALL'), ENT_QUOTES, 'UTF-8')
                    . '</button>'
                );
        }

        if (PermissionHelper::canAdmin() || $this->getCurrentUser()->authorise('core.options', 'com_cspageprotector')) {
            $toolbar->preferences('com_cspageprotector');
        }

        $toolbar->help('COM_CSPAGEPROTECTOR_HELP', false, SupportHelper::REPO_URL);
    }
}
