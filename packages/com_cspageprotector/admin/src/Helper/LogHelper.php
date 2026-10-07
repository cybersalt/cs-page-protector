<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Writes protection events to `#__cspageprotector_log`.
 *
 * Logging must never break a page render, so every write is wrapped and any
 * failure goes to Joomla's own error log instead.
 *
 * @since  0.1.0
 */
final class LogHelper
{
    public const EVENT_CHALLENGED = 'challenged';
    public const EVENT_PASSED     = 'passed';
    public const EVENT_FAILED     = 'failed';
    public const EVENT_BLOCKED    = 'blocked';
    public const EVENT_BOT        = 'bot';
    public const EVENT_ERROR      = 'error';

    /**
     * All event types, in display order.
     *
     * @var    string[]
     * @since  0.1.0
     */
    public const EVENTS = [
        self::EVENT_CHALLENGED,
        self::EVENT_PASSED,
        self::EVENT_FAILED,
        self::EVENT_BLOCKED,
        self::EVENT_BOT,
        self::EVENT_ERROR,
    ];

    /**
     * Record one event.
     *
     * @param   Registry  $params      Component params.
     * @param   string    $event       One of the EVENT_* constants.
     * @param   string    $ip          Client IP (anonymised here if configured).
     * @param   string    $userAgent   Client User-Agent.
     * @param   string    $url         Requested URL.
     * @param   int       $menuItemId  Active menu item id (0 if none).
     * @param   string    $captcha     Captcha plugin element in use.
     * @param   string    $details     Short free-text detail.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public static function log(
        Registry $params,
        string $event,
        string $ip,
        string $userAgent,
        string $url,
        int $menuItemId = 0,
        string $captcha = '',
        string $details = ''
    ): void {
        if ((int) $params->get('logging_enabled', 1) !== 1 || !\in_array($event, self::EVENTS, true)) {
            return;
        }

        if (self::anonymizeEnabled($params)) {
            $ip = IpHelper::anonymize($ip);
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            // A misconfigured captcha would otherwise write one error row per
            // visitor. One row per ten minutes is plenty to surface the problem.
            if ($event === self::EVENT_ERROR && self::recentlyLogged($db, $event, 600)) {
                return;
            }

            $created    = Factory::getDate()->toSql();
            $userAgent  = mb_substr($userAgent, 0, 512);
            $url        = mb_substr($url, 0, 2048);
            $captcha    = mb_substr($captcha, 0, 50);
            $details    = mb_substr($details, 0, 1024);

            $query = $db->createQuery()
                ->insert($db->quoteName('#__cspageprotector_log'))
                ->columns($db->quoteName(['created', 'event', 'ip', 'user_agent', 'url', 'menu_item_id', 'captcha', 'details']))
                ->values(':created, :event, :ip, :ua, :url, :itemid, :captcha, :details')
                ->bind(':created', $created)
                ->bind(':event', $event)
                ->bind(':ip', $ip)
                ->bind(':ua', $userAgent)
                ->bind(':url', $url)
                ->bind(':itemid', $menuItemId, ParameterType::INTEGER)
                ->bind(':captcha', $captcha)
                ->bind(':details', $details);

            $db->setQuery($query)->execute();

            // Opportunistic retention sweep on ~2% of writes keeps the table
            // bounded without needing a scheduled task.
            if (random_int(1, 50) === 1) {
                self::prune($db, (int) $params->get('retention_days', 30));
            }
        } catch (\Throwable $e) {
            Log::add('cs-page-protector log write failed: ' . $e->getMessage(), Log::WARNING, 'com_cspageprotector');
        }
    }

    /**
     * Is IP shortening on? On by default (GDPR-friendly).
     *
     * @param   Registry  $params  Component params.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function anonymizeEnabled(Registry $params): bool
    {
        return (int) $params->get('anonymize_ip', 1) === 1;
    }

    /**
     * Shorten every full IP already stored in the log, so switching the
     * option on also cleans up the history, not just new entries.
     * Idempotent: already-shortened addresses map to themselves.
     *
     * @param   DatabaseInterface  $db  Database.
     *
     * @return  integer  Number of rows changed.
     *
     * @since   0.1.0
     */
    public static function anonymizeStored(DatabaseInterface $db): int
    {
        $query = $db->createQuery()
            ->select('DISTINCT ' . $db->quoteName('ip'))
            ->from($db->quoteName('#__cspageprotector_log'))
            ->where($db->quoteName('ip') . ' <> ' . $db->quote(''));

        $changed = 0;

        foreach ($db->setQuery($query)->loadColumn() ?: [] as $ip) {
            $short = IpHelper::anonymize((string) $ip);

            if ($short === '' || $short === $ip) {
                continue;
            }

            $old    = (string) $ip;
            $update = $db->createQuery()
                ->update($db->quoteName('#__cspageprotector_log'))
                ->set($db->quoteName('ip') . ' = :short')
                ->where($db->quoteName('ip') . ' = :old')
                ->bind(':short', $short)
                ->bind(':old', $old);

            $db->setQuery($update)->execute();
            $changed += (int) $db->getAffectedRows();
        }

        return $changed;
    }

    /**
     * Delete rows older than the retention window. 0 = keep forever.
     *
     * @param   DatabaseInterface  $db    Database.
     * @param   int                $days  Retention in days.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public static function prune(DatabaseInterface $db, int $days): void
    {
        if ($days <= 0) {
            return;
        }

        $cutoff = Factory::getDate('-' . $days . ' days')->toSql();
        $query  = $db->createQuery()
            ->delete($db->quoteName('#__cspageprotector_log'))
            ->where($db->quoteName('created') . ' < :cutoff')
            ->bind(':cutoff', $cutoff);

        $db->setQuery($query)->execute();
    }

    /**
     * Was an event of this type written in the last N seconds?
     *
     * @param   DatabaseInterface  $db       Database.
     * @param   string             $event    Event type.
     * @param   int                $seconds  Window.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private static function recentlyLogged(DatabaseInterface $db, string $event, int $seconds): bool
    {
        $since = Factory::getDate('-' . $seconds . ' seconds')->toSql();
        $query = $db->createQuery()
            ->select('1')
            ->from($db->quoteName('#__cspageprotector_log'))
            ->where($db->quoteName('event') . ' = :event')
            ->where($db->quoteName('created') . ' >= :since')
            ->bind(':event', $event)
            ->bind(':since', $since);

        return (bool) $db->setQuery($query, 0, 1)->loadResult();
    }
}
