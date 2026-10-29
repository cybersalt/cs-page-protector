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
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Log\Log;
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
     * The view of a request whose page can't be worked out: a task the
     * controller's code doesn't let us map to a method, or a task method that
     * renders something other than a plain view. Matched like the component's
     * default view (any protected item of the component), and no menu item
     * ever vouches for it.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    private const UNKNOWN_VIEW = '?';

    /**
     * Core components whose site pages pick their record by `id` alone (com_tags
     * by a list of ids). Every other component may pick it by another key too
     * (see RECORD_KEY_PATTERN).
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const ID_ONLY_COMPONENTS = ['com_content', 'com_contact', 'com_newsfeeds', 'com_tags'];

    /**
     * Request keys that may pick a record: id, *_id, cid, uid, user_id, slug,
     * alias, username and the like. Never Itemid (the menu item) or utm_*
     * campaign tags.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    private const RECORD_KEY_PATTERN = '/^(?:id|ids|cid|uid|pid|aid|a_id|catid|slug|alias|username|user|userid|memberid|profileid|productid|itemid|articleid|eventid|orderid|postid|uuid|guid)$|_(?:id|ids|uid|uuid|slug|alias|username)$|^id_/i';

    /**
     * BaseController methods that change the task map.
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const TASK_CALLS = ['registertask', 'unregistertask', 'registerdefaulttask'];

    /**
     * Calls that show a page: a controller's display(), a view object's
     * display(), and getting a view object (getView(), createView()).
     * render() is left out: it also builds emails and JSON answers (core
     * com_contact renders its custom fields into the contact email).
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const RENDER_CALLS = ['display', 'getview', 'createview'];

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
            try {
                self::$protectedMemo = self::computeProtected($app, $params);
            } catch (\Throwable $e) {
                // Can't tell: protected (fail closed). The challenge page and
                // com_ajax return before anything that can throw.
                self::$protectedMemo = $app->isClient('site');

                try {
                    Log::add('cs-page-protector could not classify a request (checked): ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
                } catch (\Throwable $ignored) {
                    // Nothing more to do.
                }
            }
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
        // component with something protected counts (below). com_tags reads
        // its `id` as a list of tags (its menu items carry id[0]=N, which
        // strict routing sends on), so a readable com_tags id list is matched
        // tag by tag instead (see tagIds()).
        $arrays    = self::arrayKeys($input, $option);
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
     * The request keys (of SINGLE_VALUE_KEYS) sent as an array. A com_tags
     * `id` list that can be read (see tagIds()) doesn't count.
     *
     * @param   Input        $input   The request input.
     * @param   string|null  $option  Request component, lower case (null: an array).
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function arrayKeys(Input $input, ?string $option): array
    {
        $arrays = [];

        foreach (self::SINGLE_VALUE_KEYS as $key) {
            // com_tags: a list (or one value) every reader takes the same way.
            if ($key === 'id' && $option === 'com_tags') {
                if (self::tagIds($input->get('id', null, 'raw')) === null) {
                    $arrays[] = $key;
                }

                continue;
            }

            if (self::singleValue($input, $key) === null) {
                $arrays[] = $key;
            }
        }

        return $arrays;
    }

    /**
     * The tag ids a com_tags `id` value names, the way com_tags reads it
     * (TagModel: a list, one entry may hold several ids separated by commas,
     * "6:alias" counts as 6, entries without a number are dropped). Null
     * when a part can't be read the same way by every reader (a nested array,
     * "6e1", "+6"): such a request is treated as protected.
     *
     * @param   mixed  $value  The raw `id` value (request or menu link).
     *
     * @return  int[]|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function tagIds($value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = \is_array($value) ? array_values($value) : [$value];

        if (\count($values) === 1 && \is_scalar($values[0])) {
            $values = explode(',', (string) $values[0]);
        }

        $ids = [];

        foreach ($values as $entry) {
            if (!\is_scalar($entry)) {
                return null;
            }

            $entry = trim((string) $entry);

            if (preg_match('/^(\d{1,18})(?::[^,]*)?$/', $entry, $m)) {
                if ((int) $m[1] > 0) {
                    $ids[] = (int) $m[1];
                }

                continue;
            }

            if ($entry === '' || !preg_match('/\d/', $entry)) {
                continue;
            }

            return null;
        }

        return array_values(array_unique($ids));
    }

    /**
     * The record ids a menu link names: its `id` (com_tags: its list of
     * tags). Empty when the link has none (a list); [-1] when it can't be
     * read (matches nothing, and never vouches).
     *
     * @param   string                $option  Component, lower case.
     * @param   array<string, mixed>  $query   Menu item query.
     *
     * @return  int[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function linkIds(string $option, array $query): array
    {
        if ($option === 'com_tags') {
            return self::tagIds($query['id'] ?? null) ?? [-1];
        }

        $id = self::queryId($query);

        return $id === 0 ? [] : [$id];
    }

    /**
     * The record ids a request names (see describeRequest()).
     *
     * @param   array<string, mixed>  $request  From describeRequest().
     *
     * @return  int[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function requestIds(array $request): array
    {
        if (\is_array($request['ids'] ?? null)) {
            return $request['ids'];
        }

        return (int) $request['id'] === 0 ? [] : [(int) $request['id']];
    }

    /**
     * Do two id lists name exactly the same records?
     *
     * @param   int[]  $a  Ids.
     * @param   int[]  $b  Ids.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function sameIds(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * Is this request key one that may pick a record (see RECORD_KEY_PATTERN)?
     *
     * @param   string  $name  Request or menu link key.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function isRecordKey(string $name): bool
    {
        return $name !== 'Itemid' && !preg_match('/^utm_/i', $name) && preg_match(self::RECORD_KEY_PATTERN, $name) === 1;
    }

    /**
     * The keys other than `id` that may pick a record, with their raw values.
     * Core components that pick their record by `id` alone have none.
     *
     * @param   string                $option  Component, lower case.
     * @param   array<string, mixed>  $values  Menu link query or request data.
     *
     * @return  array<string, mixed>
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function recordKeys(string $option, array $values): array
    {
        if (\in_array($option, self::ID_ONLY_COMPONENTS, true)) {
            return [];
        }

        $keys = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;

            if ($name !== 'id' && self::isRecordKey($name)) {
                $keys[$name] = $value;
            }
        }

        return $keys;
    }

    /**
     * The record keys of the request (see recordKeys()).
     *
     * @param   Input   $input   The request input.
     * @param   string  $option  Request component, lower case.
     *
     * @return  array<string, mixed>
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function requestRecordKeys(Input $input, string $option): array
    {
        if (\in_array($option, self::ID_ONLY_COMPONENTS, true)) {
            return [];
        }

        $values = [];

        foreach (array_keys((array) $input->getArray()) as $name) {
            $name = (string) $name;

            if ($name !== 'id' && self::isRecordKey($name)) {
                $values[$name] = $input->get($name, null, 'raw');
            }
        }

        return $values;
    }

    /**
     * Does the key hold a numeric id (id, *_id, cid, catid, userid and the like)?
     *
     * @param   string  $name  Key.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function isIdKey(string $name): bool
    {
        return preg_match('/(?:id|ids)$|^id_/i', $name) === 1;
    }

    /**
     * A clean numeric id: "5", or a "5:alias" slug. Null for anything else.
     *
     * @param   string  $value  Trimmed value.
     *
     * @return  integer|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function cleanId(string $value): ?int
    {
        return preg_match('/^(\d{1,18})(?::.*)?$/s', $value, $m) ? (int) $m[1] : null;
    }

    /**
     * Are two record key values certainly the same record? Strict: used
     * before a menu item may vouch for a request or exclude it.
     *
     * @param   string  $key  Key name.
     * @param   mixed   $a    Value.
     * @param   mixed   $b    Value.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function sameValue(string $key, $a, $b): bool
    {
        if (!\is_scalar($a) || !\is_scalar($b)) {
            return false;
        }

        $a = trim((string) $a);
        $b = trim((string) $b);

        if ($a === '' || $b === '') {
            return false;
        }

        if (self::isIdKey($key)) {
            $id = self::cleanId($a);

            return $id !== null && $id === self::cleanId($b);
        }

        return $a === $b;
    }

    /**
     * Could two record key values be the same record? Loose: false only when
     * they are certainly different (two clean numbers, or two plain ASCII
     * words that differ in more than case). Used to match protected records.
     *
     * @param   string  $key  Key name.
     * @param   mixed   $a    Value.
     * @param   mixed   $b    Value.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function mayBeSame(string $key, $a, $b): bool
    {
        if (!\is_scalar($a) || !\is_scalar($b)) {
            return true;
        }

        $a = trim((string) $a);
        $b = trim((string) $b);

        if (self::isIdKey($key)) {
            $x = self::cleanId($a);
            $y = self::cleanId($b);

            return $x === null || $y === null || $x === $y;
        }

        if (preg_match('/^[A-Za-z0-9_-]+$/', $a) && preg_match('/^[A-Za-z0-9_-]+$/', $b)) {
            return self::asciiLower($a) === self::asciiLower($b);
        }

        return true;
    }

    /**
     * The parts of the request the rules match on: component, the view
     * Joomla will show (see resolveView()), id, the com_tags id list, and the
     * other keys that may pick a record (third-party components).
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   string                   $option  Request component, lower case.
     *
     * @return  array{option: string, view: ?string, id: int, ids: int[]|null, keys: array<string, mixed>}
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function describeRequest(CMSApplicationInterface $app, string $option): array
    {
        $input = $app->getInput();
        $ids   = $option === 'com_tags' ? (self::tagIds($input->get('id', null, 'raw')) ?? [-1]) : null;

        return [
            'option' => $option,
            'view'   => self::resolveView(
                $app,
                $option,
                (string) self::singleValue($input, 'view'),
                (string) self::singleValue($input, 'task'),
                (string) self::singleValue($input, 'controller')
            ),
            'id'     => $ids !== null ? (\count($ids) === 1 ? $ids[0] : 0) : (int) (self::singleValue($input, 'id', 'int', '0') ?? 0),
            'ids'    => $ids,
            'keys'   => self::requestRecordKeys($input, $option),
        ];
    }

    /**
     * The view a request shows, worked out the way Joomla picks it, so a
     * request can't step around a view rule by leaving `view` out:
     *
     * - ComponentDispatcher runs `task=<controller>.<method>` (or
     *   `controller=` plus `task=<method>`). BaseController::execute() looks
     *   the task up in the controller's task map (its public methods plus the
     *   task aliases its constructor registers, see taskTarget()) and falls
     *   back to the default task, display();
     * - a task that runs a method other than display() is a real task (a
     *   form submit, a payment callback, an AJAX call) unless the method
     *   renders a page itself (see methodRendering()): then the request is
     *   that page;
     * - display() shows `view`, or with no `view` the view named after the
     *   controller.
     *
     * The result is null for a real task that shows no page of its own, so
     * the view rules never touch those. It is '' when the page is the
     * component's own default view, when a named controller has its own
     * display() (it may show any view, whatever `view` says), or for a
     * component whose controllers can't be read (older, non-namespaced
     * components): the rules then treat the request as any view of that
     * component, unless it is exactly the page of the menu item it was
     * opened under. It is UNKNOWN_VIEW when the task can't be mapped to a
     * method, or the method renders something that can't be named: treated
     * as any view of that component, and no menu item vouches for it.
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

        if ($class !== null && $method !== 'display') {
            // The method BaseController::execute() will run for this task.
            $target = self::taskTarget($app, $option, $class, $named ? $controller : '', $method);

            if ($target === null) {
                return self::UNKNOWN_VIEW;
            }

            if ($target !== 'display') {
                $rendering = self::methodRendering($class, $target);

                // A real task: the router fills `view` in from the menu item,
                // so a form submit or a payment callback often carries one.
                if ($rendering === null) {
                    return null;
                }

                if ($rendering === self::UNKNOWN_VIEW) {
                    return self::UNKNOWN_VIEW;
                }

                // The method sets a view and calls display().
                if ($rendering !== '') {
                    return $custom && self::hasOwnDisplay($class) ? self::UNKNOWN_VIEW : $rendering;
                }

                // It calls display() with the request's view: as below.
            }
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
     * The component MVC factories found by controllerClass(), keyed by option.
     *
     * @var    array<string, MVCFactory>
     * @since  __DEPLOY_VERSION__
     */
    private static array $factories = [];

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
                            $class                    = $candidate;
                            self::$factories[$option] = $factory;
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
     * inside the class). Null when they can't be read.
     *
     * @return  string[]|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function baseControllerMethods(): ?array
    {
        if (self::$baseControllerMethods !== null) {
            return self::$baseControllerMethods;
        }

        try {
            $reader = \Closure::bind(static fn (): array => get_class_methods(BaseController::class), null, BaseController::class);

            if (!$reader instanceof \Closure) {
                return null;
            }

            $methods = $reader();
        } catch (\Throwable $e) {
            return null;
        }

        return \is_array($methods) && $methods !== [] ? self::$baseControllerMethods = $methods : null;
    }

    /**
     * Memoised baseControllerMethods() result.
     *
     * @var    string[]|null
     * @since  __DEPLOY_VERSION__
     */
    private static ?array $baseControllerMethods = null;

    /**
     * The method (lower case) BaseController::execute() will run for this
     * task of this controller, or null when that can't be told for certain.
     *
     * The task map is first read from the source (see readTaskMap()). When a
     * line of the source that could change this task's entry can't be read
     * with certainty (a conditional or non-literal registerTask(), one made
     * outside the constructor, and the like), the controller is built through
     * the component's MVC factory, as the dispatcher will build it, and its
     * real task map is used (see liveTaskMap()). Null when neither works: the
     * request is then treated as a page of unknown view (fail closed).
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   string                   $option  Component, lower case.
     * @param   string                   $class   Controller class.
     * @param   string                   $name    Controller name from the request ('' for the DisplayController).
     * @param   string                   $task    Task (the method part), lower case.
     *
     * @return  string|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function taskTarget(CMSApplicationInterface $app, string $option, string $class, string $name, string $task): ?string
    {
        $read = self::staticTaskMap($class);

        if ($read !== null && !$read['all'] && !\in_array($task, $read['unsure'], true)) {
            if (\array_key_exists($task, $read['map'])) {
                return $read['map'][$task];
            }

            if (!\in_array('__default', $read['unsure'], true)) {
                return $read['map']['__default'] ?? null;
            }
        }

        $live = self::liveTaskMap($app, $option, $class, $name, $task);

        if ($live === null) {
            return null;
        }

        return $live[$task] ?? $live['__default'] ?? null;
    }

    /**
     * Memoised readTaskMap() results, keyed by class.
     *
     * @var    array<string, array{map: array<string, string>, unsure: string[], all: bool}|null>
     * @since  __DEPLOY_VERSION__
     */
    private static array $taskMaps = [];

    /**
     * readTaskMap(), memoised, null on any failure.
     *
     * @param   string  $class  Controller class.
     *
     * @return  array{map: array<string, string>, unsure: string[], all: bool}|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function staticTaskMap(string $class): ?array
    {
        if (\array_key_exists($class, self::$taskMaps)) {
            return self::$taskMaps[$class];
        }

        try {
            $base   = self::baseControllerMethods();
            $result = $base === null ? null : self::readTaskMap($class, $base);
        } catch (\Throwable $e) {
            $result = null;
        }

        return self::$taskMaps[$class] = $result;
    }

    /**
     * The task map a controller builds, read from the source of the class and
     * its parents up to BaseController, without running any of it:
     *
     * - every public method (minus BaseController's own, except display()),
     *   lower case, maps to itself, and __default to display(), as
     *   BaseController's constructor does;
     * - then, parents first, each constructor's `$this->registerTask('a',
     *   'b')`, `unregisterTask('a')` and `registerDefaultTask('b')` with
     *   plain quoted names, written as a statement of its own at the top
     *   level of __construct(), after `parent::__construct(...)` and before
     *   any `return` (chained calls count too). registerTask() only takes a
     *   method the controller has, as Joomla checks.
     *
     * Anything else that can change the map leaves it unsure: such a call
     * anywhere else in the files (inside an if, a loop, a try, a closure,
     * another method, a trait) makes its task "unsure" when its first name
     * is plain (registerDefaultTask: the default task), and the whole map
     * ("all") when it isn't; so do a write to taskMap, the names of those
     * methods inside strings, dynamic calls (`$this->$name()`,
     * call_user_func()), a constructor without a top-level
     * `parent::__construct()` or one using the alternative if/endif syntax,
     * and a `default_task` setting (the default task).
     *
     * @param   string    $class  Controller class.
     * @param   string[]  $base   BaseController's own methods.
     *
     * @return  array{map: array<string, string>, unsure: string[], all: bool}|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function readTaskMap(string $class, array $base): ?array
    {
        $reflection = new \ReflectionClass($class);
        $methods    = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($name === 'display' || !\in_array($name, $base, true)) {
                $methods[] = self::asciiLower($name);
            }
        }

        $map     = ['__default' => 'display'] + (array_combine($methods, $methods) ?: []);
        $unsure  = [];
        $all     = false;
        $trusted = [];
        $chain   = [];
        $files   = [];

        for ($r = $reflection; $r && $r->getName() !== BaseController::class; $r = $r->getParentClass() ?: null) {
            array_unshift($chain, $r);
        }

        $addFiles = static function (\ReflectionClass $r) use (&$addFiles, &$files): void {
            $file = $r->getFileName();

            if (\is_string($file)) {
                $files[$file] = true;
            }

            foreach ($r->getTraits() as $trait) {
                $addFiles($trait);
            }
        };

        foreach ($chain as $r) {
            $addFiles($r);
        }

        foreach ($chain as $r) {
            if (!$r->hasMethod('__construct')) {
                continue;
            }

            $constructor = $r->getMethod('__construct');

            if ($constructor->getDeclaringClass()->getName() !== $r->getName()) {
                continue;
            }

            $file   = $constructor->getFileName();
            $tokens = \is_string($file) && $file === $r->getFileName() ? self::fileTokens($file) : null;
            $calls  = $tokens === null ? null : self::constructorTaskCalls($tokens, (int) $constructor->getStartLine(), (int) $constructor->getEndLine());

            if ($calls === null) {
                $all = true;

                continue;
            }

            foreach ($calls['positions'] as $position) {
                $trusted[$file . '#' . $position] = true;
            }

            foreach ($calls['calls'] as [$call, $first, $second]) {
                if ($call === 'unregistertask') {
                    unset($map[$first]);
                } else {
                    $task   = $call === 'registerdefaulttask' ? '__default' : $first;
                    $target = $call === 'registerdefaulttask' ? $first : (string) $second;

                    if (\in_array($target, $methods, true)) {
                        $map[$task] = $target;
                    }
                }
            }
        }

        foreach (array_keys($files) as $file) {
            $tokens = self::fileTokens($file);

            if ($tokens === null) {
                $all = true;

                continue;
            }

            foreach ($tokens as $i => $token) {
                if (!\is_array($token)) {
                    continue;
                }

                $text  = self::asciiLower($token[1]);
                $next  = $tokens[$i + 1] ?? null;
                $after = $tokens[$i + 2] ?? null;

                if (\in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    if (preg_match('/registertask|unregistertask|registerdefaulttask|taskmap/', $text)) {
                        $all = true;
                    } elseif (str_contains($text, 'default_task')) {
                        $unsure[] = '__default';
                    }

                    continue;
                }

                if ($token[0] === T_VARIABLE && $text === '$taskmap') {
                    $all = true;

                    continue;
                }

                // Dynamic method calls: ->$name(…), ->{…}(…).
                if (\in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    if ($next === '{' || (\is_array($next) && $next[0] === T_VARIABLE && $after === '(')) {
                        $all = true;
                    }

                    continue;
                }

                if ($token[0] !== T_STRING) {
                    continue;
                }

                if ($text === 'taskmap') {
                    $all = true;

                    continue;
                }

                if ($text === 'default_task') {
                    $unsure[] = '__default';

                    continue;
                }

                if ($next !== '(') {
                    continue;
                }

                if (\in_array($text, ['call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array'], true)) {
                    $all = true;

                    continue;
                }

                if (!\in_array($text, self::TASK_CALLS, true) || isset($trusted[$file . '#' . $i])) {
                    continue;
                }

                if ($text === 'registerdefaulttask') {
                    $unsure[] = '__default';

                    continue;
                }

                $first = self::taskNameToken($after, true);

                if ($first === null) {
                    $all = true;
                } else {
                    $unsure[] = $first;
                }
            }
        }

        return ['map' => $map, 'unsure' => array_values(array_unique($unsure)), 'all' => $all];
    }

    /**
     * The task calls a constructor makes for certain (see readTaskMap()), in
     * order, with the token positions of their method names. Null when the
     * constructor can't be read, has no top-level `parent::__construct()`,
     * or uses the alternative (endif) syntax.
     *
     * @param   array  $tokens     fileTokens() of the file.
     * @param   int    $startLine  First line of the constructor.
     * @param   int    $endLine    Last line of the constructor.
     *
     * @return  array{calls: list<array{0: string, 1: string, 2: ?string}>, positions: int[]}|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function constructorTaskCalls(array $tokens, int $startLine, int $endLine): ?array
    {
        $body = self::functionBody($tokens, '__construct', $startLine, $endLine);

        if ($body === null) {
            return null;
        }

        [$open, $close] = $body;

        $calls     = [];
        $positions = [];
        $parent    = false;
        $returned  = false;
        $depth     = 0;
        $parens    = 0;
        $start     = $open + 1;

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];
            $id    = \is_array($token) ? $token[0] : $token;

            if (\in_array($id, [T_ENDIF, T_ENDWHILE, T_ENDFOR, T_ENDFOREACH, T_ENDSWITCH, T_ENDDECLARE], true)) {
                return null;
            }

            if ($id === T_RETURN || $id === T_GOTO) {
                $returned = true;
            }

            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                continue;
            }

            if ($id === '}') {
                $depth--;

                // A block statement at the top level ends here, unless it goes on (else, catch…).
                if ($depth === 0 && $parens === 0) {
                    $next = $tokens[$i + 1] ?? null;

                    if (!\is_array($next) || !\in_array($next[0], [T_ELSE, T_ELSEIF, T_CATCH, T_FINALLY, T_WHILE], true)) {
                        $start = $i + 1;
                    }
                }

                continue;
            }

            if ($id === '(' || $id === '[' || $id === T_ATTRIBUTE) {
                $parens++;
            } elseif ($id === ')' || $id === ']') {
                $parens--;
            }

            if ($depth !== 0 || $parens !== 0 || $id !== ';') {
                continue;
            }

            $statement = \array_slice($tokens, $start, $i - $start, true);
            $start     = $i + 1;

            if (self::isParentConstructCall($statement)) {
                $parent = true;

                continue;
            }

            if (!$parent || $returned) {
                continue;
            }

            $chain = self::taskCallChain($statement);

            if ($chain !== null) {
                foreach ($chain as [$position, $call, $first, $second]) {
                    $calls[]     = [$call, $first, $second];
                    $positions[] = $position;
                }
            }
        }

        return $parent ? ['calls' => $calls, 'positions' => $positions] : null;
    }

    /**
     * Is this statement exactly `parent::__construct(…)`?
     *
     * @param   array  $statement  Tokens, keyed by position.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function isParentConstructCall(array $statement): bool
    {
        $t = array_values($statement);

        return \count($t) >= 5
            && \is_array($t[0]) && $t[0][0] === T_STRING && self::asciiLower($t[0][1]) === 'parent'
            && \is_array($t[1]) && $t[1][0] === T_DOUBLE_COLON
            && \is_array($t[2]) && $t[2][0] === T_STRING && self::asciiLower($t[2][1]) === '__construct'
            && $t[3] === '(' && end($t) === ')';
    }

    /**
     * A statement that is only `$this->registerTask('a', 'b')` (or
     * unregisterTask('a'), registerDefaultTask('b')), possibly chained, with
     * plain quoted names. Null for anything else.
     *
     * @param   array  $statement  Tokens, keyed by position.
     *
     * @return  list<array{0: int, 1: string, 2: string, 3: ?string}>|null  [position of the name, call, first, second]
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function taskCallChain(array $statement): ?array
    {
        $positions = array_keys($statement);
        $t         = array_values($statement);
        $count     = \count($t);

        if ($count < 5 || !\is_array($t[0]) || $t[0][0] !== T_VARIABLE || $t[0][1] !== '$this') {
            return null;
        }

        $calls = [];
        $k     = 1;

        while ($k < $count) {
            if (!\is_array($t[$k]) || $t[$k][0] !== T_OBJECT_OPERATOR || !\is_array($t[$k + 1] ?? null) || $t[$k + 1][0] !== T_STRING || ($t[$k + 2] ?? null) !== '(') {
                return null;
            }

            $call  = self::asciiLower($t[$k + 1][1]);
            $first = self::taskNameToken($t[$k + 3] ?? null, $call !== 'registerdefaulttask');

            if (!\in_array($call, self::TASK_CALLS, true) || $first === null) {
                return null;
            }

            if ($call === 'registertask') {
                $second = ($t[$k + 4] ?? null) === ',' ? self::taskNameToken($t[$k + 5] ?? null, false) : null;

                if ($second === null || ($t[$k + 6] ?? null) !== ')') {
                    return null;
                }

                $calls[] = [$positions[$k + 1], $call, $first, $second];
                $k += 7;
            } else {
                if (($t[$k + 4] ?? null) !== ')') {
                    return null;
                }

                $calls[] = [$positions[$k + 1], $call, $first, null];
                $k += 5;
            }
        }

        return $calls;
    }

    /**
     * A task or method name written as a plain quoted string in PHP source
     * ('notify' or "notify"), lower case; null for anything else.
     *
     * @param   mixed    $token  A token_get_all() token.
     * @param   boolean  $task   A task name (may also hold "." and "-"), else a method name.
     *
     * @return  string|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function taskNameToken($token, bool $task): ?string
    {
        if (!\is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $pattern = $task ? '/^([\'"])([A-Za-z0-9_.\-]+)\1$/' : '/^([\'"])([A-Za-z0-9_]+)\1$/';

        return preg_match($pattern, $token[1], $m) ? self::asciiLower($m[2]) : null;
    }

    /**
     * Memoised fileTokens() results, keyed by file.
     *
     * @var    array<string, array|null>
     * @since  __DEPLOY_VERSION__
     */
    private static array $fileTokens = [];

    /**
     * token_get_all() of a PHP file without whitespace and comments,
     * memoised; null when it can't be read.
     *
     * @param   string  $file  File path.
     *
     * @return  array|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function fileTokens(string $file): ?array
    {
        if (\array_key_exists($file, self::$fileTokens)) {
            return self::$fileTokens[$file];
        }

        $tokens = null;

        try {
            if (\function_exists('token_get_all') && is_file($file)) {
                $code = file_get_contents($file);

                if (\is_string($code)) {
                    $tokens = [];

                    foreach (token_get_all($code) as $token) {
                        if (!\is_array($token) || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            $tokens[] = $token;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $tokens = null;
        }

        return self::$fileTokens[$file] = $tokens;
    }

    /**
     * The body of a function: the positions of its opening and closing
     * braces, found by name between the lines reflection gives. Null when
     * it can't be found (or has no body).
     *
     * @param   array   $tokens     fileTokens() of the file.
     * @param   string  $name       Function name.
     * @param   int     $startLine  First line.
     * @param   int     $endLine    Last line.
     *
     * @return  array{0: int, 1: int}|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function functionBody(array $tokens, string $name, int $startLine, int $endLine): ?array
    {
        $name  = self::asciiLower($name);
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!\is_array($token) || $token[0] !== T_FUNCTION || $token[2] < $startLine || $token[2] > $endLine) {
                continue;
            }

            $k = $i + 1;

            if (($tokens[$k] ?? null) === '&') {
                $k++;
            }

            if (!\is_array($tokens[$k] ?? null) || self::asciiLower($tokens[$k][1]) !== $name) {
                continue;
            }

            // Skip the parameter list (default values may hold parentheses).
            $parens = 0;

            for ($k++; $k < $count; $k++) {
                if ($tokens[$k] === '(') {
                    $parens++;
                } elseif ($tokens[$k] === ')') {
                    $parens--;

                    if ($parens === 0) {
                        break;
                    }
                }
            }

            for ($k++; $k < $count && $tokens[$k] !== '{'; $k++) {
                if ($tokens[$k] === ';') {
                    return null;
                }
            }

            $depth = 0;

            for ($j = $k; $j < $count; $j++) {
                $id = \is_array($tokens[$j]) ? $tokens[$j][0] : $tokens[$j];

                if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                } elseif ($id === '}') {
                    $depth--;

                    if ($depth === 0) {
                        return [$k, $j];
                    }
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Memoised liveTaskMap() results, keyed "class|task".
     *
     * @var    array<string, array<string, string>|null>
     * @since  __DEPLOY_VERSION__
     */
    private static array $liveTaskMaps = [];

    /**
     * The real task map of the controller, for a task its source leaves
     * unsure: the controller is built through the component's MVC factory
     * the way ComponentDispatcher will build it for this request (same
     * input, with `controller` and `task` split as the dispatcher splits
     * them), then its taskMap is read. It is given its own copy of the
     * input, its base path is set (JPATH_COMPONENT isn't defined yet when
     * this runs), and anything it prints is thrown away. Null when it can't
     * be built or read: the request is then a page of unknown view.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   string                   $option  Component, lower case.
     * @param   string                   $class   Controller class.
     * @param   string                   $name    Controller name from the request ('' for the DisplayController).
     * @param   string                   $task    Task, lower case.
     *
     * @return  array<string, string>|null  Lower-case task => lower-case method.
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function liveTaskMap(CMSApplicationInterface $app, string $option, string $class, string $name, string $task): ?array
    {
        $key = $class . '|' . $task;

        if (\array_key_exists($key, self::$liveTaskMaps)) {
            return self::$liveTaskMaps[$key];
        }

        $map   = null;
        $level = ob_get_level();

        try {
            $factory = self::$factories[$option] ?? null;
            $input   = $app->getInput();

            if ($factory instanceof MVCFactory && $input instanceof Input && \defined('JPATH_SITE')) {
                $data      = (new \ReflectionProperty(Input::class, 'data'))->getValue($input);
                $inputType = \get_class($input);
                $copy      = new $inputType(\is_array($data) ? $data : []);
                $config    = ['option' => $option, 'base_path' => JPATH_SITE . '/components/' . $option];

                $copy->set('task', $task);

                if ($name !== '') {
                    $copy->set('controller', $name);
                    $config['name'] = $name;
                }

                ob_start();

                $controller = $factory->createController($name !== '' ? $name : 'display', 'Site', $config, $app, $copy);

                if ($controller instanceof BaseController && \get_class($controller) === $class) {
                    $taskMap = (new \ReflectionProperty(BaseController::class, 'taskMap'))->getValue($controller);

                    if (\is_array($taskMap)) {
                        $map = [];

                        foreach ($taskMap as $alias => $method) {
                            if (\is_string($method)) {
                                $map[self::asciiLower((string) $alias)] = self::asciiLower($method);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $map = null;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return self::$liveTaskMaps[$key] = $map;
    }

    /**
     * Memoised methodRendering() results, keyed "class::method".
     *
     * @var    array<string, string|null>
     * @since  __DEPLOY_VERSION__
     */
    private static array $renderings = [];

    /**
     * Does this controller method show a page, and which view? Read from its
     * source (and from the controller's own methods it calls, a few levels
     * deep), never run:
     *
     * - null: it shows no page (no display(), getView(), createView() or
     *   new …View): a real task, such as a form submit, a redirect, a
     *   payment callback or a JSON answer;
     * - a view name: it sets `view` to a plain quoted name
     *   (`$this->input->set('view', 'member')`) as a statement of its own at
     *   the top of the method, before it calls `$this->display()` (or
     *   parent::/self::/static::display()), and sets no other view;
     * - '': it calls display() without changing the view, so the request's
     *   view is shown;
     * - UNKNOWN_VIEW: anything else that shows a page (a view object's
     *   display(), getView(), new …View, a conditional or non-literal view,
     *   or one of these in a method it calls).
     *
     * @param   string   $class   Controller class.
     * @param   string   $method  Method name.
     * @param   int      $depth   Recursion depth.
     * @param   array    $seen    Methods already followed.
     *
     * @return  string|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function methodRendering(string $class, string $method, int $depth = 0, array &$seen = []): ?string
    {
        $memo = $class . '::' . self::asciiLower($method);

        if ($depth === 0 && \array_key_exists($memo, self::$renderings)) {
            return self::$renderings[$memo];
        }

        try {
            $result = self::readMethodRendering($class, $method, $depth, $seen);
        } catch (\Throwable $e) {
            $result = self::UNKNOWN_VIEW;
        }

        if ($depth === 0) {
            self::$renderings[$memo] = $result;
        }

        return $result;
    }

    /**
     * methodRendering() without the memo.
     *
     * @param   string   $class   Controller class.
     * @param   string   $method  Method name.
     * @param   int      $depth   Recursion depth.
     * @param   array    $seen    Methods already followed.
     *
     * @return  string|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function readMethodRendering(string $class, string $method, int $depth, array &$seen): ?string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $declaring  = $reflection->getDeclaringClass();

        if ($declaring->getName() === BaseController::class) {
            // BaseController's display() shows the request's view; its other
            // methods (setRedirect(), getModel(), checkToken()…) render
            // nothing. getView() and createView() count where they're called.
            return self::asciiLower($reflection->getName()) === 'display' ? '' : null;
        }

        $seen[$declaring->getName() . '::' . self::asciiLower($method)] = true;

        $file   = $reflection->getFileName();
        $tokens = \is_string($file) ? self::fileTokens($file) : null;
        $body   = $tokens === null ? null : self::functionBody($tokens, $reflection->getName(), (int) $reflection->getStartLine(), (int) $reflection->getEndLine());

        if ($body === null) {
            return self::UNKNOWN_VIEW;
        }

        [$open, $close] = $body;

        $direct      = null;
        $other       = false;
        $viewUnknown = false;
        $views       = [];
        $calls       = [];
        $depthInBody = 0;

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];
            $id    = \is_array($token) ? $token[0] : $token;

            if ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depthInBody++;

                continue;
            }

            if ($id === '}') {
                $depthInBody--;

                continue;
            }

            // new SomethingView(…)
            if ($id === T_NEW) {
                $next = $tokens[$i + 1] ?? null;

                if (\is_array($next) && preg_match('/view$/i', (string) $next[1])) {
                    $other = true;
                }

                continue;
            }

            // $this->default_view = …, $this->name = …: display() would pick another view.
            if ($id === T_VARIABLE && $token[1] === '$this' && \is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_OBJECT_OPERATOR) {
                $property = $tokens[$i + 2] ?? null;

                if (\is_array($property) && \in_array(self::asciiLower($property[1]), ['default_view', 'name'], true) && ($tokens[$i + 3] ?? null) === '=') {
                    $viewUnknown = true;
                }

                continue;
            }

            if ($id !== T_STRING || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $name     = self::asciiLower($token[1]);
            $operator = $tokens[$i - 1] ?? null;
            $subject  = $tokens[$i - 2] ?? null;
            $arrow    = \is_array($operator) && \in_array($operator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
            $static   = \is_array($operator) && $operator[0] === T_DOUBLE_COLON;
            $onThis   = $arrow && \is_array($subject) && $subject[0] === T_VARIABLE && $subject[1] === '$this';
            $scope    = $static && \is_array($subject) && $subject[0] === T_STRING ? self::asciiLower($subject[1]) : '';
            $own      = $onThis || \in_array($scope, ['parent', 'self', 'static'], true);

            // ->set('view', …) / ->def('view', …) on any input.
            if ($arrow && \in_array($name, ['set', 'def'], true)) {
                $key = $tokens[$i + 2] ?? null;

                if (!\is_array($key) || $key[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    $viewUnknown = true;
                } elseif (trim($key[1], '\'"') === 'view') {
                    $value = ($tokens[$i + 3] ?? null) === ',' ? self::taskNameToken($tokens[$i + 4] ?? null, false) : null;

                    if ($name === 'set' && $value !== null && ($tokens[$i + 5] ?? null) === ')') {
                        // A top-level statement of its own: `$x->…->set('view', 'name');`
                        $topLevel = $depthInBody === 0 && ($tokens[$i + 6] ?? null) === ';' && self::startsStatement($tokens, $i, $open);
                        $views[]  = [$value, $topLevel, $i];
                    } else {
                        $viewUnknown = true;
                    }
                }

                continue;
            }

            if (\in_array($name, self::RENDER_CALLS, true)) {
                if ($name === 'display' && $own) {
                    $direct ??= $i;
                } else {
                    $other = true;
                }

                continue;
            }

            if (!$own || $depth >= 4) {
                continue;
            }

            // $this-> and static:: reach the request's controller class,
            // self:: this class, parent:: its parent.
            $owner = match ($scope) {
                'parent' => $declaring->getParentClass() ? $declaring->getParentClass()->getName() : null,
                'self'   => $declaring->getName(),
                default  => $class,
            };

            // A private method is the one of the class whose code calls it.
            if ($scope !== 'parent' && $declaring->hasMethod($name)) {
                $local = $declaring->getMethod($name);

                if ($local->isPrivate() && $local->getDeclaringClass()->getName() === $declaring->getName()) {
                    $owner = $declaring->getName();
                }
            }

            if ($owner === null) {
                $other = true;

                continue;
            }

            $calls[] = [$owner, $name];
        }

        foreach ($calls as [$owner, $name]) {
            if (!(new \ReflectionClass($owner))->hasMethod($name)) {
                // A magic or missing method: can't tell what it does.
                $other = true;

                continue;
            }

            $followed = (new \ReflectionMethod($owner, $name))->getDeclaringClass()->getName() . '::' . $name;

            if (isset($seen[$followed])) {
                continue;
            }

            if (self::methodRendering($owner, $name, $depth + 1, $seen) !== null) {
                $other = true;
            }
        }

        if ($direct === null && !$other) {
            return null;
        }

        if ($other || $viewUnknown) {
            return self::UNKNOWN_VIEW;
        }

        if ($views === []) {
            return '';
        }

        $names = array_values(array_unique(array_column($views, 0)));

        foreach ($views as [$value, $topLevel, $position]) {
            if (\count($names) === 1 && $topLevel && $position < $direct) {
                return self::normaliseName($value) ?: self::UNKNOWN_VIEW;
            }
        }

        return self::UNKNOWN_VIEW;
    }

    /**
     * Is the call at $position part of a plain chain (`$this->input->set(`,
     * `$input->set(`, `$this->app->getInput()->set(`) that starts a
     * statement of its own (not after if, else, while, a label…)?
     *
     * @param   array  $tokens    fileTokens() of the file.
     * @param   int    $position  Position of the method name.
     * @param   int    $open      Position of the function body's opening brace.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function startsStatement(array $tokens, int $position, int $open): bool
    {
        for ($k = $position - 1; $k > $open; $k--) {
            $token = $tokens[$k];

            if (\in_array($token, [';', '{', '}'], true)) {
                break;
            }

            if (\in_array($token, ['(', ')'], true)) {
                continue;
            }

            if (!\is_array($token) || !\in_array($token[0], [T_VARIABLE, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_STRING, T_DOUBLE_COLON], true)) {
                return false;
            }
        }

        $first = $tokens[$k + 1] ?? null;

        return \is_array($first) && $first[0] === T_VARIABLE;
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
     * The menu item the router resolved from the request itself: the active
     * item when the request names its Itemid (query string or form field)
     * or its SEF path starts with that item's path. 0 otherwise, including
     * when the router fell back to the home page (an unrouted URL).
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function getRoutedItemId(CMSApplicationInterface $app): int
    {
        if (!method_exists($app, 'getMenu')) {
            return 0;
        }

        $active = $app->getMenu()->getActive();
        $id     = $active ? (int) $active->id : 0;

        if ($id <= 0) {
            return 0;
        }

        $input = $app->getInput();

        foreach (['get', 'post'] as $source) {
            $raw = $input->$source->get('Itemid', null, 'raw');

            if (\is_scalar($raw) && preg_match('/^\s*\d+\s*$/', (string) $raw) && (int) $raw === $id) {
                return $id;
            }
        }

        $route = trim((string) ($active->route ?? ''), '/');

        if ($route === '') {
            return 0;
        }

        try {
            // The path the router parsed: no base path, no index.php, no suffix.
            $path = urldecode(Uri::getInstance()->getPath());
            $base = Uri::base(true);

            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, \strlen($base));
            }

            $path = trim($path, '/');

            if (str_starts_with($path, 'index.php')) {
                $path = trim(substr($path, 9), '/');
            }

            $path       = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $path);
            $candidates = [$path];
            $first      = explode('/', $path, 2);

            if (\count($first) === 2) {
                foreach (LanguageHelper::getLanguages() as $language) {
                    if (isset($language->sef) && (string) $language->sef === $first[0]) {
                        $candidates[] = $first[1];
                    }
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }

        foreach ($candidates as $candidate) {
            if (str_starts_with($candidate . '/', $route . '/')) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * Is the request exactly the page of the menu item it was opened under
     * (same component and view as the item's own link, the same id, and no
     * other record key the link doesn't have or holds another value for)?
     * Only then does that menu item vouch for the request. Any menu item id
     * can be added to any URL, and Joomla puts links it can't place under
     * the home item.
     *
     * @param   CMSApplicationInterface  $app      The application.
     * @param   array                    $request  From describeRequest().
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function isActiveItemsOwnPage(CMSApplicationInterface $app, array $request): bool
    {
        if ($request['view'] === null || $request['view'] === self::UNKNOWN_VIEW) {
            return false;
        }

        $active = self::getActiveItem($app);

        if ($active === null) {
            return false;
        }

        $query  = (array) ($active->query ?? []);
        $option = $request['option'];

        if (self::asciiLower(self::queryString($query, 'option')) !== $option) {
            return false;
        }

        $view = self::resolveView(
            $app,
            $option,
            self::queryString($query, 'view'),
            self::queryString($query, 'task'),
            self::queryString($query, 'controller')
        );

        if ($view !== $request['view'] || !self::sameIds(self::linkIds($option, $query), self::requestIds($request))) {
            return false;
        }

        $linkKeys    = self::recordKeys($option, $query);
        $requestKeys = (array) ($request['keys'] ?? []);

        foreach (array_unique(array_merge(array_keys($linkKeys), array_keys($requestKeys))) as $key) {
            if (!self::sameValue((string) $key, $linkKeys[$key] ?? null, $requestKeys[$key] ?? null)) {
                return false;
            }
        }

        return true;
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
     * @param   CMSApplicationInterface  $app      The application.
     * @param   array                    $request  From describeRequest().
     * @param   string[]                 $views    From getProtectedViews().
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

        if ($request['view'] !== '' && $request['view'] !== self::UNKNOWN_VIEW) {
            return \in_array($request['option'] . ':' . $request['view'], $views, true);
        }

        // The component's default view (or a view that can't be told): it
        // counts as any listed view of that component unless it's the menu
        // item's own page.
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
     *
     * - A page: true only when the view Joomla will show (resolveView()), the
     *   id (com_tags: the list of tags), the layout and any other record key
     *   match an excluded menu item's own link, or (com_content) the request
     *   is an article or subcategory inside an excluded category. So
     *   `task=article.display` is matched like `view=article`.
     * - A real task (a login or contact form submit, a payment callback): it
     *   shows no page of its own, so it is excluded only when it was sent to
     *   an excluded page: the menu item the router resolved from the
     *   request's own Itemid or SEF path (not its fallback to the home page)
     *   is excluded, and the view and id the router filled in are still that
     *   item's. A `view` or `id` added to the request can only narrow this,
     *   never exclude a request by itself.
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

        $input   = $app->getInput();
        $request = self::describeRequest($app, $option);
        $layout  = (string) self::singleValue($input, 'layout');
        $menu    = $app->getMenu();

        if ($request['view'] === null) {
            $routed = self::getRoutedItemId($app);

            if ($routed <= 0 || !\in_array($routed, $excluded, true)) {
                return false;
            }

            $item  = $menu->getItem($routed);
            $query = $item ? (array) ($item->query ?? []) : [];

            return $item
                && self::normaliseName(self::queryString($query, 'view')) === self::normaliseName((string) self::singleValue($input, 'view'))
                && self::sameIds(self::linkIds($option, $query), self::requestIds($request))
                && \in_array(self::queryString($query, 'layout'), ['', $layout], true);
        }

        if ($request['view'] === self::UNKNOWN_VIEW) {
            return false;
        }

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

            if (
                $itemView === $request['view']
                && self::sameIds(self::linkIds($option, $query), self::requestIds($request))
                && ($itemLayout === '' || $itemLayout === $layout)
                && self::sameRecordKeys(self::recordKeys($option, $query), $request['keys'])
            ) {
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
     * Do a link's and a request's record keys (other than `id`) certainly
     * name the same record: the same keys, each with the same value?
     *
     * @param   array<string, mixed>  $link     From recordKeys().
     * @param   array<string, mixed>  $request  From describeRequest().
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function sameRecordKeys(array $link, array $request): bool
    {
        foreach (array_unique(array_merge(array_keys($link), array_keys($request))) as $key) {
            if (!self::sameValue((string) $key, $link[$key] ?? null, $request[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Catch requests for protected content reached through a different (or
     * forged) Itemid, or none. The view is the one Joomla will show
     * (resolveView()), so `task=contact.display` or `controller=contact` is
     * matched like `view=contact`, and so is a task method that shows a page
     * itself; a request that runs another task (form submit, payment
     * callback, AJAX) never matches.
     *
     * - same component + view + id as a protected menu item (com_tags: any
     *   of the request's tags is the protected item's); in other
     *   (third-party) components also when the request picks the record by
     *   another key (user_id, slug…) that could be the protected item's;
     * - a protected item whose link has no id covers its view whatever the
     *   id: a list covers every record, unless the request is exactly
     *   another menu item's own page; one record picked by another key
     *   (user_id=5) covers the view unless the request is exactly another
     *   menu item's own page that is clearly another record;
     * - an article / contact / news feed (or a subcategory) inside a protected
     *   category of com_content, com_contact or com_newsfeeds, and the
     *   featured-contacts and contact category lists when they'd show a
     *   contact from a protected category or a protected single contact;
     * - any other component: the detail view of a protected list (a protected
     *   "members" list covers "member"), unless the request is exactly the
     *   page of the menu item it was opened under (issue #16);
     * - the component's default view (no view named), or a view that can't
     *   be told: any component with a protected menu item, unless it is
     *   exactly that menu item's page (never for a view that can't be told).
     *
     * "Exactly the page" means the same view, id and other record keys
     * (isActiveItemsOwnPage()).
     *
     * @param   CMSApplicationInterface  $app         The application.
     * @param   int[]                    $configured  Protected menu item ids.
     * @param   array                    $request     From describeRequest().
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
        $unknown             = $view === '' || $view === self::UNKNOWN_VIEW;
        $requestIds          = self::requestIds($request);
        $requestKeys         = (array) ($request['keys'] ?? []);
        $namesRecord         = $requestIds !== [] || $requestKeys !== [];
        $ownPageMemo         = null;
        $ownPage             = static function () use ($app, $request, &$ownPageMemo): bool {
            return $ownPageMemo ??= self::isActiveItemsOwnPage($app, $request);
        };

        foreach ($configured as $menuItemId) {
            $item  = $menu->getItem($menuItemId);
            $query = $item ? (array) ($item->query ?? []) : [];

            if (!$item || self::asciiLower(self::queryString($query, 'option')) !== $option) {
                continue;
            }

            $sameComponent = true;
            $itemView      = self::normaliseName(self::queryString($query, 'view'));
            $itemId        = self::queryId($query);

            if (!$unknown && $itemView === $view) {
                $linkIds  = self::linkIds($option, $query);
                $linkKeys = self::recordKeys($option, $query);

                if ($linkIds !== []) {
                    // The protected record itself.
                    if (array_intersect($linkIds, $requestIds) !== []) {
                        return true;
                    }

                    // Another key in the request could pick this record.
                    if (self::keysMaySelect($linkKeys, $requestKeys) && !$ownPage()) {
                        return true;
                    }
                } elseif ($linkKeys !== []) {
                    // One record picked by another key: the view is covered,
                    // except another menu item's own page for another record.
                    if (!($ownPage() && self::keysDiffer($linkKeys, $requestKeys))) {
                        return true;
                    }
                } elseif (!($namesRecord && $ownPage())) {
                    // A list without an id covers its view whatever record
                    // the request adds, except exactly another menu item's
                    // own page (a public, filtered copy of the list such as
                    // view=members&id=3).
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

        if ($unknown) {
            return !$ownPage();
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

        return !$ownPage();
    }

    /**
     * Could the request pick a protected record by a key other than `id`:
     * a record key the link doesn't have, or one whose value could be the
     * link's?
     *
     * @param   array<string, mixed>  $link     The protected link's record keys.
     * @param   array<string, mixed>  $request  The request's record keys.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function keysMaySelect(array $link, array $request): bool
    {
        foreach ($request as $key => $value) {
            if (!\array_key_exists($key, $link) || self::mayBeSame((string) $key, $link[$key], $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the request certainly name another record than the link: one of
     * the link's record keys has a value in the request that can't be the
     * link's?
     *
     * @param   array<string, mixed>  $link     The protected link's record keys.
     * @param   array<string, mixed>  $request  The request's record keys.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function keysDiffer(array $link, array $request): bool
    {
        foreach ($link as $key => $value) {
            if (\array_key_exists($key, $request) && !self::mayBeSame((string) $key, $value, $request[$key])) {
                return true;
            }
        }

        return false;
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
