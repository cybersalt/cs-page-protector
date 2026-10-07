<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Decides whether the current front-end request is for a protected page and,
 * if so, whether the visitor is exempt.
 *
 * Kept free of side effects so the system plugin can ask the question from
 * more than one event (onAfterRoute, the page-cache events) and always get
 * the same memoised answer.
 *
 * @since  0.1.0
 */
final class ProtectionHelper
{
    public const MODE_SELECTED   = 'selected';
    public const MODE_ALL_EXCEPT = 'all_except';

    public const EXEMPT_NONE  = '';
    public const EXEMPT_IP    = 'ip';
    public const EXEMPT_USER  = 'user';
    public const EXEMPT_GROUP = 'group';
    public const EXEMPT_BOT   = 'bot';

    /**
     * Components that are never intercepted: com_ajax carries the captcha's
     * own challenge request, and the challenge page lives in this component.
     *
     * @var    string[]
     * @since  0.1.0
     */
    private const ALWAYS_ALLOWED_OPTIONS = ['com_ajax', 'com_cspageprotector'];

    /**
     * Memoised "is this request protected?" answer.
     *
     * @var    boolean|null
     * @since  0.1.0
     */
    private static ?bool $protectedMemo = null;

    /**
     * Component params.
     *
     * @return  Registry
     *
     * @since   0.1.0
     */
    public static function getParams(): Registry
    {
        return ComponentHelper::getParams('com_cspageprotector');
    }

