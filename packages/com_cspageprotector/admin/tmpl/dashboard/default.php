<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 *
 * @var \Cybersalt\Component\Cspageprotector\Administrator\View\Dashboard\HtmlView $this
 */

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\DisplayHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\LogHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\SupportHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

$wa = $this->getDocument()->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('com_cspageprotector');
$wa->useStyle('com_cspageprotector.admin')->useScript('com_cspageprotector.admin');

HTMLHelper::_('bootstrap.modal');
Text::script('COM_CSPAGEPROTECTOR_COPIED');

// With shortened IPs the copy button copies the range, ready for a firewall rule.
$ipsShortened = LogHelper::anonymizeEnabled(ProtectionHelper::getParams());

$logsBase = 'index.php?option=com_cspageprotector&view=logs';
$logsUrl  = static fn (array $filters = []): string => Route::_(
    $logsBase . ($filters ? '&' . http_build_query(['filter' => $filters]) : ''),
    false
);

$levelIcon = [
    'danger'  => 'icon-times-circle text-danger',
    'warning' => 'icon-warning text-warning',
    'info'    => 'icon-info-circle text-info',
    'success' => 'icon-check-circle text-success',
];

$cards = [
    ['value' => $this->stats['challenged'] ?? 0, 'label' => 'COM_CSPAGEPROTECTOR_STAT_CHALLENGED', 'href' => $logsUrl(['event' => 'challenged', 'since' => '24h']), 'tone' => ''],
    ['value' => $this->stats['passed'] ?? 0,     'label' => 'COM_CSPAGEPROTECTOR_STAT_PASSED',     'href' => $logsUrl(['event' => 'passed', 'since' => '24h']),     'tone' => 'text-success'],
    ['value' => $this->stats['failed'] ?? 0,     'label' => 'COM_CSPAGEPROTECTOR_STAT_FAILED',     'href' => $logsUrl(['event' => 'failed', 'since' => '24h']),     'tone' => ($this->stats['failed'] ?? 0) > 0 ? 'text-warning' : ''],
    ['value' => $this->stats['blocked'] ?? 0,    'label' => 'COM_CSPAGEPROTECTOR_STAT_BLOCKED',    'href' => $logsUrl(['event' => 'blocked', 'since' => '24h']),    'tone' => ($this->stats['blocked'] ?? 0) > 0 ? 'text-danger' : ''],
    ['value' => $this->stats['unique_ips'] ?? 0, 'label' => 'COM_CSPAGEPROTECTOR_STAT_UNIQUE_IPS', 'href' => $logsUrl(['event' => 'challenged', 'since' => '24h']), 'tone' => ''],
    ['value' => $this->stats['bot'] ?? 0,        'label' => 'COM_CSPAGEPROTECTOR_STAT_BOTS',       'href' => $logsUrl(['event' => 'bot', 'since' => '24h']),        'tone' => ''],
    ['value' => $this->stats['error'] ?? 0,      'label' => 'COM_CSPAGEPROTECTOR_STAT_ERRORS',     'href' => $logsUrl(['event' => 'error', 'since' => '24h']),      'tone' => ($this->stats['error'] ?? 0) > 0 ? 'text-danger' : ''],
];

