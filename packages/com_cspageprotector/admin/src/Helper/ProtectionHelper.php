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
use Joomla\CMS\Uri\Uri;
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

    public const MODULE_MODE_PLACEHOLDER = 'placeholder';
    public const MODULE_MODE_HIDE        = 'hide';

    /**
     * Memoised "is this request protected?" answer.
     *
     * @var    boolean|null
     * @since  0.1.0
     */
    private static ?bool $protectedMemo = null;

    /**
     * Memoised "may this visitor see protected content?" answer.
     *
     * @var    boolean|null
     * @since  0.1.0
     */
    private static ?bool $accessMemo = null;

    /**
     * Module ids protected in Options.
     *
     * @param   Registry  $params  Component params.
     *
     * @return  int[]
     *
     * @since   0.1.0
     */
    public static function getProtectedModuleIds(Registry $params): array
    {
        $ids = array_map('intval', (array) $params->get('protect_modules', []));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Module positions protected in Options (every module in them is protected).
     *
     * @param   Registry  $params  Component params.
     *
     * @return  string[]
     *
     * @since   0.1.0
     */
    public static function getProtectedPositions(Registry $params): array
    {
        $positions = array_map(static fn ($p): string => trim((string) $p), (array) $params->get('protect_positions', []));

        return array_values(array_unique(array_filter($positions, static fn (string $p): bool => $p !== '')));
    }

    /**
     * Is any module protection configured at all? (Cheap early-out.)
     *
     * @param   Registry  $params  Component params.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function hasModuleProtection(Registry $params): bool
    {
        return self::getProtectedModuleIds($params) !== [] || self::getProtectedPositions($params) !== [];
    }

    /**
     * Is this module protected (by id or by position)?
     *
     * @param   object    $module  Module row from ModuleHelper.
     * @param   Registry  $params  Component params.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function isProtectedModule(object $module, Registry $params): bool
    {
        if (\in_array((int) ($module->id ?? 0), self::getProtectedModuleIds($params), true)) {
            return true;
        }

        return \in_array((string) ($module->position ?? ''), self::getProtectedPositions($params), true);
    }

    /**
     * May the current visitor see protected content? True when they're
     * exempt (allow-listed IP, logged-in user/group, verified search engine)
     * or already hold a valid pass. Memoised for the request.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function visitorHasAccess(CMSApplicationInterface $app, Registry $params): bool
    {
        if (self::$accessMemo === null) {
            $ip        = IpHelper::getClientIp($params);
            $userAgent = $app->getInput()->server->getString('HTTP_USER_AGENT', '');

            self::$accessMemo = self::getExemption($app, $params, $ip, $userAgent) !== self::EXEMPT_NONE
                || VerificationHelper::isVerified($app, $params, $ip, $userAgent);
        }

        return self::$accessMemo;
    }

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

        // id[]=1 style requests: components cast them to an item, but our
        // matching can't, so treat them as protected (fail closed).
        if (\is_array($app->getInput()->get('id', null, 'raw'))) {
            return true;
        }

        $configured = self::getMenuItemIds($params);
        $itemId     = self::getActiveItemId($app);

        if ((string) $params->get('protection_mode', self::MODE_SELECTED) === self::MODE_ALL_EXCEPT) {
            // Only honour an exclusion when the request really IS that page.
            // A matching Itemid alone isn't enough: Joomla falls back to the
            // default menu item for unrouted URLs, and an Itemid can be bolted
            // onto any URL, so excluding Home must not open every article.
            return !self::isExcludedRequest($app, $configured);
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
     * Where to send a visitor after the check. Only this site is allowed: an
     * absolute URL must have exactly this site's host (and port), and a
     * relative one must be a path ("/..."). Joomla's Uri::isInternal() alone
     * would accept look-alike hosts such as example.co for example.com.
     *
     * @param   string  $url  Decoded return URL.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    public static function safeReturnUrl(string $url): string
    {
        $fallback = Uri::root();

        if ($url === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url)) {
            return $fallback;
        }

        $root  = new Uri(Uri::root());
        $parts = parse_url($url);

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return $fallback;
        }

        if (!isset($parts['host'])) {
            if (isset($parts['scheme']) || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
                return $fallback;
            }

            return $root->toString(['scheme', 'host', 'port']) . $url;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!\in_array($scheme, ['http', 'https'], true) || strcasecmp($parts['host'], (string) $root->getHost()) !== 0) {
            return $fallback;
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $rootPort    = (int) ($root->getPort() ?: ($root->getScheme() === 'https' ? 443 : 80));

        if ((int) ($parts['port'] ?? $defaultPort) !== $rootPort) {
            return $fallback;
        }

        return $url;
    }

    /**
     * Memoised com_content targets of page protection.
     *
     * @var    array{articles: int[], ranges: array<int, array{0: int, 1: int}>}|null
     * @since  0.1.0
     */
    private static ?array $contentTargets = null;

    /**
     * Memoised category id => [lft, rgt].
     *
     * @var    array<int, array{0: int, 1: int}|null>
     * @since  0.1.0
     */
    private static array $categoryRanges = [];

    /**
     * Articles and category trees that page protection covers ("selected"
     * mode with "also catch other routes" on): articles with their own
     * protected menu item, and everything inside a protected category.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  array{articles: int[], ranges: array<int, array{0: int, 1: int}>}
     *
     * @since   0.1.0
     */
    public static function getContentTargets(CMSApplicationInterface $app, Registry $params): array
    {
        if (self::$contentTargets !== null) {
            return self::$contentTargets;
        }

        $targets = ['articles' => [], 'ranges' => []];

        if (
            (string) $params->get('protection_mode', self::MODE_SELECTED) !== self::MODE_SELECTED
            || (int) $params->get('match_content', 1) !== 1
            || !method_exists($app, 'getMenu')
        ) {
            return self::$contentTargets = $targets;
        }

        $categories = [];

        foreach (self::getMenuItemIds($params) as $menuItemId) {
            $item = $app->getMenu()->getItem($menuItemId);

            if (!$item || (string) ($item->query['option'] ?? '') !== 'com_content') {
                continue;
            }

            $view = (string) ($item->query['view'] ?? '');
            $id   = (int) ($item->query['id'] ?? 0);

            if ($view === 'article' && $id > 0) {
                $targets['articles'][] = $id;
            } elseif ($view === 'category' && $id > 0) {
                $categories[] = $id;
            }
        }

        foreach (array_unique($categories) as $categoryId) {
            $range = self::getCategoryRange($categoryId);

            if ($range !== null) {
                $targets['ranges'][$categoryId] = $range;
            }
        }

        $targets['articles'] = array_values(array_unique($targets['articles']));

        return self::$contentTargets = $targets;
    }

    /**
     * Is this com_content article covered by page protection?
     *
     * @param   int  $articleId   Article id.
     * @param   int  $categoryId  The article's category id.
     * @param   array{articles: int[], ranges: array<int, array{0: int, 1: int}>}  $targets  From getContentTargets().
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function isProtectedArticle(int $articleId, int $categoryId, array $targets): bool
    {
        if ($articleId > 0 && \in_array($articleId, $targets['articles'], true)) {
            return true;
        }

        if ($targets['ranges'] === [] || $categoryId <= 0) {
            return false;
        }

        $range = self::getCategoryRange($categoryId);

        if ($range === null) {
            return false;
        }

        foreach ($targets['ranges'] as [$lft, $rgt]) {
            if ($lft <= $range[0] && $rgt >= $range[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Would a com_content feed for this view include protected articles?
     * Category feeds are checked against their whole subtree (they can include
     * subcategory content); featured and archive feeds against the site.
     *
     * @param   string  $view        Request view.
     * @param   int     $categoryId  Request id (category feeds).
     * @param   array{articles: int[], ranges: array<int, array{0: int, 1: int}>}  $targets  From getContentTargets().
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function feedIncludesProtected(string $view, int $categoryId, array $targets): bool
    {
        if ($targets['articles'] === [] && $targets['ranges'] === []) {
            return false;
        }

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__content', 'a'))
                ->join('INNER', $db->quoteName('#__categories', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid'));

            $scope = [];

            if ($targets['articles'] !== []) {
                $scope[] = $db->quoteName('a.id') . ' IN (' . implode(',', array_map('intval', $targets['articles'])) . ')';
            }

            foreach ($targets['ranges'] as [$lft, $rgt]) {
                $scope[] = '(' . $db->quoteName('c.lft') . ' >= ' . (int) $lft . ' AND ' . $db->quoteName('c.rgt') . ' <= ' . (int) $rgt . ')';
            }

            $query->where('(' . implode(' OR ', $scope) . ')');

            if ($view === 'category') {
                $range = self::getCategoryRange($categoryId);

                if ($range === null) {
                    return false;
                }

                $query->where($db->quoteName('c.lft') . ' >= ' . (int) $range[0])
                    ->where($db->quoteName('c.rgt') . ' <= ' . (int) $range[1]);
            } elseif ($view === 'featured') {
                $query->where($db->quoteName('a.featured') . ' = 1');
            }

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (\Throwable $e) {
            // Can't tell: treat the feed as leaking.
            return true;
        }
    }

    /**
     * Module types (e.g. "mod_login") of the protected modules, for gating
     * com_ajax calls into those modules.
     *
     * @param   Registry  $params  Component params.
     *
     * @return  string[]
     *
     * @since   0.1.0
     */
    public static function getProtectedModuleTypes(Registry $params): array
    {
        $ids       = self::getProtectedModuleIds($params);
        $positions = self::getProtectedPositions($params);

        if ($ids === [] && $positions === []) {
            return [];
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $or = [];

            if ($ids !== []) {
                $or[] = $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
            }

            if ($positions !== []) {
                $or[] = $db->quoteName('position') . ' IN (' . implode(',', array_map([$db, 'quote'], $positions)) . ')';
            }

            $query = $db->createQuery()
                ->select('DISTINCT ' . $db->quoteName('module'))
                ->from($db->quoteName('#__modules'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->where('(' . implode(' OR ', $or) . ')');

            return array_map('strval', $db->setQuery($query)->loadColumn() ?: []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * [lft, rgt] of a com_content category, memoised.
     *
     * @param   int  $categoryId  Category id.
     *
     * @return  array{0: int, 1: int}|null
     *
     * @since   0.1.0
     */
    private static function getCategoryRange(int $categoryId): ?array
    {
        if (\array_key_exists($categoryId, self::$categoryRanges)) {
            return self::$categoryRanges[$categoryId];
        }

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select([$db->quoteName('lft'), $db->quoteName('rgt')])
                ->from($db->quoteName('#__categories'))
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
                ->bind(':id', $categoryId, ParameterType::INTEGER);

            $row = $db->setQuery($query)->loadObject();

            return self::$categoryRanges[$categoryId] = $row ? [(int) $row->lft, (int) $row->rgt] : null;
        } catch (\Throwable $e) {
            return self::$categoryRanges[$categoryId] = null;
        }
    }

    /**
     * "Every page except…" mode: is this request one of the excluded pages?
     * True only when the request's option / view / id / layout match an
     * excluded menu item's own link, or (com_content) the request is an
     * article or subcategory inside an excluded category.
     *
     * @param   CMSApplicationInterface  $app       The application.
     * @param   int[]                    $excluded  Excluded menu item ids.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private static function isExcludedRequest(CMSApplicationInterface $app, array $excluded): bool
    {
        if ($excluded === []) {
            return false;
        }

        $input   = $app->getInput();
        $request = [
            'option' => $input->getCmd('option', ''),
            'view'   => $input->getCmd('view', ''),
            'id'     => (string) $input->getInt('id', 0),
            'layout' => $input->getCmd('layout', ''),
        ];
        $menu       = $app->getMenu();
        $categories = [];

        foreach ($excluded as $menuItemId) {
            $item = $menu->getItem($menuItemId);

            if (!$item || (string) ($item->query['option'] ?? '') !== $request['option']) {
                continue;
            }

            $matches = true;

            foreach (['view', 'id', 'layout'] as $key) {
                $expected = $key === 'id' ? (string) (int) ($item->query[$key] ?? 0) : (string) ($item->query[$key] ?? '');

                if ($expected !== $request[$key] && !($key === 'layout' && $expected === '')) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return true;
            }

            if ($request['option'] === 'com_content' && (string) ($item->query['view'] ?? '') === 'category' && (int) ($item->query['id'] ?? 0) > 0) {
                $categories[] = (int) $item->query['id'];
            }
        }

        $id = (int) $request['id'];

        if ($categories !== [] && $id > 0 && \in_array($request['view'], ['article', 'category'], true)) {
            return self::isInsideCategories($request['view'], $id, $categories);
        }

        return false;
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
