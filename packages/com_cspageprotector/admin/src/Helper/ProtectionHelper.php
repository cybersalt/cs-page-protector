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
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\Factory\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Input\Input;
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
     * One "Also protect these views" entry: component:view, lower case.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    public const VIEW_ENTRY_PATTERN = '/^com_[a-z0-9_]+:[a-z0-9_.-]+$/';

    /**
     * Components "Also protect these views" refuses: their pages have to stay
     * reachable for visitors who haven't passed (logging in, registering,
     * resetting a password, privacy consent, front-end site settings).
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const RESERVED_VIEW_OPTIONS = ['com_users', 'com_config', 'com_privacy'];

    /**
     * View names "Also protect these views" refuses in any component: payment
     * returns and server-to-server callbacks, which a payment provider can't
     * answer a check on.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    private const RESERVED_VIEW_PATTERN = '/(notify|notification|callback|webhook|postback|response|vmplg|checkout|payment|^ipn|ipn$)/';

    /**
     * Request keys that are a single value on every real page. An array in
     * any of them can't be matched against the protected pages (a component
     * may still turn it into one), so such a request is treated as protected.
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const SINGLE_VALUE_KEYS = ['option', 'view', 'id', 'Itemid', 'task', 'controller', 'layout', 'format'];

    /**
     * Components whose lists are categories. A protected category list of one
     * of these protects every item (and subcategory) inside that category,
     * whichever menu item a request uses: articles, contacts, news feeds.
     * com_content also has its own list and feed gate (getContentTargets()).
     *
     * @var    array<string, array{view: string, table: string}>
     * @since  __DEPLOY_VERSION__
     */
    private const CATEGORY_COMPONENTS = [
        'com_content'   => ['view' => 'article', 'table' => '#__content'],
        'com_contact'   => ['view' => 'contact', 'table' => '#__contact_details'],
        'com_newsfeeds' => ['view' => 'newsfeed', 'table' => '#__newsfeeds'],
    ];

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
     * Are any pages protected? "Every page except" always protects something;
     * "selected" mode only once menu items are picked.
     *
     * @param   Registry  $params  Component params.
     *
     * @return  boolean
     *
     * @since   0.2.0
     */
    public static function hasPageProtection(Registry $params): bool
    {
        return (string) $params->get('protection_mode', 'selected') === self::MODE_ALL_EXCEPT
            || self::getMenuItemIds($params) !== []
            || self::getProtectedViews($params) !== [];
    }

    /**
     * "Also protect these views" ("selected" mode only): component:view
     * entries, lower case. Invalid and refused entries are left out (Options
     * refuses to save them, see ProtectviewsRule).
     *
     * @param   Registry  $params  Component params.
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getProtectedViews(Registry $params): array
    {
        if ((string) $params->get('protection_mode', self::MODE_SELECTED) !== self::MODE_SELECTED) {
            return [];
        }

        return self::parseViewList((string) $params->get('protect_views', ''))['valid'];
    }

    /**
     * Split an "Also protect these views" value into its entries. One entry
     * per line (commas also separate); "#" starts a comment that runs to the
     * end of its line, so a commented-out line never leaves a live entry
     * behind.
     *
     * - valid: usable entries, as option:view with the view name reduced to
     *   the characters Joomla keeps when it loads a view ([a-z0-9_]);
     * - invalid: not written as component:view;
     * - reserved: pages that must stay reachable without the check (login,
     *   registration, password reset, privacy, front-end settings, payment
     *   returns and callbacks);
     * - unchecked: com_ajax and this component, which are never checked, so
     *   the entry could never do anything.
     *
     * @param   string  $text  Field value.
     *
     * @return  array{valid: string[], invalid: string[], reserved: string[], unchecked: string[]}
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function parseViewList(string $text): array
    {
        $result = ['valid' => [], 'invalid' => [], 'reserved' => [], 'unchecked' => []];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            foreach (preg_split('/[\s,]+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
                $normalised = self::asciiLower($entry);

                if (!preg_match(self::VIEW_ENTRY_PATTERN, $normalised)) {
                    $result['invalid'][] = $entry;

                    continue;
                }

                [$option, $view] = explode(':', $normalised, 2);
                $view            = self::normaliseName($view);

                if ($view === '') {
                    $result['invalid'][] = $entry;

                    continue;
                }

                $key = $option . ':' . $view;

                if (\in_array($option, self::ALWAYS_ALLOWED_OPTIONS, true)) {
                    $result['unchecked'][] = $key;
                } elseif (\in_array($option, self::RESERVED_VIEW_OPTIONS, true) || preg_match(self::RESERVED_VIEW_PATTERN, $view)) {
                    $result['reserved'][] = $key;
                } else {
                    $result['valid'][] = $key;
                }
            }
        }

        foreach (['valid', 'reserved', 'unchecked'] as $list) {
            $result[$list] = array_values(array_unique($result[$list]));
        }

        return $result;
    }

    /**
     * Lower-case ASCII letters only. strtolower() follows the locale on PHP
     * 8.1 (a Turkish locale turns "I" into a dotless i), and every name
     * compared here is ASCII.
     *
     * @param   string  $text  Text to lower-case.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function asciiLower(string $text): string
    {
        return strtr($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /**
     * A view or controller name the way Joomla uses it to load the class:
     * lower case, letters, digits and underscores only (MVCFactory strips
     * everything else, so "con.tact" loads the "contact" view).
     *
     * @param   string  $name  Name from the request or a menu link.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function normaliseName(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9_]/', '', self::asciiLower($name));
    }

    /**
     * A request value that has to be a single value: the value through the
     * given filter, or null when it was sent as an array (or object).
     *
     * @param   Input   $input    The request input.
     * @param   string  $key      Request key.
     * @param   string  $filter   Input filter, e.g. "cmd", "word".
     * @param   string  $default  Value when the key is missing.
     *
     * @return  string|null
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function singleValue(Input $input, string $key, string $filter = 'cmd', string $default = ''): ?string
    {
        $raw = $input->get($key, null, 'raw');

        if ($raw === null) {
            return $default;
        }

        if (!\is_scalar($raw)) {
            return null;
        }

        return (string) $input->get($key, $default, $filter);
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
     * Write the component params straight to #__extensions (for actions that
     * aren't an Options save) and drop the cached copy. With caching on,
     * ComponentHelper reads params from the `_system` cache group, so without
     * this the change wouldn't show until that cache expired. The site cache
     * lives in /cache on Joomla 5 and administrator/cache on Joomla 6, so both
     * (and cache_path) are cleaned.
     *
     * @param   Registry  $params  The full params to store.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public static function saveParams(Registry $params): void
    {
        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $json = $params->toString();

        $db->setQuery(
            $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = :params')
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_cspageprotector'))
                ->bind(':params', $json)
        )->execute();

        self::cleanCacheGroup('_system');
    }

    /**
     * Clean one cache group in every place Joomla may keep it: the site cache
     * is /cache on Joomla 5 and administrator/cache on Joomla 6, plus
     * cache_path when it's set.
     *
     * @param   string  $group  Cache group, e.g. `_system` or `com_plugins`.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public static function cleanCacheGroup(string $group): void
    {
        $bases = array_unique(array_filter([
            (string) Factory::getApplication()->get('cache_path', ''),
            JPATH_SITE . '/cache',
            JPATH_ADMINISTRATOR . '/cache',
        ]));

        foreach ($bases as $base) {
            if (!is_dir($base)) {
                continue;
            }

            try {
                Factory::getContainer()->get(CacheControllerFactoryInterface::class)
                    ->createCacheController('callback', ['defaultgroup' => $group, 'cachebase' => $base])
                    ->clean($group);
            } catch (\Throwable $e) {
                // A stale cache only delays the change; the database is already right.
            }
        }
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

        // Itemid[]=… is an array: no menu item (the request is protected
        // anyway, see SINGLE_VALUE_KEYS), and never a TypeError.
        return (int) (self::singleValue($app->getInput(), 'Itemid', 'int', '0') ?? 0);
    }

    /**
     * Protected menu item ids ("selected" mode): the ones picked in Options
     * plus their language copies and Menu Item Aliases (see
     * withRelatedItems()).
     *
     * @param   Registry  $params  Component params.
     *
     * @return  int[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getProtectedMenuItemIds(Registry $params): array
    {
        return self::withRelatedItems(self::getMenuItemIds($params));
    }

    /**
     * Menu item ids plus the items that show the same page under another id:
     * their language associations (the French copy of a page), and published
     * Menu Item Alias items that point at any of them (Joomla shows the
     * target's page under the alias's own id when the alias doesn't
     * redirect). Used for protected items and, in "every page except" mode,
     * for the excluded ones.
     *
     * @param   int[]  $ids  Menu item ids.
     *
     * @return  int[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function withRelatedItems(array $ids): array
    {
        $key = implode(',', $ids);

        if ($ids === [] || isset(self::$associationMemo[$key])) {
            return $ids === [] ? [] : self::$associationMemo[$key];
        }

        $all = $ids;

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select($db->quoteName('b.id'))
                ->from($db->quoteName('#__associations', 'a'))
                ->join(
                    'INNER',
                    $db->quoteName('#__associations', 'b'),
                    $db->quoteName('b.key') . ' = ' . $db->quoteName('a.key') . ' AND ' . $db->quoteName('b.context') . ' = ' . $db->quoteName('a.context')
                )
                ->where($db->quoteName('a.context') . ' = ' . $db->quote('com_menus.item'))
                ->whereIn($db->quoteName('a.id'), $ids, ParameterType::INTEGER);

            $all = array_merge($all, array_map('intval', $db->setQuery($query)->loadColumn() ?: []));
        } catch (\Throwable $e) {
            // No associations table or no database: the picked items still count.
        }

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select($db->quoteName(['id', 'params']))
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('alias'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('published') . ' = 1');

            foreach ($db->setQuery($query)->loadObjectList() ?: [] as $alias) {
                $aliasParams = json_decode((string) $alias->params, true);
                $target      = \is_array($aliasParams) && \is_scalar($aliasParams['aliasoptions'] ?? null) ? (int) $aliasParams['aliasoptions'] : 0;

                if ($target > 0 && \in_array($target, $all, true)) {
                    $all[] = (int) $alias->id;
                }
            }
        } catch (\Throwable $e) {
            // Can't read the aliases: the picked items and their copies still count.
        }

        return self::$associationMemo[$key] = array_values(array_unique(array_filter($all, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Memoised withRelatedItems() results, keyed by the given ids.
     *
     * @var    array<string, int[]>
     * @since  __DEPLOY_VERSION__
     */
    private static array $associationMemo = [];

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

        $input  = $app->getInput();
        $option = self::singleValue($input, 'option');

        if ($option !== null) {
            $option = self::asciiLower($option);

            if ($option === '' || \in_array($option, self::ALWAYS_ALLOWED_OPTIONS, true)) {
                return false;
            }
        }

        if (!self::hasPageProtection($params)) {
            return false;
        }

        // option[]=…, view[]=…, id[]=1, Itemid[]=… and the like: a component
        // may still turn them into a page, but they can't be matched against
        // the protected ones, so treat them as protected (fail closed). An
        // array option or Itemid can't be tied to a component or menu item,
        // and in "every page except" mode every component has something
        // protected, so those are protected straight away. Otherwise only a
        // component with something protected counts (below): com_tags menu
        // items carry their tag as id[0]=N, and strict routing sends that on.
        $arrays    = array_values(array_filter(self::SINGLE_VALUE_KEYS, static fn (string $key): bool => self::singleValue($input, $key) === null));
        $allExcept = (string) $params->get('protection_mode', self::MODE_SELECTED) === self::MODE_ALL_EXCEPT;

        if ($arrays !== [] && ($option === null || $allExcept || \in_array('Itemid', $arrays, true))) {
            return true;
        }

        $configured = self::getMenuItemIds($params);

        if ($allExcept) {
            // Only honour an exclusion when the request really IS that page.
            // A matching Itemid alone isn't enough: Joomla falls back to the
            // default menu item for unrouted URLs, and an Itemid can be bolted
            // onto any URL, so excluding Home must not open every article.
            // Language copies of an excluded page are excluded too, the same
            // way they're protected in "selected" mode.
            return !self::isExcludedRequest($app, self::withRelatedItems($configured), (string) $option);
        }

        $protected = self::getProtectedMenuItemIds($params);
        $views     = self::getProtectedViews($params);

        if ($protected === [] && $views === []) {
            return false;
        }

        $itemId = self::getActiveItemId($app);

        if ($itemId > 0 && \in_array($itemId, $protected, true)) {
            return true;
        }

        // The rules below only ever match a component that has something
        // protected, so leave every other component alone (and unread).
        $option    = (string) $option;
        $views     = array_values(array_filter($views, static fn (string $entry): bool => str_starts_with($entry, $option . ':')));
        $ownItems  = self::itemsOfComponent($app, $protected, $option);
        $protected = (int) $params->get('match_content', 1) === 1 ? $ownItems : [];

        // An array in view, id, task and the like, in a component that has a
        // protected menu item or view: can't be matched, so protected.
        if ($arrays !== []) {
            return $views !== [] || $ownItems !== [];
        }

        if ($views === [] && $protected === []) {
            return false;
        }

        $request = self::describeRequest($app, $option);

        // "Also protect these views": whatever the Itemid, or none.
        if ($views !== [] && self::matchesProtectedView($app, $request, $views)) {
            return true;
        }

        return $protected !== [] && self::matchesProtectedContent($app, $protected, $request);
    }

    /**
     * The menu items (of the given ids) that belong to a component.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   int[]                    $ids     Menu item ids.
     * @param   string                   $option  Component, lower case.
     *
     * @return  int[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function itemsOfComponent(CMSApplicationInterface $app, array $ids, string $option): array
    {
        if ($ids === [] || !method_exists($app, 'getMenu')) {
            return [];
        }

        $menu = $app->getMenu();

        return array_values(array_filter($ids, static function (int $id) use ($menu, $option): bool {
            $item = $menu->getItem($id);

            return $item && self::asciiLower(self::queryString((array) ($item->query ?? []), 'option')) === $option;
        }));
    }

    /**
     * The parts of the request the rules match on: component, the view
     * Joomla will show (see resolveView()) and id.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   string                   $option  Request component, lower case.
     *
     * @return  array{option: string, view: ?string, id: int}
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function describeRequest(CMSApplicationInterface $app, string $option): array
    {
        $input = $app->getInput();

        return [
            'option' => $option,
            'view'   => self::resolveView(
                $app,
                $option,
                (string) self::singleValue($input, 'view'),
                (string) self::singleValue($input, 'task'),
                (string) self::singleValue($input, 'controller')
            ),
            'id'     => (int) (self::singleValue($input, 'id', 'int', '0') ?? 0),
        ];
    }

    /**
     * The view a request shows, worked out the way Joomla picks it, so a
     * request can't step around a view rule by leaving `view` out:
     *
     * - ComponentDispatcher runs `task=<controller>.<method>` (or
     *   `controller=` plus `task=<method>`). A method the controller has, or
     *   a task alias of one, other than display(), runs instead of a page
     *   view;
     * - otherwise display() runs (a method the controller doesn't have falls
     *   back to it as well) and shows `view`, or with no `view` the view
     *   named after the controller.
     *
     * The result is null when the request runs a real task that isn't
     * display() (a form submit, a payment callback, an AJAX call), so the
     * view rules never touch those. It is '' when the page is the
     * component's own default view, when a named controller has its own
     * display() (it may show any view, whatever `view` says), or for a
     * component whose controllers can't be read (older, non-namespaced
     * components): the rules then treat the request as any view of that
     * component, unless it is exactly the page of the menu item it was
     * opened under.
     *
     * @param   CMSApplicationInterface  $app         The application.
     * @param   string                   $option      Component, lower case.
     * @param   string                   $view        `view` value.
     * @param   string                   $task        `task` value.
     * @param   string                   $controller  `controller` value.
     *
     * @return  string|null
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function resolveView(CMSApplicationInterface $app, string $option, string $view, string $task, string $controller): ?string
    {
        $view = self::normaliseName($view);

        // ComponentDispatcher::dispatch(): "controller.method", else the
        // `controller` value (default "display") and the task as the method.
        if (str_contains($task, '.')) {
            $parts      = explode('.', $task);
            $controller = $parts[0];
            $method     = $parts[1];
            $named      = true;
        } else {
            $method = $task === '' ? 'display' : $task;
            $named  = $controller !== '';
        }

        $method     = self::asciiLower($method);
        $controller = self::normaliseName($controller);

        // A controller other than the component's DisplayController, named in
        // the request (`task=profile.display`, `controller=profile`).
        $custom = $named && $controller !== 'display';
        $class  = $method === 'display' && $view !== '' && !$custom ? null : self::controllerClass($app, $option, $named ? $controller : 'display');

        // A real task of the controller runs instead of display(), whatever
        // `view` says (the router fills `view` in from the menu item, so a
        // form submit or a payment callback often carries one).
        if ($class !== null && $method !== 'display' && \in_array($method, self::controllerTasks($class), true)) {
            return null;
        }

        // A named controller with its own display() can show any view, not
        // the one the request names: unknown, so the rules fail closed. A
        // DisplayController's own display() keeps the request's view (core
        // components all have one, and they show `view`).
        if ($custom && $class !== null && self::hasOwnDisplay($class)) {
            return '';
        }

        if ($view !== '') {
            return $view;
        }

        if ($class === null) {
            // Can't read the controller (an older component picks it in its
            // own entry file): only a display counts as a page view.
            return $method === 'display' || $method === '' ? '' : null;
        }

        // A controller with its own display() picks the view itself.
        if (self::hasOwnDisplay($class)) {
            return '';
        }

        try {
            $defaults = (new \ReflectionClass($class))->getDefaultProperties();
        } catch (\ReflectionException $e) {
            return '';
        }

        // BaseController: default_view, else the controller's name, which the
        // dispatcher sets from the request for a named controller.
        foreach ([(string) ($defaults['default_view'] ?? ''), (string) ($defaults['name'] ?? ''), $named ? $controller : ''] as $candidate) {
            $candidate = self::normaliseName($candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Memoised controller class lookups, keyed "option:name".
     *
     * @var    array<string, string|null>
     * @since  __DEPLOY_VERSION__
     */
    private static array $controllerClasses = [];

    /**
     * The site controller class ComponentDispatcher would create for this
     * component and controller name, or null when it can't be told: an older
     * component without a namespace (its own entry file picks the
     * controller), or no such class.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   string                   $option  Component, lower case.
     * @param   string                   $name    Controller name, normalised.
     *
     * @return  string|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function controllerClass(CMSApplicationInterface $app, string $option, string $name): ?string
    {
        $key = $option . ':' . $name;

        if (\array_key_exists($key, self::$controllerClasses)) {
            return self::$controllerClasses[$key];
        }

        $class = null;

        try {
            if ($name !== '' && method_exists($app, 'bootComponent')) {
                $component = $app->bootComponent($option);
                $factory   = $component instanceof MVCFactoryServiceInterface ? $component->getMVCFactory() : null;

                if ($factory instanceof MVCFactory) {
                    $namespace = (new \ReflectionProperty(MVCFactory::class, 'namespace'))->getValue($factory);

                    if (\is_string($namespace) && $namespace !== '') {
                        $candidate = trim($namespace, '\\') . '\\Site\\Controller\\' . ucfirst($name) . 'Controller';

                        if (class_exists($candidate) && is_subclass_of($candidate, BaseController::class)) {
                            $class = $candidate;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $class = null;
        }

        return self::$controllerClasses[$key] = $class;
    }

    /**
     * Does this controller declare its own display() (or one of its parents
     * other than BaseController does)? Unreadable counts as yes.
     *
     * @param   string  $class  Controller class.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function hasOwnDisplay(string $class): bool
    {
        try {
            return (new \ReflectionMethod($class, 'display'))->getDeclaringClass()->getName() !== BaseController::class;
        } catch (\ReflectionException $e) {
            return true;
        }
    }

    /**
     * BaseController's own methods, every visibility, the way its constructor
     * sees them when it registers tasks (get_class_methods() called from
     * inside the class).
     *
     * @var    string[]|null
     * @since  __DEPLOY_VERSION__
     */
    private static ?array $baseControllerMethods = null;

    /**
     * The tasks a controller answers (lower case), other than ones that show
     * a page, the way BaseController registers them: its public methods,
     * minus BaseController's own (of any visibility) except display(), plus
     * the task aliases its code registers (see controllerAliases()). An alias
     * that points at display(), or one that's unregistered again, is left
     * out: Joomla shows a page for it.
     *
     * @param   string  $class  Controller class.
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function controllerTasks(string $class): array
    {
        self::$baseControllerMethods ??= (array) \Closure::bind(
            static fn (): array => get_class_methods(BaseController::class),
            null,
            BaseController::class
        )();

        $base  = self::$baseControllerMethods;
        $tasks = [];

        try {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $name = $method->getName();

                if ($name === 'display' || !\in_array($name, $base, true)) {
                    $tasks[] = self::asciiLower($name);
                }
            }
        } catch (\ReflectionException $e) {
            return [];
        }

        // registerTask() only takes a method the controller has (or display).
        $methods = $tasks;

        foreach (self::controllerAliases($class) as $alias => $target) {
            $tasks = array_values(array_diff($tasks, [$alias]));

            if ($target !== null && $target !== 'display' && \in_array($target, $methods, true)) {
                $tasks[] = $alias;
            }
        }

        return $tasks;
    }

    /**
     * Memoised controllerAliases() results, keyed by class.
     *
     * @var    array<string, array<string, string|null>>
     * @since  __DEPLOY_VERSION__
     */
    private static array $aliasMemo = [];

    /**
     * Task aliases a controller registers, read from the source of the class
     * and its parents up to BaseController (parents first, so a subclass
     * wins): `registerTask('notify', 'callback')` gives notify => callback,
     * `unregisterTask('notify')` gives notify => null. The controller is
     * never created, so none of its code runs. Only calls with plain quoted
     * names count; anything else is left out, and the request then counts as
     * a page view (fail closed). Without PHP's tokenizer, no aliases.
     *
     * @param   string  $class  Controller class.
     *
     * @return  array<string, string|null>  Lower-case alias => lower-case method, or null.
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function controllerAliases(string $class): array
    {
        if (isset(self::$aliasMemo[$class])) {
            return self::$aliasMemo[$class];
        }

        $aliases = [];

        try {
            if (!\function_exists('token_get_all')) {
                return self::$aliasMemo[$class] = [];
            }

            $files = [];

            for ($reflection = new \ReflectionClass($class); $reflection && $reflection->getName() !== BaseController::class; $reflection = $reflection->getParentClass() ?: null) {
                $file = $reflection->getFileName();

                if (\is_string($file) && is_file($file)) {
                    array_unshift($files, $file);
                }
            }

            foreach (array_unique($files) as $file) {
                $tokens = array_values(array_filter(
                    token_get_all((string) file_get_contents($file)),
                    static fn ($token): bool => !\is_array($token) || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
                ));

                foreach ($tokens as $i => $token) {
                    if (!\is_array($token) || $token[0] !== T_STRING || ($tokens[$i + 1] ?? null) !== '(') {
                        continue;
                    }

                    $call  = self::asciiLower($token[1]);
                    $first = self::taskNameToken($tokens[$i + 2] ?? null);

                    if ($first === null || $first === '__default') {
                        continue;
                    }

                    if ($call === 'registertask' && ($tokens[$i + 3] ?? null) === ',' && ($tokens[$i + 5] ?? null) === ')') {
                        $second = self::taskNameToken($tokens[$i + 4] ?? null);

                        if ($second !== null) {
                            $aliases[$first] = $second;
                        }
                    } elseif ($call === 'unregistertask' && ($tokens[$i + 3] ?? null) === ')') {
                        $aliases[$first] = null;
                    }
                }
            }
        } catch (\Throwable $e) {
            $aliases = [];
        }

        return self::$aliasMemo[$class] = $aliases;
    }

    /**
     * A task or method name written as a plain quoted string in PHP source
     * ('notify' or "notify"), lower case; null for anything else.
     *
     * @param   mixed  $token  A token_get_all() token.
     *
     * @return  string|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function taskNameToken($token): ?string
    {
        if (!\is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return preg_match('/^([\'"])([A-Za-z0-9_]+)\1$/', $token[1], $m) ? self::asciiLower($m[2]) : null;
    }

    /**
     * The menu item the request was opened under: the router's active item,
     * else the Itemid in the request.
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  object|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function getActiveItem(CMSApplicationInterface $app): ?object
    {
        if (!method_exists($app, 'getMenu')) {
            return null;
        }

        $menu   = $app->getMenu();
        $active = $menu->getActive();

        if (!$active) {
            $itemId = self::getActiveItemId($app);
            $active = $itemId > 0 ? $menu->getItem($itemId) : null;
        }

        return $active && (int) $active->id > 0 ? $active : null;
    }

    /**
     * Is the request exactly the page of the menu item it was opened under
     * (same component, view and id as the item's own link)? Only then does
     * that menu item vouch for the request. Any menu item id can be added to
     * any URL, and Joomla puts links it can't place under the home item.
     *
     * @param   CMSApplicationInterface                      $app      The application.
     * @param   array{option: string, view: ?string, id: int}  $request  From describeRequest().
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function isActiveItemsOwnPage(CMSApplicationInterface $app, array $request): bool
    {
        $active = $request['view'] === null ? null : self::getActiveItem($app);

        if ($active === null) {
            return false;
        }

        $query = (array) ($active->query ?? []);

        if (self::asciiLower(self::queryString($query, 'option')) !== $request['option']) {
            return false;
        }

        $view = self::resolveView(
            $app,
            $request['option'],
            self::queryString($query, 'view'),
            self::queryString($query, 'task'),
            self::queryString($query, 'controller')
        );

        return $view === $request['view'] && self::queryId($query) === $request['id'];
    }

    /**
     * A string value from a menu link's query ('' when missing or not a string).
     *
     * @param   array<string, mixed>  $query  Menu item query.
     * @param   string                $key    Key.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function queryString(array $query, string $key): string
    {
        return \is_scalar($query[$key] ?? null) ? (string) $query[$key] : '';
    }

    /**
     * The id in a menu link's query. com_tags links carry it as a one-entry
     * array (id[0]=8); more than one id can't be a single page (-1).
     *
     * @param   array<string, mixed>  $query  Menu item query.
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function queryId(array $query): int
    {
        $id = $query['id'] ?? 0;

        if (\is_array($id)) {
            if (\count($id) !== 1) {
                return -1;
            }

            $id = reset($id);
        }

        return \is_scalar($id) ? (int) $id : -1;
    }

    /**
     * Is the request for one of the "Also protect these views" views?
     *
     * @param   CMSApplicationInterface                      $app      The application.
     * @param   array{option: string, view: ?string, id: int}  $request  From describeRequest().
     * @param   string[]                                       $views    From getProtectedViews().
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function matchesProtectedView(CMSApplicationInterface $app, array $request, array $views): bool
    {
        if ($request['view'] === null) {
            return false;
        }

        if ($request['view'] !== '') {
            return \in_array($request['option'] . ':' . $request['view'], $views, true);
        }

        // The component's default view: it can't be named, so it counts as
        // any listed view of that component unless it's the menu item's own page.
        foreach ($views as $entry) {
            if (str_starts_with($entry, $request['option'] . ':')) {
                return !self::isActiveItemsOwnPage($app, $request);
            }
        }

        return false;
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

        // Control characters (ASCII 0-31 and 127) or a backslash: refuse.
        if ($url === '' || preg_match('/[[:cntrl:]\\\\]/', $url)) {
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

        $scheme = self::asciiLower((string) ($parts['scheme'] ?? ''));

        if (!\in_array($scheme, ['http', 'https'], true) || self::asciiLower($parts['host']) !== self::asciiLower((string) $root->getHost())) {
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
     * Memoised content targets of page protection.
     *
     * @var    array{articles: int[], ranges: array<int, array{0: int, 1: int}>, contacts: array<int, array{0: int, 1: int}>, contactIds: int[]}|null
     * @since  0.1.0
     */
    private static ?array $contentTargets = null;

    /**
     * Memoised "extension:category id" => [lft, rgt].
     *
     * @var    array<string, array{0: int, 1: int}|null>
     * @since  0.1.0
     */
    private static array $categoryRanges = [];

    /**
     * Content that page protection covers outside its own pages ("selected"
     * mode with "also catch other routes" on): articles with their own
     * protected menu item, everything inside a protected com_content
     * category (ranges), everything inside a protected com_contact category
     * (contacts), and contacts with their own protected menu item
     * (contactIds), for lists, tag lists, modules and feeds.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  array{articles: int[], ranges: array<int, array{0: int, 1: int}>, contacts: array<int, array{0: int, 1: int}>, contactIds: int[]}
     *
     * @since   0.1.0
     */
    public static function getContentTargets(CMSApplicationInterface $app, Registry $params): array
    {
        if (self::$contentTargets !== null) {
            return self::$contentTargets;
        }

        $targets = ['articles' => [], 'ranges' => [], 'contacts' => [], 'contactIds' => []];

        if (
            (string) $params->get('protection_mode', self::MODE_SELECTED) !== self::MODE_SELECTED
            || (int) $params->get('match_content', 1) !== 1
            || !method_exists($app, 'getMenu')
        ) {
            return self::$contentTargets = $targets;
        }

        $categories = ['com_content' => [], 'com_contact' => []];

        foreach (self::getProtectedMenuItemIds($params) as $menuItemId) {
            $item   = $app->getMenu()->getItem($menuItemId);
            $query  = $item ? (array) ($item->query ?? []) : [];
            $option = self::asciiLower(self::queryString($query, 'option'));

            if (!isset($categories[$option])) {
                continue;
            }

            $view = self::normaliseName(self::queryString($query, 'view'));
            $id   = self::queryId($query);

            if ($option === 'com_content' && $view === 'article' && $id > 0) {
                $targets['articles'][] = $id;
            } elseif ($option === 'com_contact' && $view === 'contact' && $id > 0) {
                $targets['contactIds'][] = $id;
            } elseif ($view === 'category' && $id > 0) {
                $categories[$option][] = $id;
            }
        }

        foreach (['com_content' => 'ranges', 'com_contact' => 'contacts'] as $option => $key) {
            foreach (array_unique($categories[$option]) as $categoryId) {
                $range = self::getCategoryRange($categoryId, $option);

                if ($range !== null) {
                    $targets[$key][$categoryId] = $range;
                }
            }
        }

        $targets['articles']   = array_values(array_unique($targets['articles']));
        $targets['contactIds'] = array_values(array_unique($targets['contactIds']));

        return self::$contentTargets = $targets;
    }

    /**
     * Does page protection cover any content outside its own pages?
     *
     * @param   array{articles: int[], ranges: array<int, array{0: int, 1: int}>, contacts: array<int, array{0: int, 1: int}>, contactIds: int[]}  $targets  From getContentTargets().
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function hasContentTargets(array $targets): bool
    {
        return $targets['articles'] !== [] || $targets['ranges'] !== [] || ($targets['contacts'] ?? []) !== [] || ($targets['contactIds'] ?? []) !== [];
    }

    /**
     * Is this contact covered by page protection: its own protected menu
     * item, or inside a protected com_contact category?
     *
     * @param   int    $contactId   Contact id.
     * @param   int    $categoryId  The contact's category id.
     * @param   array{articles: int[], ranges: array<int, array{0: int, 1: int}>, contacts: array<int, array{0: int, 1: int}>, contactIds: int[]}  $targets  From getContentTargets().
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function isProtectedContact(int $contactId, int $categoryId, array $targets): bool
    {
        if ($contactId > 0 && \in_array($contactId, $targets['contactIds'] ?? [], true)) {
            return true;
        }

        if (($targets['contacts'] ?? []) === [] || $categoryId <= 0) {
            return false;
        }

        $range = self::getCategoryRange($categoryId, 'com_contact');

        if ($range === null) {
            return false;
        }

        foreach ($targets['contacts'] as [$lft, $rgt]) {
            if ($lft <= $range[0] && $rgt >= $range[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this com_content article covered by page protection?
     *
     * @param   int  $articleId   Article id.
     * @param   int  $categoryId  The article's category id.
     * @param   array{articles: int[], ranges: array<int, array{0: int, 1: int}>, contacts: array<int, array{0: int, 1: int}>, contactIds: int[]}  $targets  From getContentTargets().
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
     * @param   array{articles: int[], ranges: array<int, array{0: int, 1: int}>, contacts: array<int, array{0: int, 1: int}>, contactIds: int[]}  $targets  From getContentTargets().
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
     * [lft, rgt] of a category, memoised.
     *
     * @param   int     $categoryId  Category id.
     * @param   string  $extension   The category's component.
     *
     * @return  array{0: int, 1: int}|null
     *
     * @since   0.1.0
     */
    private static function getCategoryRange(int $categoryId, string $extension = 'com_content'): ?array
    {
        $key = $extension . ':' . $categoryId;

        if (\array_key_exists($key, self::$categoryRanges)) {
            return self::$categoryRanges[$key];
        }

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select([$db->quoteName('lft'), $db->quoteName('rgt')])
                ->from($db->quoteName('#__categories'))
                ->where($db->quoteName('id') . ' = :id')
                ->where($db->quoteName('extension') . ' = :extension')
                ->bind(':id', $categoryId, ParameterType::INTEGER)
                ->bind(':extension', $extension);

            $row = $db->setQuery($query)->loadObject();

            return self::$categoryRanges[$key] = $row ? [(int) $row->lft, (int) $row->rgt] : null;
        } catch (\Throwable $e) {
            return self::$categoryRanges[$key] = null;
        }
    }

    /**
     * "Every page except…" mode: is this request one of the excluded pages?
     * True only when the request's option / view / id / layout match an
     * excluded menu item's own link, or (com_content) the request is an
     * article or subcategory inside an excluded category. The view is the one
     * Joomla will show (resolveView()), so `task=article.display` is matched
     * like `view=article`.
     *
     * @param   CMSApplicationInterface  $app       The application.
     * @param   int[]                    $excluded  Excluded menu item ids.
     * @param   string                   $option    Request component, lower case.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private static function isExcludedRequest(CMSApplicationInterface $app, array $excluded, string $option): bool
    {
        $excluded = self::itemsOfComponent($app, $excluded, $option);

        if ($excluded === []) {
            return false;
        }

        // A real task (a login or contact form submit, a payment callback)
        // has no page view of its own: it's matched by the raw `view`, which
        // the router fills in from the page it was sent to, so a form sent
        // from an excluded page stays excluded.
        $input   = $app->getInput();
        $details = self::describeRequest($app, $option);
        $request = [
            'view'   => $details['view'] ?? self::normaliseName((string) self::singleValue($input, 'view')),
            'id'     => $details['id'],
            'layout' => (string) self::singleValue($input, 'layout'),
        ];
        $menu       = $app->getMenu();
        $categories = [];

        foreach ($excluded as $menuItemId) {
            $item  = $menu->getItem($menuItemId);
            $query = $item ? (array) ($item->query ?? []) : [];

            if (!$item || self::asciiLower(self::queryString($query, 'option')) !== $option) {
                continue;
            }

            $itemView   = self::normaliseName(self::queryString($query, 'view'));
            $itemId     = self::queryId($query);
            $itemLayout = self::queryString($query, 'layout');

            if ($itemView === $request['view'] && $itemId === $request['id'] && ($itemLayout === '' || $itemLayout === $request['layout'])) {
                return true;
            }

            if ($option === 'com_content' && $itemView === 'category' && $itemId > 0) {
                $categories[] = $itemId;
            }
        }

        if ($categories !== [] && $request['id'] > 0 && \in_array($request['view'], ['article', 'category'], true)) {
            return self::isInsideCategories('com_content', $request['view'], $request['id'], $categories);
        }

        return false;
    }

    /**
     * Catch requests for protected content reached through a different (or
     * forged) Itemid, or none. The view is the one Joomla will show
     * (resolveView()), so `task=contact.display` or `controller=contact` is
     * matched like `view=contact`; a request that runs another task (form
     * submit, payment callback, AJAX) never matches.
     *
     * - same component + view + id as a protected menu item; a protected item
     *   whose link has no id (a list) covers its view whatever the id, unless
     *   the request is exactly another menu item's own page with that id;
     * - an article / contact / news feed (or a subcategory) inside a protected
     *   category of com_content, com_contact or com_newsfeeds, and the
     *   featured-contacts and contact category lists when they'd show a
     *   contact from a protected category or a protected single contact;
     * - any other component: the detail view of a protected list (a protected
     *   "members" list covers "member"), unless the request is exactly the
     *   page of the menu item it was opened under (issue #16);
     * - the component's default view (no view named): any component with a
     *   protected menu item, unless it is exactly that menu item's page.
     *
     * @param   CMSApplicationInterface                        $app         The application.
     * @param   int[]                                          $configured  Protected menu item ids.
     * @param   array{option: string, view: ?string, id: int}  $request     From describeRequest().
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private static function matchesProtectedContent(CMSApplicationInterface $app, array $configured, array $request): bool
    {
        ['option' => $option, 'view' => $view, 'id' => $id] = $request;

        if ($view === null) {
            return false;
        }

        $menu                = $app->getMenu();
        $categoryComponent   = self::CATEGORY_COMPONENTS[$option] ?? null;
        $protectedCategories = [];
        $protectedContacts   = [];
        $detailViews         = [];
        $sameComponent       = false;
        $ownPage             = null;

        foreach ($configured as $menuItemId) {
            $item  = $menu->getItem($menuItemId);
            $query = $item ? (array) ($item->query ?? []) : [];

            if (!$item || self::asciiLower(self::queryString($query, 'option')) !== $option) {
                continue;
            }

            $sameComponent = true;
            $itemView      = self::normaliseName(self::queryString($query, 'view'));
            $itemId        = self::queryId($query);

            if ($view !== '' && $itemView === $view && $itemId === $id) {
                return true;
            }

            // A protected list without an id covers its view whatever id the
            // request adds, except exactly another menu item's own page (a
            // public, filtered copy of the list such as view=members&id=3).
            if ($view !== '' && $itemView === $view && $itemId === 0) {
                $ownPage ??= $id !== 0 && self::isActiveItemsOwnPage($app, $request);

                if (!$ownPage) {
                    return true;
                }
            }

            if ($categoryComponent !== null) {
                if ($itemView === 'category' && $itemId > 0) {
                    $protectedCategories[] = $itemId;
                } elseif ($option === 'com_contact' && $itemView === 'contact' && $itemId > 0) {
                    $protectedContacts[] = $itemId;
                }
            } else {
                $detailViews = array_merge($detailViews, self::detailViewsOf($itemView));
            }
        }

        if (!$sameComponent) {
            return false;
        }

        if ($view === '') {
            return !self::isActiveItemsOwnPage($app, $request);
        }

        if ($categoryComponent !== null) {
            if ($protectedCategories === [] && $protectedContacts === []) {
                return false;
            }

            if (
                $protectedCategories !== []
                && $id > 0
                && \in_array($view, [$categoryComponent['view'], 'category'], true)
                && self::isInsideCategories($option, $view, $id, $protectedCategories)
            ) {
                return true;
            }

            if ($option === 'com_contact' && \in_array($view, ['featured', 'category'], true)) {
                return self::contactListIncludesProtected($app, $view, $id, $protectedCategories, $protectedContacts);
            }

            return false;
        }

        if (!\in_array($view, $detailViews, true)) {
            return false;
        }

        return !self::isActiveItemsOwnPage($app, $request);
    }

    /**
     * Would this contact list show a protected contact (one inside a
     * protected category, or one with its own protected menu item)? The
     * featured-contacts list shows featured contacts from every category; a
     * contact category list shows its own contacts and those of its
     * subcategories, as deep as its "Subcategory Levels" setting (maxLevel).
     * Fails closed.
     *
     * @param   CMSApplicationInterface  $app                  The application.
     * @param   string                   $view                 'featured' or 'category'.
     * @param   int                      $categoryId           Request id (category lists).
     * @param   int[]                    $protectedCategories  Protected com_contact category ids.
     * @param   int[]                    $protectedContacts    Contact ids with their own protected menu item.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function contactListIncludesProtected(CMSApplicationInterface $app, string $view, int $categoryId, array $protectedCategories, array $protectedContacts = []): bool
    {
        try {
            $scope = [];
            $db    = Factory::getContainer()->get(DatabaseInterface::class);

            if ($protectedContacts !== []) {
                $scope[] = $db->quoteName('a.id') . ' IN (' . implode(',', array_map('intval', array_unique($protectedContacts))) . ')';
            }

            foreach (array_unique($protectedCategories) as $protectedId) {
                $range = self::getCategoryRange($protectedId, 'com_contact');

                if ($range !== null) {
                    $scope[] = '(' . $db->quoteName('c.lft') . ' >= ' . (int) $range[0] . ' AND ' . $db->quoteName('c.rgt') . ' <= ' . (int) $range[1] . ')';
                }
            }

            if ($scope === []) {
                return false;
            }

            $query = $db->createQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__contact_details', 'a'))
                ->join('INNER', $db->quoteName('#__categories', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid'))
                ->where($db->quoteName('a.published') . ' = 1')
                ->where('(' . implode(' OR ', $scope) . ')');

            if ($view === 'featured') {
                $query->where($db->quoteName('a.featured') . ' = 1');
            } else {
                $levels = method_exists($app, 'getParams') ? (int) $app->getParams('com_contact')->get('maxLevel', 1) : -1;

                if ($categoryId <= 0) {
                    return false;
                }

                $parent = $db->setQuery(
                    $db->createQuery()
                        ->select([$db->quoteName('lft'), $db->quoteName('rgt'), $db->quoteName('level')])
                        ->from($db->quoteName('#__categories'))
                        ->where($db->quoteName('id') . ' = :id')
                        ->where($db->quoteName('extension') . ' = ' . $db->quote('com_contact'))
                        ->bind(':id', $categoryId, ParameterType::INTEGER)
                )->loadObject();

                if (!$parent) {
                    return false;
                }

                // The category itself and its subcategories (0 levels: only itself).
                $query->where($db->quoteName('c.lft') . ' >= ' . (int) $parent->lft)
                    ->where($db->quoteName('c.rgt') . ' <= ' . (int) $parent->rgt);

                if ($levels >= 0) {
                    $query->where($db->quoteName('c.level') . ' <= ' . ((int) $parent->level + $levels));
                }
            }

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (\Throwable $e) {
            // Can't tell: treat the list as showing a protected contact.
            return true;
        }
    }

    /**
     * Likely names of the detail view that belongs to a list view: the
     * singular ("members" -> "member", "entries" -> "entry", "boxes" -> "box").
     * Extra guesses that aren't a real view never match a request. Only
     * regular plurals are covered: K2 (itemlist -> item), Community Builder
     * (userslist -> userprofile) and the like need "Also protect these views".
     *
     * @param   string  $listView  The protected menu item's view, lower case.
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function detailViewsOf(string $listView): array
    {
        $views = [];

        if (\strlen($listView) > 3 && str_ends_with($listView, 'ies')) {
            $views[] = substr($listView, 0, -3) . 'y';
        }

        if (\strlen($listView) > 2 && str_ends_with($listView, 'es')) {
            $views[] = substr($listView, 0, -2);
        }

        if (\strlen($listView) > 1 && str_ends_with($listView, 's') && !str_ends_with($listView, 'ss')) {
            $views[] = substr($listView, 0, -1);
        }

        return $views;
    }

    /**
     * Is the item / category inside (or equal to) one of the given categories
     * of a category component (com_content, com_contact, com_newsfeeds)? Uses
     * the nested-set lft/rgt columns.
     *
     * @param   string  $option       Component, a CATEGORY_COMPONENTS key.
     * @param   string  $view         The component's item view ('article', 'contact', 'newsfeed') or 'category'.
     * @param   int     $id           Item or category id.
     * @param   int[]   $categoryIds  Protected category ids.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private static function isInsideCategories(string $option, string $view, int $id, array $categoryIds): bool
    {
        $component = self::CATEGORY_COMPONENTS[$option] ?? null;

        if ($component === null) {
            return false;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            if ($view === $component['view']) {
                $query = $db->createQuery()
                    ->select([$db->quoteName('c.lft'), $db->quoteName('c.rgt')])
                    ->from($db->quoteName($component['table'], 'a'))
                    ->join('INNER', $db->quoteName('#__categories', 'c'), $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid'))
                    ->where($db->quoteName('a.id') . ' = :id')
                    ->bind(':id', $id, ParameterType::INTEGER);
            } else {
                $query = $db->createQuery()
                    ->select([$db->quoteName('lft'), $db->quoteName('rgt')])
                    ->from($db->quoteName('#__categories'))
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('extension') . ' = :extension')
                    ->bind(':id', $id, ParameterType::INTEGER)
                    ->bind(':extension', $option);
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
