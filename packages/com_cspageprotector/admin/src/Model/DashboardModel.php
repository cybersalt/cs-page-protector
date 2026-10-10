<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Model;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\CaptchaHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\LogHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Dashboard data: health checks, 24-hour stats, protected pages, top IPs,
 * recent events.
 *
 * @since  0.1.0
 */
final class DashboardModel extends BaseDatabaseModel
{
    /**
     * Health checks, worst first. Each: level (danger|warning|info|success),
     * text, optional link + linkText.
     *
     * @return  array<int, array{level: string, text: string, link: string, linkText: string}>
     *
     * @since   0.1.0
     */
    public function getChecks(): array
    {
        $params = ProtectionHelper::getParams();
        $checks = [];

        // 1. The gatekeeper plugin.
        if (PluginHelper::isEnabled('system', 'cspageprotector')) {
            $checks[] = $this->check('success', Text::_('COM_CSPAGEPROTECTOR_CHECK_PLUGIN_OK'));
        } else {
            $checks[] = $this->check(
                'danger',
                Text::_('COM_CSPAGEPROTECTOR_CHECK_PLUGIN_OFF'),
                'index.php?option=com_plugins&view=plugins&filter[folder]=system&filter[search]=cspageprotector',
                Text::_('COM_CSPAGEPROTECTOR_CHECK_PLUGIN_OFF_LINK')
            );
        }

        // 2. The captcha.
        $captcha     = CaptchaHelper::getConfiguredPlugin($params);
        $extensionId = CaptchaHelper::getExtensionId($captcha);
        $problem     = CaptchaHelper::getProblem($captcha);
        $impact      = $problem !== CaptchaHelper::PROBLEM_NONE ? $this->captchaImpact($params) : '';

        if ($problem === CaptchaHelper::PROBLEM_POW_MISSING) {
            $checks[] = $this->check('danger', trim(Text::sprintf('COM_CSPAGEPROTECTOR_CHECK_POW_MISSING', JVERSION) . ' ' . $impact), 'index.php?option=com_joomlaupdate', Text::_('COM_CSPAGEPROTECTOR_CHECK_POW_MISSING_LINK'));
        } elseif ($problem === CaptchaHelper::PROBLEM_MISSING) {
            $checks[] = $this->check('danger', trim(Text::sprintf('COM_CSPAGEPROTECTOR_CHECK_CAPTCHA_MISSING', $captcha) . ' ' . $impact), $this->optionsUrl('challenge'), Text::_('COM_CSPAGEPROTECTOR_OPEN_OPTIONS'));
        } elseif ($problem === CaptchaHelper::PROBLEM_DISABLED) {
            $checks[] = $this->check(
                'danger',
                trim(Text::sprintf('COM_CSPAGEPROTECTOR_CHECK_CAPTCHA_DISABLED', $captcha) . ' ' . $impact),
                'index.php?option=com_plugins&task=plugin.edit&extension_id=' . $extensionId,
                Text::_('COM_CSPAGEPROTECTOR_CHECK_CAPTCHA_DISABLED_LINK')
            );
        } else {
            $checks[] = $this->check(
                'success',
                Text::sprintf('COM_CSPAGEPROTECTOR_CHECK_CAPTCHA_OK', $captcha),
                'index.php?option=com_plugins&task=plugin.edit&extension_id=' . $extensionId,
                Text::_('COM_CSPAGEPROTECTOR_CHECK_CAPTCHA_SETTINGS_LINK')
            );
        }

        // 3. What's protected.
        $count = \count(ProtectionHelper::getMenuItemIds($params));

        if ((string) $params->get('protection_mode', 'selected') === ProtectionHelper::MODE_ALL_EXCEPT) {
            $checks[] = $this->check('info', Text::plural('COM_CSPAGEPROTECTOR_CHECK_MODE_ALL_EXCEPT_N', $count), $this->optionsUrl('protection'), Text::_('COM_CSPAGEPROTECTOR_CHECK_CHOOSE_PAGES'));
        } elseif ($count === 0) {
            // Only a warning when nothing at all is protected; protecting just
            // modules is a deliberate setup, so then it is only a note.
            $checks[] = ProtectionHelper::hasModuleProtection($params)
                ? $this->check('info', Text::_('COM_CSPAGEPROTECTOR_CHECK_NO_PAGES_MODULES_ONLY'), $this->optionsUrl('protection'), Text::_('COM_CSPAGEPROTECTOR_CHECK_CHOOSE_PAGES'))
                : $this->check('warning', Text::_('COM_CSPAGEPROTECTOR_CHECK_NO_PAGES'), $this->optionsUrl('protection'), Text::_('COM_CSPAGEPROTECTOR_CHECK_CHOOSE_PAGES'));
        } else {
            $checks[] = $this->check('success', Text::plural('COM_CSPAGEPROTECTOR_CHECK_PAGES_N', $count), $this->optionsUrl('protection'), Text::_('COM_CSPAGEPROTECTOR_CHECK_CHOOSE_PAGES'));
        }

        // 3b. Protected modules (only mentioned once some are configured).
        if (ProtectionHelper::hasModuleProtection($params)) {
            $checks[] = $this->check(
                'success',
                Text::plural('COM_CSPAGEPROTECTOR_CHECK_MODULES_N', \count($this->getProtectedModules())),
                $this->optionsUrl('modules'),
                Text::_('COM_CSPAGEPROTECTOR_CHOOSE_MODULES')
            );
        }

        // 4. Behind Cloudflare but reading REMOTE_ADDR: every visitor looks like a Cloudflare IP.
        $server = Factory::getApplication()->getInput()->server;

        if ((string) $params->get('ip_source', 'remote_addr') === 'remote_addr' && $server->getString('HTTP_CF_CONNECTING_IP', '') !== '') {
            $checks[] = $this->check('warning', Text::_('COM_CSPAGEPROTECTOR_CHECK_CLOUDFLARE'), $this->optionsUrl('exemptions'), Text::_('COM_CSPAGEPROTECTOR_OPEN_OPTIONS'));
        }

        // 5. Logging off.
        if ((int) $params->get('logging_enabled', 1) !== 1) {
            $checks[] = $this->check('info', Text::_('COM_CSPAGEPROTECTOR_CHECK_LOGGING_OFF'), $this->optionsUrl('logging'), Text::_('COM_CSPAGEPROTECTOR_OPEN_OPTIONS'));
        }

        // 6. Page cache: handled, but worth saying so admins don't worry.
        if (PluginHelper::isEnabled('system', 'cache')) {
            $checks[] = $this->check('info', Text::_('COM_CSPAGEPROTECTOR_CHECK_PAGECACHE'));
        }

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2, 'success' => 3];
        usort($checks, static fn (array $a, array $b): int => $rank[$a['level']] <=> $rank[$b['level']]);

