<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Model;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\LogHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

/**
 * Event log list.
 *
 * @since  0.1.0
 */
final class LogsModel extends ListModel
{
    /**
     * Columns the list may be sorted by.
     *
     * @var    string[]
     * @since  0.1.0
     */
    private const ORDERABLE = ['a.id', 'a.created', 'a.event', 'a.ip', 'a.url', 'a.user_agent', 'm.title'];

    /**
     * @param   array  $config  Model config.
     *
     * @since   0.1.0
     */
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'created', 'a.created',
                'event', 'a.event',
                'ip', 'a.ip',
                'url', 'a.url',
                'user_agent', 'a.user_agent',
                'menu_item_id', 'a.menu_item_id',
                'menu_title', 'm.title',
                // Filter-only keys
                'menu_item', 'since', 'ua',
            ];
        }

        parent::__construct($config);
    }

    /**
     * @param   string  $ordering   Default ordering column.
     * @param   string  $direction  Default direction.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    protected function populateState($ordering = 'a.created', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * @param   string  $id  Store id prefix.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    protected function getStoreId($id = '')
    {
        foreach (['search', 'event', 'menu_item', 'ip', 'url', 'ua', 'since'] as $key) {
            $id .= ':' . $this->getState('filter.' . $key);
        }

        return parent::getStoreId($id);
    }

    /**
     * @return  QueryInterface
     *
     * @since   0.1.0
     */
    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select($db->quoteName('a') . '.*')
            ->select($db->quoteName('m.title', 'menu_title'))
            ->from($db->quoteName('#__cspageprotector_log', 'a'))
            ->join('LEFT', $db->quoteName('#__menu', 'm'), $db->quoteName('m.id') . ' = ' . $db->quoteName('a.menu_item_id'));

        $search = trim((string) $this->getState('filter.search'));

        if ($search !== '') {
            if (stripos($search, 'id:') === 0) {
                $searchId = (int) substr($search, 3);
                $query->where($db->quoteName('a.id') . ' = :searchid')
                    ->bind(':searchid', $searchId, ParameterType::INTEGER);
            } else {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
                $query->where('(' . implode(' OR ', [
                    $db->quoteName('a.ip') . ' LIKE :s1',
                    $db->quoteName('a.url') . ' LIKE :s2',
                    $db->quoteName('a.user_agent') . ' LIKE :s3',
                    $db->quoteName('a.details') . ' LIKE :s4',
                    $db->quoteName('m.title') . ' LIKE :s5',
                ]) . ')')
                    ->bind([':s1', ':s2', ':s3', ':s4', ':s5'], $like);
            }
        }

        $event = (string) $this->getState('filter.event');

        if (\in_array($event, LogHelper::EVENTS, true)) {
            $query->where($db->quoteName('a.event') . ' = :event')
                ->bind(':event', $event);
        }

        $menuItem = (string) $this->getState('filter.menu_item');

        if ($menuItem !== '' && ctype_digit($menuItem)) {
            $menuItemId = (int) $menuItem;
            $query->where($db->quoteName('a.menu_item_id') . ' = :menuitem')
                ->bind(':menuitem', $menuItemId, ParameterType::INTEGER);
        }

        // bind() keeps a reference, so each pattern gets its own array slot
        // (Joomla-Brain gotcha #18).
        $patterns = [];

        foreach (['ip' => 'a.ip', 'url' => 'a.url', 'ua' => 'a.user_agent'] as $key => $column) {
            $value = trim((string) $this->getState('filter.' . $key));

            if ($value === '') {
                continue;
            }

            // IPs match from the start ("203.0.113." finds a whole /24); the
            // other text columns match anywhere.
            $escaped        = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
            $patterns[$key] = $key === 'ip' ? $escaped . '%' : '%' . $escaped . '%';

            $query->where($db->quoteName($column) . ' LIKE :f_' . $key)
                ->bind(':f_' . $key, $patterns[$key]);
        }

        $sinceCutoff = self::sinceCutoff((string) $this->getState('filter.since'));

        if ($sinceCutoff !== null) {
            $query->where($db->quoteName('a.created') . ' >= :since')
                ->bind(':since', $sinceCutoff);
        }

        $ordering  = (string) $this->getState('list.ordering', 'a.created');
        $direction = strtoupper((string) $this->getState('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        // Explicit allowlist: filter_fields also holds filter-only keys
        // (since, ua, menu_item) that are not real columns.
        if (!\in_array($ordering, self::ORDERABLE, true)) {
            $ordering = 'a.created';
        }

        $query->order($db->quoteName($ordering) . ' ' . $direction);

        return $query;
    }

    /**
     * Event counts for the stats bar. Not filtered — they show the whole table
     * so "3 failures overall" is visible even while looking at the last hour.
     *
     * @return  array<string, int>
     *
     * @since   0.1.0
     */
    public function getStats(): array
    {
        $db     = $this->getDatabase();
        $cutoff = Factory::getDate('-24 hours')->toSql();

        $select = [
            'COUNT(*) AS ' . $db->quoteName('total'),
            'SUM(CASE WHEN ' . $db->quoteName('created') . ' >= ' . $db->quote($cutoff) . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('last24h'),
        ];

        foreach (LogHelper::EVENTS as $event) {
            $select[] = 'SUM(CASE WHEN ' . $db->quoteName('event') . ' = ' . $db->quote($event) . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName($event);
        }

        $row = $db->setQuery(
            $db->createQuery()->select($select)->from($db->quoteName('#__cspageprotector_log'))
        )->loadAssoc() ?: [];

        $stats = ['total' => (int) ($row['total'] ?? 0), 'last24h' => (int) ($row['last24h'] ?? 0)];

        foreach (LogHelper::EVENTS as $event) {
            $stats[$event] = (int) ($row[$event] ?? 0);
        }

        return $stats;
    }

    /**
     * CSV of the currently filtered rows (RFC 4180, formula-injection safe).
     *
     * @return  string
     *
     * @since   0.1.0
     */
    public function getCsv(): string
    {
        $headers = ['id', 'created_utc', 'event', 'ip', 'menu_item_id', 'menu_item', 'url', 'user_agent', 'captcha', 'details'];
        $rows    = [self::csvRow($headers)];

        foreach ($this->getItems() ?: [] as $item) {
            $rows[] = self::csvRow([
                (string) $item->id,
                (string) $item->created,
                (string) $item->event,
                (string) $item->ip,
                (string) $item->menu_item_id,
                (string) ($item->menu_title ?? ''),
                (string) $item->url,
                (string) $item->user_agent,
                (string) $item->captcha,
                (string) $item->details,
            ]);
        }

        return implode("\r\n", $rows) . "\r\n";
    }

    /**
     * Human-readable dump of the currently filtered rows, for support tickets.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    public function getTextDump(): string
    {
        $items = $this->getItems() ?: [];
        $stats = $this->getStats();

        $out  = "Cybersalt Page Protector - Event Log\n";
        $out .= 'Generated (UTC): ' . Factory::getDate()->toSql() . "\n";
        $out .= \sprintf(
            "All-time: %d events (last 24h: %d) | challenged %d | passed %d | failed %d | blocked %d | bot %d | error %d\n",
            $stats['total'],
            $stats['last24h'],
            $stats['challenged'],
            $stats['passed'],
            $stats['failed'],
            $stats['blocked'],
            $stats['bot'],
            $stats['error']
        );
        $out .= 'Rows in this dump: ' . \count($items) . "\n";
        $out .= str_repeat('=', 78) . "\n\n";

        foreach ($items as $item) {
            $out .= \sprintf("#%d  [%s]  %s UTC\n", (int) $item->id, strtoupper((string) $item->event), (string) $item->created);
            $out .= 'IP:       ' . (string) $item->ip . "\n";
            $out .= 'Page:     ' . (string) ($item->menu_title ?? '-') . ' (#' . (int) $item->menu_item_id . ")\n";
            $out .= 'URL:      ' . (string) $item->url . "\n";
            $out .= 'Agent:    ' . (string) $item->user_agent . "\n";

            if ((string) $item->captcha !== '') {
                $out .= 'Captcha:  ' . (string) $item->captcha . "\n";
            }

            if ((string) $item->details !== '') {
                $out .= 'Details:  ' . (string) $item->details . "\n";
            }

            $out .= str_repeat('-', 78) . "\n";
        }

        return $out;
    }

    /**
     * Cut-off timestamp for the time-range filter.
     *
     * @param   string  $since  '1h', '24h', '7d', '30d' or ''.
     *
     * @return  string|null
     *
     * @since   0.1.0
     */
    public static function sinceCutoff(string $since): ?string
    {
        return match ($since) {
            '1h'    => Factory::getDate('-1 hour')->toSql(),
            '24h'   => Factory::getDate('-24 hours')->toSql(),
            '7d'    => Factory::getDate('-7 days')->toSql(),
            '30d'   => Factory::getDate('-30 days')->toSql(),
            default => null,
        };
    }

    /**
     * One CSV row. Cells that start with = + - @ are prefixed with a quote so
     * a scraper-supplied User-Agent can't become a spreadsheet formula.
     *
     * @param   string[]  $fields  Cell values.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    private static function csvRow(array $fields): string
    {
        return implode(',', array_map(
            static function (string $value): string {
                $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

                if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
                    $value = "'" . $value;
                }

                return '"' . str_replace('"', '""', $value) . '"';
            },
            $fields
        ));
    }
}
