<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\View\Dashboard;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\SupportHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Model\DashboardModel;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Dashboard view.
 *
 * @since  0.1.0
 */
final class HtmlView extends BaseHtmlView
{
    /** @var array */
    public $checks = [];

    /** @var array<string, int> */
    public $stats = [];

    /** @var object[] */
    public $protectedItems = [];

    /** @var object[] */
    public $topIps = [];

    /** @var object[] */
    public $recent = [];

    /** @var string */
    public $optionsUrl = '';

    /** @var string */
    public $protectionMode = 'selected';

    /** @var boolean */
    public $canAdmin = false;

    /**
     * @param   string|null  $tpl  Template.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function display($tpl = null): void
    {
        /** @var DashboardModel $model */
        $model = $this->getModel();

        $this->checks         = $model->getChecks();
        $this->stats          = $model->getStats();
        $this->protectedItems = $model->getProtectedItems();
        $this->topIps         = $model->getTopIps();
        $this->recent         = $model->getRecent();
        $this->optionsUrl     = $model->optionsUrl();
        $this->protectionMode = (string) \Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper::getParams()->get('protection_mode', 'selected');
        $this->canAdmin       = PermissionHelper::canAdmin();

        $this->addToolbar();
        $this->getDocument()->setTitle(Text::_('COM_CSPAGEPROTECTOR_TITLE_DASHBOARD'));

        parent::display($tpl);
    }

    /**
     * @return  void
     *
     * @since   0.1.0
     */
    private function addToolbar(): void
    {
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_CSPAGEPROTECTOR_TITLE_DASHBOARD'), 'shield-alt');

        $toolbar->linkButton('cspp-logs', 'COM_CSPAGEPROTECTOR_SUBMENU_LOGS')
            ->url(Route::_('index.php?option=com_cspageprotector&view=logs', false))
            ->icon('icon-list')
            ->buttonClass('btn btn-secondary');

        // Submits the hidden form in the template (POST + token).
        $toolbar->customButton('cspp-clear-my-pass')
            ->html(
                '<button type="submit" form="csppClearMyPassForm" class="btn btn-secondary" title="'
                . htmlspecialchars(Text::_('COM_CSPAGEPROTECTOR_CLEAR_MY_PASS_DESC'), ENT_QUOTES, 'UTF-8') . '">'
                . '<span class="icon-unlock" aria-hidden="true"></span> '
                . htmlspecialchars(Text::_('COM_CSPAGEPROTECTOR_CLEAR_MY_PASS'), ENT_QUOTES, 'UTF-8')
                . '</button>'
            );

        if ($this->canAdmin) {
            $toolbar->customButton('cspp-reset-passes')
                ->html(
                    '<button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#csppResetPassesModal">'
                    . '<span class="icon-refresh" aria-hidden="true"></span> '
                    . htmlspecialchars(Text::_('COM_CSPAGEPROTECTOR_RESET_PASSES'), ENT_QUOTES, 'UTF-8')
                    . '</button>'
                );
        }

        if (PermissionHelper::canAdmin() || $this->getCurrentUser()->authorise('core.options', 'com_cspageprotector')) {
            $toolbar->preferences('com_cspageprotector');
        }

        $toolbar->help('COM_CSPAGEPROTECTOR_HELP', false, SupportHelper::REPO_URL);
    }
}