        return $checks;
    }

    /**
     * Last-24-hour counts per event, plus distinct challenged IPs and all-time total.
     *
     * @return  array<string, int>
     *
     * @since   0.1.0
     */
    public function getStats(): array
    {
        $db     = $this->getDatabase();
        $cutoff = Factory::getDate('-24 hours')->toSql();

        $select = ['COUNT(*) AS ' . $db->quoteName('total')];

        foreach (LogHelper::EVENTS as $event) {
            $select[] = 'SUM(CASE WHEN ' . $db->quoteName('event') . ' = ' . $db->quote($event)
                . ' AND ' . $db->quoteName('created') . ' >= ' . $db->quote($cutoff) . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName($event);
        }

        $select[] = 'COUNT(DISTINCT CASE WHEN ' . $db->quoteName('event') . ' = ' . $db->quote(LogHelper::EVENT_CHALLENGED)
            . ' AND ' . $db->quoteName('created') . ' >= ' . $db->quote($cutoff) . ' THEN ' . $db->quoteName('ip') . ' END) AS ' . $db->quoteName('unique_ips');

        try {
            $row = $db->setQuery($db->createQuery()->select($select)->from($db->quoteName('#__cspageprotector_log')))->loadAssoc() ?: [];
        } catch (\Throwable $e) {
            $row = [];
        }

        $stats = ['total' => (int) ($row['total'] ?? 0), 'unique_ips' => (int) ($row['unique_ips'] ?? 0)];

        foreach (LogHelper::EVENTS as $event) {
            $stats[$event] = (int) ($row[$event] ?? 0);
        }

        return $stats;
    }

    /**
     * The configured menu items with titles, menus and links.
     *
     * @return  object[]
     *
     * @since   0.1.0
     */
    public function getProtectedItems(): array
    {
        $ids = ProtectionHelper::getMenuItemIds(ProtectionHelper::getParams());

        if ($ids === []) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select([
                $db->quoteName('m.id'),
                $db->quoteName('m.title'),
                $db->quoteName('m.published'),
                $db->quoteName('m.link'),
                $db->quoteName('t.title', 'menu_title'),
            ])
            ->from($db->quoteName('#__menu', 'm'))
            ->join('LEFT', $db->quoteName('#__menu_types', 't'), $db->quoteName('t.menutype') . ' = ' . $db->quoteName('m.menutype'))
            ->whereIn($db->quoteName('m.id'), $ids, ParameterType::INTEGER)
            ->order([$db->quoteName('t.title') . ' ASC', $db->quoteName('m.lft') . ' ASC']);

        $items = $db->setQuery($query)->loadObjectList() ?: [];

        foreach ($items as $item) {
            $item->edit_url = 'index.php?option=com_menus&task=item.edit&id=' . (int) $item->id;

            try {
                $item->site_url = Route::link('site', 'index.php?Itemid=' . (int) $item->id, false, Route::TLS_IGNORE, true);
            } catch (\Throwable $e) {
                $item->site_url = Uri::root() . 'index.php?Itemid=' . (int) $item->id;
            }
        }

        // Configured ids whose menu item has since been deleted.
        $found = array_map(static fn ($i): int => (int) $i->id, $items);

        foreach (array_diff($ids, $found) as $missing) {
            $items[] = (object) [
                'id'         => (int) $missing,
                'title'      => Text::sprintf('COM_CSPAGEPROTECTOR_DELETED_MENU_ITEM', (int) $missing),
                'published'  => -99,
                'link'       => '',
                'menu_title' => '',
                'edit_url'   => '',
                'site_url'   => '',
            ];
        }

        return $items;
    }

    /**
     * Front-end modules protected by id or by position (trashed ones left out).
     *
     * @return  object[]  id, title, position, module, published, by_position
     *
     * @since   0.1.0
     */
    public function getProtectedModules(): array
    {
        $params    = ProtectionHelper::getParams();
        $ids       = ProtectionHelper::getProtectedModuleIds($params);
        $positions = ProtectionHelper::getProtectedPositions($params);

        if ($ids === [] && $positions === []) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select($db->quoteName(['id', 'title', 'position', 'module', 'published']))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' IN (0, 1)')
            ->order([$db->quoteName('position') . ' ASC', $db->quoteName('ordering') . ' ASC']);

        $or = [];

        if ($ids !== []) {
            $or[] = $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
        }

        if ($positions !== []) {
            $or[] = $db->quoteName('position') . ' IN (' . implode(',', array_map([$db, 'quote'], $positions)) . ')';
        }

        $query->where('(' . implode(' OR ', $or) . ')');

        try {
            $rows = $db->setQuery($query)->loadObjectList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($rows as $row) {
            $row->by_position = !\in_array((int) $row->id, $ids, true);
            $row->edit_url    = 'index.php?option=com_modules&task=module.edit&id=' . (int) $row->id;
        }

        return $rows;
    }

    /**
     * IPs challenged most in the last 7 days. A row with many challenges and
     * no passes is almost always a scraper.
     *
     * @param   int  $limit  Rows.
     *
     * @return  object[]
     *
     * @since   0.1.0
     */
    public function getTopIps(int $limit = 10): array
    {
        $db     = $this->getDatabase();
        $cutoff = Factory::getDate('-7 days')->toSql();

        $query = $db->createQuery()
            ->select([
                $db->quoteName('ip'),
                'SUM(CASE WHEN ' . $db->quoteName('event') . ' = ' . $db->quote(LogHelper::EVENT_CHALLENGED) . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('challenged'),
                'SUM(CASE WHEN ' . $db->quoteName('event') . ' = ' . $db->quote(LogHelper::EVENT_PASSED) . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('passed'),
                'SUM(CASE WHEN ' . $db->quoteName('event') . ' = ' . $db->quote(LogHelper::EVENT_FAILED) . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('failed'),
                'MAX(' . $db->quoteName('created') . ') AS ' . $db->quoteName('last_seen'),
            ])
            ->from($db->quoteName('#__cspageprotector_log'))
            ->where($db->quoteName('created') . ' >= :cutoff')
            ->where($db->quoteName('ip') . ' <> ' . $db->quote(''))
            ->group($db->quoteName('ip'))
            ->order($db->quoteName('challenged') . ' DESC')
            ->bind(':cutoff', $cutoff);

        try {
            return $db->setQuery($query, 0, $limit)->loadObjectList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Most recent events.
     *
     * @param   int  $limit  Rows.
     *
     * @return  object[]
     *
     * @since   0.1.0
     */
    public function getRecent(int $limit = 10): array
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select($db->quoteName(['l.id', 'l.created', 'l.event', 'l.ip', 'l.menu_item_id']))
            ->select($db->quoteName('m.title', 'menu_title'))
            ->from($db->quoteName('#__cspageprotector_log', 'l'))
            ->join('LEFT', $db->quoteName('#__menu', 'm'), $db->quoteName('m.id') . ' = ' . $db->quoteName('l.menu_item_id'))
            ->order($db->quoteName('l.id') . ' DESC');

        try {
            return $db->setQuery($query, 0, $limit)->loadObjectList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Component Options URL, optionally opening a specific tab.
     *
     * @param   string  $tab  Fieldset name.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    public function optionsUrl(string $tab = ''): string
    {
        $return = urlencode(base64_encode('index.php?option=com_cspageprotector&view=dashboard'));

        return 'index.php?option=com_config&view=component&component=com_cspageprotector&return=' . $return
            . ($tab !== '' ? '#' . $tab : '');
    }

    /**
     * What a captcha that can't run means for visitors right now, given what
     * is protected and the "If the captcha can't run" setting. Empty when
     * nothing is protected yet.
     *
     * @param   Registry  $params  Component params.
     *
     * @return  string
     *
     * @since   0.2.0
     */
    private function captchaImpact(Registry $params): string
    {
        $parts = [];

        if (ProtectionHelper::hasPageProtection($params)) {
            $parts[] = (string) $params->get('captcha_unavailable', 'allow') === 'allow'
                ? Text::_('COM_CSPAGEPROTECTOR_CHECK_IMPACT_PAGES_OPEN')
                : Text::_('COM_CSPAGEPROTECTOR_CHECK_IMPACT_PAGES_BLOCKED');
        }

        if (ProtectionHelper::hasModuleProtection($params)) {
            $parts[] = Text::_('COM_CSPAGEPROTECTOR_CHECK_IMPACT_MODULES_LOCKED');
        }

        return implode(' ', $parts);
    }

    /**
     * @param   string  $level     danger|warning|info|success
     * @param   string  $text      Message (plain text).
     * @param   string  $link      Optional admin URL.
     * @param   string  $linkText  Optional link label.
     *
     * @return  array{level: string, text: string, link: string, linkText: string}
     *
     * @since   0.1.0
     */
    private function check(string $level, string $text, string $link = '', string $linkText = ''): array
    {
        return ['level' => $level, 'text' => $text, 'link' => $link, 'linkText' => $linkText];
    }
}
