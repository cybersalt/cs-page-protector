<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 *
 * @var \Cybersalt\Component\Cspageprotector\Administrator\View\Logs\HtmlView $this
 */

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\DisplayHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\LogHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

$wa = $this->getDocument()->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('com_cspageprotector');
$wa->useStyle('com_cspageprotector.admin')->useScript('com_cspageprotector.admin');
$wa->useScript('multiselect');

HTMLHelper::_('bootstrap.modal');
Text::script('COM_CSPAGEPROTECTOR_COPIED');

// With shortened IPs the copy button copies the range, ready for a firewall rule.
$ipsShortened = LogHelper::anonymizeEnabled(ProtectionHelper::getParams());

$listOrder = $this->escape((string) $this->state->get('list.ordering'));
$listDirn  = $this->escape((string) $this->state->get('list.direction'));
$logsBase  = 'index.php?option=com_cspageprotector&view=logs';
$logsUrl   = static fn (array $filters = []): string => Route::_(
    $logsBase . ($filters ? '&' . http_build_query(['filter' => $filters]) : ''),
    false
);

// Stats bar — every card is a filter link (Cybersalt "hotlinked stats" rule).
$cards = [
    ['key' => 'total',      'label' => 'COM_CSPAGEPROTECTOR_STAT_LOG_TOTAL',  'href' => $logsUrl(),                               'tone' => ''],
    ['key' => 'last24h',    'label' => 'COM_CSPAGEPROTECTOR_STAT_LOG_24H',    'href' => $logsUrl(['since' => '24h']),             'tone' => ''],
    ['key' => 'challenged', 'label' => 'COM_CSPAGEPROTECTOR_EVENT_CHALLENGED', 'href' => $logsUrl(['event' => 'challenged']),     'tone' => ''],
    ['key' => 'passed',     'label' => 'COM_CSPAGEPROTECTOR_EVENT_PASSED',     'href' => $logsUrl(['event' => 'passed']),         'tone' => 'text-success'],
    ['key' => 'failed',     'label' => 'COM_CSPAGEPROTECTOR_EVENT_FAILED',     'href' => $logsUrl(['event' => 'failed']),         'tone' => 'text-warning'],
    ['key' => 'blocked',    'label' => 'COM_CSPAGEPROTECTOR_EVENT_BLOCKED',    'href' => $logsUrl(['event' => 'blocked']),        'tone' => 'text-danger'],
    ['key' => 'bot',        'label' => 'COM_CSPAGEPROTECTOR_EVENT_BOT',        'href' => $logsUrl(['event' => 'bot']),            'tone' => ''],
    ['key' => 'error',      'label' => 'COM_CSPAGEPROTECTOR_EVENT_ERROR',      'href' => $logsUrl(['event' => 'error']),          'tone' => 'text-danger'],
];
?>
<div class="cspp-logs">

    <div class="row g-2 mb-3">
        <?php foreach ($cards as $card) : ?>
            <div class="col-6 col-md-3 col-xl">
                <a href="<?php echo $this->escape($card['href']); ?>" class="card text-decoration-none h-100 cspp-stat-card">
                    <div class="card-body text-center py-2">
                        <div class="h3 mb-0 <?php echo $card['tone']; ?>"><?php echo (int) ($this->stats[$card['key']] ?? 0); ?></div>
                        <div class="small text-body-secondary"><?php echo $this->escape(Text::_($card['label'])); ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <form action="<?php echo Route::_($logsBase); ?>" method="post" name="adminForm" id="adminForm">
        <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

        <?php if (empty($this->items)) : ?>
            <div class="alert alert-info">
                <span class="icon-info-circle" aria-hidden="true"></span>
                <span class="visually-hidden"><?php echo $this->escape(Text::_('INFO')); ?></span>
                <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_LOGS_EMPTY')); ?>
            </div>
        <?php else : ?>
            <table class="table" id="csppLogList">
                <caption class="visually-hidden">
                    <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_LOGS_CAPTION')); ?>,
                    <span id="orderedBy"><?php echo Text::_('JGLOBAL_SORTED_BY'); ?> </span>,
                    <span id="filteredBy"><?php echo Text::_('JGLOBAL_FILTERED_BY'); ?></span>
                </caption>
                <thead>
                    <tr>
                        <td class="cspp-col-check text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                        <th scope="col" class="cspp-col-date text-nowrap">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_CSPAGEPROTECTOR_HEADING_DATE', 'a.created', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="cspp-col-event">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_CSPAGEPROTECTOR_HEADING_EVENT', 'a.event', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="cspp-col-ip">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_CSPAGEPROTECTOR_HEADING_IP', 'a.ip', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="cspp-col-page">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_CSPAGEPROTECTOR_HEADING_PAGE', 'm.title', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_CSPAGEPROTECTOR_HEADING_URL', 'a.url', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="d-none d-lg-table-cell">
                            <?php echo HTMLHelper::_('searchtools.sort', 'COM_CSPAGEPROTECTOR_HEADING_UA', 'a.user_agent', $listDirn, $listOrder); ?>
                        </th>
                        <th scope="col" class="cspp-col-id text-end">
                            <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->items as $i => $item) : ?>
                        <tr class="row<?php echo $i % 2; ?>">
                            <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, (int) $item->id, false, 'cid', 'cb', (string) $item->ip); ?></td>
                            <td class="text-nowrap small"><?php echo $this->escape(HTMLHelper::_('date', $item->created, Text::_('DATE_FORMAT_LC6'))); ?></td>
                            <td><?php echo DisplayHelper::eventBadge((string) $item->event); ?></td>
                            <td class="text-nowrap">
                                <a href="<?php echo $this->escape($logsUrl(['ip' => (string) $item->ip])); ?>" title="<?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_FILTER_BY_IP')); ?>"><code><?php echo $this->escape($item->ip); ?></code></a>
                                <?php if ((string) $item->ip !== '') : ?>
                                    <?php $copyIp = DisplayHelper::blockTarget((string) $item->ip, $ipsShortened); ?>
                                    <?php echo DisplayHelper::copyButton($copyIp, Text::sprintf('COM_CSPAGEPROTECTOR_COPY_IP', $copyIp)); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $item->menu_item_id > 0) : ?>
                                    <a href="<?php echo $this->escape($logsUrl(['menu_item' => (int) $item->menu_item_id])); ?>">
                                        <?php echo $this->escape($item->menu_title ?? Text::sprintf('COM_CSPAGEPROTECTOR_DELETED_MENU_ITEM', (int) $item->menu_item_id)); ?>
                                    </a>
                                <?php else : ?>
                                    &ndash;
                                <?php endif; ?>
                            </td>
                            <td class="cspp-url-cell">
                                <button type="button" class="btn btn-link p-0 cspp-detail-toggle" aria-expanded="false" aria-controls="cspp-detail-<?php echo (int) $item->id; ?>" title="<?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_SHOW_DETAILS')); ?>">
                                    <span class="icon-chevron-down" aria-hidden="true"></span>
                                    <?php echo $this->escape($item->url); ?>
                                </button>
                            </td>
                            <td class="d-none d-lg-table-cell small cspp-ua-cell">
                                <a href="<?php echo $this->escape($logsUrl(['ua' => (string) $item->user_agent])); ?>" class="cspp-ua-link" title="<?php echo $this->escape($item->user_agent); ?>">
                                    <?php echo $this->escape($item->user_agent !== '' ? $item->user_agent : '-'); ?>
                                </a>
                            </td>
                            <td class="text-end"><?php echo (int) $item->id; ?></td>
                        </tr>
                        <?php // Full-width details row. Its class doesn't start with "row", so Joomla's multiselect ignores it. ?>
                        <tr class="cspp-detail-row" id="cspp-detail-<?php echo (int) $item->id; ?>" hidden>
                            <td></td>
                            <td colspan="7">
                                <dl class="row small mb-0 border rounded p-2">
                                    <dt class="col-sm-2"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_URL')); ?></dt>
                                    <dd class="col-sm-10 text-break"><?php echo $this->escape($item->url); ?></dd>
                                    <dt class="col-sm-2"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_UA')); ?></dt>
                                    <dd class="col-sm-10 text-break"><?php echo $this->escape($item->user_agent !== '' ? $item->user_agent : '-'); ?></dd>
                                    <?php if ((string) $item->captcha !== '') : ?>
                                        <dt class="col-sm-2"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_CAPTCHA')); ?></dt>
                                        <dd class="col-sm-10"><?php echo $this->escape($item->captcha); ?></dd>
                                    <?php endif; ?>
                                    <?php if ((string) $item->details !== '') : ?>
                                        <dt class="col-sm-2"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_HEADING_DETAILS')); ?></dt>
                                        <dd class="col-sm-10 text-break"><?php echo $this->escape($item->details); ?></dd>
                                    <?php endif; ?>
                                </dl>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php echo $this->pagination->getListFooter(); ?>
        <?php endif; ?>

        <input type="hidden" name="task" value="">
        <input type="hidden" name="boxchecked" value="0">
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>

    <div class="visually-hidden" aria-live="polite" id="cspp-live"></div>
</div>

<?php if ($this->canWrite) : ?>
    <div class="modal fade" id="csppClearAllModal" tabindex="-1" aria-labelledby="csppClearAllTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog">
            <form class="modal-content" method="post" action="<?php echo Route::_('index.php?option=com_cspageprotector&task=logs.purge'); ?>">
                <div class="modal-header">
                    <h3 class="modal-title h5" id="csppClearAllTitle"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CLEAR_ALL_TITLE')); ?></h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo $this->escape(Text::_('JCLOSE')); ?>"></button>
                </div>
                <div class="modal-body">
                    <p><?php echo $this->escape(Text::sprintf('COM_CSPAGEPROTECTOR_CLEAR_ALL_BODY', (int) ($this->stats['total'] ?? 0))); ?></p>
                    <p class="small text-body-secondary mb-0"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CLEAR_ALL_NOTE')); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $this->escape(Text::_('JCANCEL')); ?></button>
                    <button type="submit" class="btn btn-danger"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CLEAR_ALL_CONFIRM')); ?></button>
                </div>
                <?php echo HTMLHelper::_('form.token'); ?>
            </form>
        </div>
    </div>
<?php endif; ?>