$supportEmail = SupportHelper::getEmail();
$supportUrl   = SupportHelper::getUrl();
?>
<div class="cspp-dashboard">

    <div class="alert alert-info">
        <h2 class="alert-heading h4">
            <span class="icon-shield-alt" aria-hidden="true"></span>
            <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_INTRO_HEADING')); ?>
        </h2>
        <p class="mb-0"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_INTRO_BODY')); ?></p>
    </div>

    <div class="card mb-4">
        <h2 class="card-header h5"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_HEALTH')); ?></h2>
        <ul class="list-group list-group-flush">
            <?php foreach ($this->checks as $check) : ?>
                <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
                    <span class="<?php echo $levelIcon[$check['level']] ?? 'icon-info-circle'; ?>" aria-hidden="true"></span>
                    <span class="visually-hidden"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CHECK_LEVEL_' . strtoupper($check['level']))); ?>:</span>
                    <span class="flex-grow-1"><?php echo $this->escape($check['text']); ?></span>
                    <?php if ($check['link'] !== '') : ?>
                        <a class="btn btn-sm btn-secondary" href="<?php echo $this->escape($check['link']); ?>">
                            <?php echo $this->escape($check['linkText']); ?>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <h2 class="h5 mb-2"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_LAST_24H')); ?></h2>
    <div class="row g-3 mb-2">
        <?php foreach ($cards as $card) : ?>
            <div class="col-6 col-md-4 col-xl">
                <a href="<?php echo $this->escape($card['href']); ?>" class="card text-decoration-none h-100 cspp-stat-card">
                    <div class="card-body text-center py-3">
                        <div class="display-6 <?php echo $card['tone']; ?>"><?php echo (int) $card['value']; ?></div>
                        <div class="small text-body-secondary"><?php echo $this->escape(Text::_($card['label'])); ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="small text-body-secondary mb-4">
        <a href="<?php echo $this->escape($logsUrl()); ?>">
            <?php echo $this->escape(Text::sprintf('COM_CSPAGEPROTECTOR_DASHBOARD_ALL_TIME', (int) ($this->stats['total'] ?? 0))); ?>
        </a>
    </p>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0">
                        <?php echo $this->escape(Text::_($this->protectionMode === 'all_except' ? 'COM_CSPAGEPROTECTOR_DASHBOARD_EXCLUDED_PAGES' : 'COM_CSPAGEPROTECTOR_DASHBOARD_PROTECTED_PAGES')); ?>
                    </h2>
                    <a class="btn btn-sm btn-secondary" href="<?php echo $this->escape($this->optionsUrl); ?>">
                        <span class="icon-options" aria-hidden="true"></span>
                        <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CHECK_CHOOSE_PAGES')); ?>
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (!$this->protectedItems) : ?>
                        <p class="m-3 text-body-secondary">
                            <?php echo $this->escape(Text::_($this->protectionMode === 'all_except' ? 'COM_CSPAGEPROTECTOR_DASHBOARD_NO_EXCLUDED_PAGES' : 'COM_CSPAGEPROTECTOR_DASHBOARD_NO_PAGES')); ?>
                        </p>
                    <?php else : ?>
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_PROTECTED_PAGES')); ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_PAGE')); ?></th>
                                    <th scope="col"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_MENU')); ?></th>
                                    <th scope="col" class="text-end"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_ACTIONS')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->protectedItems as $item) : ?>
                                    <tr>
                                        <td>
                                            <?php echo $this->escape($item->title); ?>
                                            <?php if ((int) $item->published === 0) : ?>
                                                <span class="badge bg-secondary"><?php echo $this->escape(Text::_('JUNPUBLISHED')); ?></span>
                                            <?php elseif ((int) $item->published === -2) : ?>
                                                <span class="badge bg-secondary"><?php echo $this->escape(Text::_('JTRASHED')); ?></span>
                                            <?php elseif ((int) $item->published === -99) : ?>
                                                <span class="badge bg-warning text-dark"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_MISSING')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $this->escape($item->menu_title); ?></td>
                                        <td class="text-end text-nowrap">
                                            <?php if ($item->site_url !== '') : ?>
                                                <a class="btn btn-sm btn-secondary" href="<?php echo $this->escape($item->site_url); ?>" target="_blank" rel="noopener noreferrer">
                                                    <span class="icon-eye" aria-hidden="true"></span>
                                                    <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_VIEW_PAGE')); ?>
                                                    <span class="visually-hidden"><?php echo $this->escape(Text::_('JBROWSERTARGET_NEW')); ?></span>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($item->edit_url !== '') : ?>
                                                <a class="btn btn-sm btn-secondary" href="<?php echo $this->escape($item->edit_url); ?>">
                                                    <span class="icon-edit" aria-hidden="true"></span>
                                                    <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_EDIT_MENU_ITEM')); ?>
                                                </a>
                                            <?php endif; ?>
                                            <a class="btn btn-sm btn-secondary" href="<?php echo $this->escape($logsUrl(['menu_item' => (int) $item->id])); ?>">
                                                <span class="icon-list" aria-hidden="true"></span>
                                                <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_SUBMENU_LOGS')); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="card-header d-flex justify-content-between align-items-center border-top">
                    <h2 class="h5 mb-0"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_PROTECTED_MODULES')); ?></h2>
                    <a class="btn btn-sm btn-secondary" href="<?php echo $this->escape($this->modulesOptionsUrl); ?>">
                        <span class="icon-options" aria-hidden="true"></span>
                        <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CHOOSE_MODULES')); ?>
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (!$this->protectedModules) : ?>
                        <p class="m-3 text-body-secondary"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_NO_MODULES')); ?></p>
                    <?php else : ?>
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_PROTECTED_MODULES')); ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_MODULE')); ?></th>
                                    <th scope="col"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_POSITION')); ?></th>
                                    <th scope="col" class="text-end"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_ACTIONS')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->protectedModules as $module) : ?>
                                    <tr>
                                        <td>
                                            <?php echo $this->escape($module->title); ?>
                                            <small class="text-body-secondary">(<?php echo $this->escape($module->module); ?>)</small>
                                            <?php if ((int) $module->published === 0) : ?>
                                                <span class="badge bg-secondary"><?php echo $this->escape(Text::_('JUNPUBLISHED')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo $this->escape($module->position !== '' ? $module->position : Text::_('COM_CSPAGEPROTECTOR_NO_POSITION')); ?>
                                            <?php if ($module->by_position) : ?>
                                                <span class="badge bg-secondary"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_PROTECTED_BY_POSITION')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <a class="btn btn-sm btn-secondary" href="<?php echo $this->escape($module->edit_url); ?>">
                                                <span class="icon-edit" aria-hidden="true"></span>
                                                <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_EDIT_MENU_ITEM')); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">
                    <h2 class="h5 mb-0"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_TOP_IPS')); ?></h2>
                    <p class="small text-body-secondary mb-0"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_TOP_IPS_HINT')); ?></p>
                </div>
                <div class="card-body p-0">
                    <?php if (!$this->topIps) : ?>
                        <p class="m-3 text-body-secondary"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_NO_EVENTS')); ?></p>
                    <?php else : ?>
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_TOP_IPS')); ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_IP')); ?></th>
                                    <th scope="col" class="text-end"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_EVENT_CHALLENGED')); ?></th>
                                    <th scope="col" class="text-end"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_EVENT_PASSED')); ?></th>
                                    <th scope="col" class="text-end"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_EVENT_FAILED')); ?></th>
                                    <th scope="col"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_LAST_SEEN')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->topIps as $row) : ?>
                                    <?php $suspicious = (int) $row->challenged >= 5 && (int) $row->passed === 0; ?>
                                    <tr>
                                        <td class="text-nowrap">
                                            <a href="<?php echo $this->escape($logsUrl(['ip' => (string) $row->ip, 'since' => '7d'])); ?>"><code><?php echo $this->escape($row->ip); ?></code></a>
                                            <?php $copyIp = DisplayHelper::blockTarget((string) $row->ip, $ipsShortened); ?>
                                            <?php echo DisplayHelper::copyButton($copyIp, Text::sprintf('COM_CSPAGEPROTECTOR_COPY_IP', $copyIp)); ?>
                                            <?php if ($suspicious) : ?>
                                                <span class="badge bg-warning text-dark"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_LIKELY_SCRAPER')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?php echo (int) $row->challenged; ?></td>
                                        <td class="text-end"><?php echo (int) $row->passed; ?></td>
                                        <td class="text-end"><?php echo (int) $row->failed; ?></td>
                                        <td class="text-nowrap small"><?php echo $this->escape(HTMLHelper::_('date', $row->last_seen, Text::_('DATE_FORMAT_LC5'))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_RECENT')); ?></h2>
                    <a class="btn btn-sm btn-secondary" href="<?php echo $this->escape($logsUrl()); ?>">
                        <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_VIEW_ALL')); ?>
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (!$this->recent) : ?>
                        <p class="m-3 text-body-secondary"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_NO_EVENTS')); ?></p>
                    <?php else : ?>
                        <table class="table table-sm mb-0">
                            <caption class="visually-hidden"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_DASHBOARD_RECENT')); ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_DATE')); ?></th>
                                    <th scope="col"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_EVENT')); ?></th>
                                    <th scope="col"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_IP')); ?></th>
                                    <th scope="col"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_PAGE')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($this->recent as $row) : ?>
                                    <tr>
                                        <td class="text-nowrap small"><?php echo $this->escape(HTMLHelper::_('date', $row->created, Text::_('DATE_FORMAT_LC5'))); ?></td>
                                        <td><?php echo DisplayHelper::eventBadge((string) $row->event); ?></td>
                                        <td><code><?php echo $this->escape($row->ip); ?></code></td>
                                        <td><?php echo $this->escape($row->menu_title ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-3">
                    <img src="<?php echo $this->escape(Uri::root() . 'media/com_cspageprotector/images/logo.svg'); ?>" alt="" class="cspp-logo" width="40" height="40">
                    <h2 class="h5 mb-0"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_SUPPORT_HEADING')); ?></h2>
                </div>
                <div class="card-body">
                    <h3 class="h6"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_SUPPORT_COMMUNITY_HEADING')); ?></h3>
                    <p><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_SUPPORT_COMMUNITY_BODY')); ?></p>
                    <ul class="list-unstyled mb-0">
                        <?php if ($supportEmail !== '') : ?>
                            <li class="mb-1">
                                <span class="icon-envelope" aria-hidden="true"></span>
                                <a href="mailto:<?php echo $this->escape($supportEmail); ?>"><?php echo $this->escape($supportEmail); ?></a>
                                <?php echo DisplayHelper::copyButton($supportEmail, Text::_('COM_CSPAGEPROTECTOR_COPY_EMAIL')); ?>
                            </li>
                        <?php endif; ?>
                        <?php if ($supportUrl !== '') : ?>
                            <li class="mb-1">
                                <span class="icon-question-circle" aria-hidden="true"></span>
                                <a href="<?php echo $this->escape($supportUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_SUPPORT_FORM')); ?></a>
                            </li>
                        <?php endif; ?>
                        <li class="mb-1">
                            <span class="icon-book" aria-hidden="true"></span>
                            <a href="<?php echo $this->escape(SupportHelper::REPO_URL . '#readme'); ?>" target="_blank" rel="noopener noreferrer"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_SUPPORT_DOCS')); ?></a>
                        </li>
                        <li class="mb-1">
                            <span class="icon-list" aria-hidden="true"></span>
                            <a href="<?php echo $this->escape(SupportHelper::REPO_URL . '/blob/main/CHANGELOG.md'); ?>" target="_blank" rel="noopener noreferrer"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_SUPPORT_CHANGELOG')); ?></a>
                        </li>
                        <li>
                            <span class="icon-bug" aria-hidden="true"></span>
                            <a href="<?php echo $this->escape(SupportHelper::REPO_URL . '/issues'); ?>" target="_blank" rel="noopener noreferrer"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_SUPPORT_ISSUES')); ?></a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="visually-hidden" aria-live="polite" id="cspp-live"></div>
</div>

<form id="csppClearMyPassForm" method="post" action="<?php echo Route::_('index.php?option=com_cspageprotector&task=dashboard.clearmypass'); ?>" hidden>
    <?php echo HTMLHelper::_('form.token'); ?>
</form>

<?php if ($this->canAdmin) : ?>
    <div class="modal fade" id="csppResetPassesModal" tabindex="-1" aria-labelledby="csppResetPassesTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog">
            <form class="modal-content" method="post" action="<?php echo Route::_('index.php?option=com_cspageprotector&task=dashboard.resetpasses'); ?>">
                <div class="modal-header">
                    <h3 class="modal-title h5" id="csppResetPassesTitle"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_RESET_PASSES')); ?></h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo $this->escape(Text::_('JCLOSE')); ?>"></button>
                </div>
                <div class="modal-body">
                    <p><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_RESET_PASSES_BODY')); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $this->escape(Text::_('JCANCEL')); ?></button>
                    <button type="submit" class="btn btn-danger"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_RESET_PASSES_CONFIRM')); ?></button>
                </div>
                <?php echo HTMLHelper::_('form.token'); ?>
            </form>
        </div>
    </div>
<?php endif; ?>