    /**
     * Configured menu item ids (protected in "selected" mode, excluded in
     * "all except" mode).
     *
     * @param   Registry  $params  Component params.
     *
     * @return  int[]
     *
     * @since   0.1.0
     */
    public static function getMenuItemIds(Registry $params): array
    {
        $ids = array_map('intval', (array) $params->get('menu_items', []));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Is the current request for a protected page?
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function isProtectedRequest(CMSApplicationInterface $app, Registry $params): bool
    {
        if (self::$protectedMemo === null) {
            self::$protectedMemo = self::computeProtected($app, $params);
        }

        return self::$protectedMemo;
    }

    /**
     * Id of the menu item the router resolved for this request.
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  integer
     *
     * @since   0.1.0
     */
    public static function getActiveItemId(CMSApplicationInterface $app): int
    {
        $active = method_exists($app, 'getMenu') ? $app->getMenu()->getActive() : null;

        if ($active && (int) $active->id > 0) {
            return (int) $active->id;
        }

        return $app->getInput()->getInt('Itemid', 0);
    }

    /**
     * Why (if at all) is this visitor allowed through without a challenge?
     *
     * @param   CMSApplicationInterface  $app        The application.
     * @param   Registry                 $params     Component params.
     * @param   string                   $ip         Client IP.
     * @param   string                   $userAgent  Client User-Agent.
     *
     * @return  string  One of the EXEMPT_* constants; EXEMPT_NONE means "challenge them".
     *
     * @since   0.1.0
     */
    public static function getExemption(CMSApplicationInterface $app, Registry $params, string $ip, string $userAgent): string
    {
        if (IpHelper::matchesList($ip, (string) $params->get('exempt_ips', ''))) {
            return self::EXEMPT_IP;
        }

        $user = $app->getIdentity();

        if ($user && !$user->guest) {
            if ((int) $params->get('exempt_logged_in', 1) === 1) {
                return self::EXEMPT_USER;
            }

            $groups = array_map('intval', (array) $params->get('exempt_groups', []));

            if ($groups !== [] && array_intersect($groups, $user->getAuthorisedGroups()) !== []) {
                return self::EXEMPT_GROUP;
            }
        }

        // Last, because it's the only check that can cost a DNS lookup — and
        // only for requests whose User-Agent claims to be a search engine.
        if ((int) $params->get('exempt_search_engines', 1) === 1 && BotVerifier::isVerifiedCrawler($ip, $userAgent)) {
            return self::EXEMPT_BOT;
        }

        return self::EXEMPT_NONE;
    }

    /**
     * Work out whether this request is protected.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private static function computeProtected(CMSApplicationInterface $app, Registry $params): bool
    {
        if (!$app->isClient('site')) {
            return false;
        }

        $option = $app->getInput()->getCmd('option', '');

        if ($option === '' || \in_array($option, self::ALWAYS_ALLOWED_OPTIONS, true)) {
            return false;
        }

        $configured = self::getMenuItemIds($params);
        $itemId     = self::getActiveItemId($app);

        if ((string) $params->get('protection_mode', self::MODE_SELECTED) === self::MODE_ALL_EXCEPT) {
            if ($itemId > 0 && \in_array($itemId, $configured, true)) {
                $item = $app->getMenu()->getItem($itemId);

                // Only honour the exclusion when the request really belongs to
                // that menu item's component — stops "?Itemid=<excluded>" being
                // bolted onto any URL to dodge the challenge.
                if ($item && (string) ($item->query['option'] ?? '') === $option) {
                    return false;
                }
            }

            return true;
        }

        if ($configured === []) {
            return false;
        }

        if ($itemId > 0 && \in_array($itemId, $configured, true)) {
            return true;
        }

        if ((int) $params->get('match_content', 1) !== 1) {
            return false;
        }

        return self::matchesProtectedContent($app, $configured);
    }

    /**
     * Catch requests for protected content reached through a different (or
     * forged) Itemid: same component + view + id as a protected menu item, or a
     * com_content article / category that sits inside a protected category.
     *
     * @param   CMSApplicationInterface  $app         The application.
     * @param   int[]                    $configured  Protected menu item ids.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private static function matchesProtectedContent(CMSApplicationInterface $app, array $configured): bool
    {
        $input  = $app->getInput();
        $option = $input->getCmd('option', '');
        $view   = $input->getCmd('view', '');
        $id     = $input->getInt('id', 0);
        $menu   = $app->getMenu();

        $protectedCategories = [];

        foreach ($configured as $menuItemId) {
            $item = $menu->getItem($menuItemId);

            if (!$item || (string) ($item->query['option'] ?? '') !== $option) {
                continue;
            }

            $itemView = (string) ($item->query['view'] ?? '');
            $itemId   = (int) ($item->query['id'] ?? 0);

            if ($itemView === $view && $itemId === $id) {
                return true;
            }

            if ($option === 'com_content' && $itemView === 'category' && $itemId > 0) {
                $protectedCategories[] = $itemId;
            }
        }

        if ($protectedCategories === [] || $id <= 0 || !\in_array($view, ['article', 'category'], true)) {
            return false;
        }

        return self::isInsideCategories($view, $id, $protectedCategories);
    }

    /**
     * Is the article / category inside (or equal to) one of the given
     * com_content categories? Uses the nested-set lft/rgt columns.
     *
     * @param   string  $view         'article' or 'category'.
     * @param   int     $id           Article or category id.
     * @param   int[]   $categoryIds  Protected category ids.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private static function isInsideCategories(string $view, int $id, array $categoryIds): bool
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            if ($view === 'article') {
                $query = $db->createQuery()
                    ->select([$db->quoteName('c.lft'), $db->quoteName('c.rgt')])
                    ->from($db->quoteName('#__content', 'a'))
                    ->join('INNER', $db->quoteName('#__categories', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid'))
                    ->where($db->quoteName('a.id') . ' = :id')
                    ->bind(':id', $id, ParameterType::INTEGER);
            } else {
                $query = $db->createQuery()
                    ->select([$db->quoteName('lft'), $db->quoteName('rgt')])
                    ->from($db->quoteName('#__categories'))
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
                    ->bind(':id', $id, ParameterType::INTEGER);
            }

            $target = $db->setQuery($query)->loadObject();

            if (!$target) {
                return false;
            }

            $lft = (int) $target->lft;
            $rgt = (int) $target->rgt;

            $query = $db->createQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__categories'))
                ->whereIn($db->quoteName('id'), $categoryIds, ParameterType::INTEGER)
                ->where($db->quoteName('lft') . ' <= :lft')
                ->where($db->quoteName('rgt') . ' >= :rgt')
                ->bind(':lft', $lft, ParameterType::INTEGER)
                ->bind(':rgt', $rgt, ParameterType::INTEGER);

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (\Throwable $e) {
            // Fail towards protection being decided by Itemid alone.
            return false;
        }
    }
}
