<?php

/**
 * @package    plg_system_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Cspageprotector\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\CaptchaHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\DisplayHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\IpHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\LogHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\ProtectionHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\VerificationHelper;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Document\Document;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Event\Result\ResultAwareInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\EventInterface;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Joomla\Input\Input;
use Joomla\Registry\Registry;

/**
 * Gatekeeper for Cybersalt Page Protector.
 *
 * On every front-end request it asks the component whether the page is
 * protected. If it is, and the visitor is neither exempt nor already holding
 * a valid pass, the request is handed to the component's challenge view
 * instead of the real content — same URL, same menu item, same template —
 * so a scraper fetching the page only ever receives the challenge.
 *
 * It also keeps protected pages out of Joomla's page cache, otherwise a
 * cached copy of the real content could be served to an unverified visitor
 * (or a cached challenge served to a verified one).
 *
 * All settings live in the component's Options; this plugin has none of its own.
 *
 * @since  0.1.0
 */
final class Cspageprotector extends CMSPlugin implements SubscriberInterface
{
    /**
     * @var    boolean
     * @since  0.1.0
     */
    protected $autoloadLanguage = true;

    /**
     * The content events run ahead of other plugins: a locked article's text
     * is matched as it is stored, and the fields plugin never prints a locked
     * article's custom fields. So does onAfterDispatch: the render-time net
     * looks at what the task did, not at what other plugins add after it.
     *
     * @return  array<string, string|array{0: string, 1: int}>
     *
     * @since   0.1.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute'              => 'onAfterRoute',
            'onAfterInitialiseDocument' => ['onAfterInitialiseDocument', Priority::LOW],
            'onAfterDispatch'           => ['onAfterDispatch', Priority::HIGH],
            'onPageCacheSetCaching'     => 'onPageCacheSetCaching',
            'onPageCacheIsExcluded'     => 'onPageCacheIsExcluded',
            'onExtensionAfterSave'      => 'onExtensionAfterSave',
            'onAfterModuleList'         => 'onAfterModuleList',
            'onRenderModule'            => 'onRenderModule',
            'onContentPrepare'          => ['onContentPrepare', Priority::HIGH],
            'onContentAfterTitle'       => ['onLockedItemDisplay', Priority::HIGH],
            'onContentBeforeDisplay'    => ['onLockedItemDisplay', Priority::HIGH],
            'onContentAfterDisplay'     => ['onLockedItemDisplay', Priority::HIGH],
            'onBeforeCompileHead'       => 'onBeforeCompileHead',
        ];
    }

    /**
     * Set when the admin warning carries the "Enable the captcha plugin"
     * button, whose style has to wait until the document exists.
     *
     * @var    boolean
     * @since  0.2.0
     */
    private bool $alertButtonStyleNeeded = false;

    /**
     * Add the alert-button CSS for the admin warning queued in onAfterRoute
     * (too early for the document there).
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public function onBeforeCompileHead(): void
    {
        if (!$this->alertButtonStyleNeeded) {
            return;
        }

        try {
            DisplayHelper::addAlertButtonStyle();
        } catch (\Throwable $e) {
            // Unstyled is still usable.
        }
    }

    /**
     * Set once a protected module or a protected article is part of this
     * page, so the page is kept out of the page cache whoever is viewing it.
     *
     * @var    boolean
     * @since  0.1.0
     */
    private bool $pageDependsOnVisitor = false;

    /**
     * Articles whose text was swapped for the notice, see onLockedItemDisplay().
     *
     * @var    \SplObjectStorage|null
     * @since  0.3.0
     */
    private ?\SplObjectStorage $lockedItems = null;

    /**
     * Protected modules: in "hide" mode (and always on the challenge page
     * itself, so there is never a second captcha) drop them from the list
     * before anything renders, so empty positions collapse as usual.
     *
     * @param   EventInterface  $event  onAfterModuleList
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onAfterModuleList(EventInterface $event): void
    {
        $app = $this->getApplication();

        if (!$this->isActive($app)) {
            return;
        }

        try {
            $params = ProtectionHelper::getParams();

            if (!ProtectionHelper::hasModuleProtection($params)) {
                return;
            }

            $modules   = (array) $event->getArgument('modules', []);
            $protected = array_filter($modules, static fn ($m): bool => \is_object($m) && ProtectionHelper::isProtectedModule($m, $params));

            if ($protected === []) {
                return;
            }

            // Whatever this visitor sees, the page differs per visitor now.
            $this->pageDependsOnVisitor = true;
            $app->allowCache(false);

            // Never let Joomla's module cache store a protected module: the
            // cache key ignores who is viewing, so a verified visitor's copy
            // would be served to scrapers (and a placeholder, with its form
            // token, to verified visitors). Done for every visitor.
            foreach ($protected as $module) {
                $moduleParams = new Registry((string) ($module->params ?? ''));
                $moduleParams->set('cache', 0);
                $moduleParams->set('owncache', 0);
                $module->params = $moduleParams->toString();
            }

            if (ProtectionHelper::visitorHasAccess($app, $params)) {
                return;
            }

            $onChallengePage = $app->getInput()->getCmd('option') === 'com_cspageprotector';
            $hide            = (string) $params->get('module_mode', ProtectionHelper::MODULE_MODE_PLACEHOLDER) === ProtectionHelper::MODULE_MODE_HIDE;

            if (!$hide && !$onChallengePage) {
                // Placeholder mode: onRenderModule swaps the content.
                return;
            }

            $kept = array_values(array_filter($modules, static fn ($m): bool => !\is_object($m) || !ProtectionHelper::isProtectedModule($m, $params)));

            if (method_exists($event, 'updateModules')) {
                $event->updateModules($kept);
            } else {
                $event->setArgument('modules', $kept);
            }
        } catch (\Throwable $e) {
            Log::add('cs-page-protector module filter error: ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
        }
    }

    /**
     * Placeholder mode: replace a protected module's content with a short
     * notice and a "Show content" button that runs the one site-wide check.
     * Runs before the module chrome, so the module keeps its title and box.
     * The captcha itself is never rendered inside a module.
     *
     * @param   EventInterface  $event  onRenderModule
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onRenderModule(EventInterface $event): void
    {
        $app = $this->getApplication();

        // Checked on every render, not only for modules in the page's list:
        // {loadmodule}, {loadposition} and third-party module loaders render
        // modules that never went through onAfterModuleList.
        if (!$this->isActive($app)) {
            return;
        }

        $module = $event->getArgument('subject') ?? $event->getArgument(0);

        if (!\is_object($module) || !isset($module->content)) {
            return;
        }

        try {
            $params = ProtectionHelper::getParams();

            if (!ProtectionHelper::hasModuleProtection($params) || !ProtectionHelper::isProtectedModule($module, $params)) {
                return;
            }

            // Keeps the page out of the page cache, wherever the module came from.
            $this->pageDependsOnVisitor = true;

            if (ProtectionHelper::visitorHasAccess($app, $params)) {
                return;
            }

            $module->content = $this->renderModulePlaceholder($app, $params);
        } catch (\Throwable $e) {
            // Fail closed for the module: never leak its content on an error.
            $module->content = '';
            Log::add('cs-page-protector module placeholder error: ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
        }
    }

    /**
     * The placeholder shown instead of a protected module's content.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  string  Safe HTML.
     *
     * @since   0.1.0
     */
    private function renderModulePlaceholder(CMSApplicationInterface $app, Registry $params): string
    {
        $document = $app->getDocument();

        if ($document && method_exists($document, 'getWebAssetManager')) {
            $wa = $document->getWebAssetManager();
            $wa->getRegistry()->addExtensionRegistryFile('com_cspageprotector');
            $wa->useStyle('com_cspageprotector.module');
            $wa->useScript('com_cspageprotector.module');
        }

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $text      = trim((string) $params->get('module_placeholder_text', '')) ?: Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_MODULE_LOCKED_TEXT');
        $button    = trim((string) $params->get('module_placeholder_button', '')) ?: Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_MODULE_LOCKED_BUTTON');
        $itemId    = ProtectionHelper::getActiveItemId($app);
        $returnB64 = base64_encode(Uri::getInstance()->toString());
        $itemParam = $itemId > 0 ? '&Itemid=' . $itemId : '';

        // Fallback (no JavaScript, or a captcha that can't run inline): the
        // check on its own clean page (tmpl=component: template styling, no
        // other modules), which then comes back here. Plain non-SEF links are
        // always routable and keep the base64 intact.
        $fallbackUrl = Uri::base() . 'index.php?option=com_cspageprotector&view=challenge&tmpl=component'
            . $itemParam . '&cspp_return=' . rawurlencode($returnB64);
        $verifyUrl   = Uri::base() . 'index.php?option=com_cspageprotector&task=challenge.verify' . $itemParam;

        // Inline check: the Proof-of-Work widget is placed in the clicked box,
        // solved, and the form posts; the page reloads with the module shown.
        // The widget markup is printed once per page inside an inert
        // <template>, so nothing runs (and only one captcha ever exists)
        // until a visitor clicks.
        $captchaName = CaptchaHelper::getConfiguredPlugin($params);
        $inline      = $captchaName === CaptchaHelper::DEFAULT_PLUGIN && CaptchaHelper::isAvailable($captchaName);
        $template    = '';

        if ($inline && !$this->captchaTemplatePrinted) {
            $captcha = CaptchaHelper::getInstance($captchaName);
            $widget  = $captcha ? (string) $captcha->display(CaptchaHelper::FIELD_NAME, 'cspp-inline-captcha', 'cspp-captcha') : '';

            if ($widget === '') {
                $inline = false;
            } else {
                $template                     = '<template class="cspp-captcha-template">' . $widget . '</template>';
                $this->captchaTemplatePrinted = true;
            }
        }

        return '<div class="cspp-module-locked" data-cspp-inline="' . ($inline ? '1' : '0') . '">'
            . '<p class="cspp-module-locked-text">' . $e($text) . '</p>'
            . '<form class="cspp-module-locked-form" method="post" action="' . $e($verifyUrl) . '">'
            . '<div class="cspp-module-locked-captcha"></div>'
            . '<a class="btn btn-secondary btn-sm cspp-module-locked-button" href="' . $e($fallbackUrl) . '" rel="nofollow">' . $e($button) . '</a>'
            . '<p class="cspp-module-locked-status" aria-live="polite"'
            . ' data-working="' . $e(Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_MODULE_WORKING')) . '"'
            . ' data-done="' . $e(Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_MODULE_DONE')) . '"'
            . ' data-error="' . $e(Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_MODULE_ERROR')) . '"></p>'
            . '<input type="hidden" name="return" value="' . $e($returnB64) . '">'
            . '<input type="hidden" name="cspp_item" value="' . (int) $itemId . '">'
            . '<input type="hidden" name="cspp_source" value="module">'
            . '<input type="hidden" name="' . $e(Session::getFormToken()) . '" value="1">'
            . '</form>'
            . $template
            . '</div>';
    }

    /**
     * The inline captcha <template> is printed once per page.
     *
     * @var    boolean
     * @since  0.1.0
     */
    private bool $captchaTemplatePrinted = false;

    /**
     * When the component Options are saved with "Shorten IP addresses" on,
     * shorten the IPs already in the log too, not just future entries.
     *
     * @param   EventInterface  $event  onExtensionAfterSave (context, table, isNew)
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onExtensionAfterSave(EventInterface $event): void
    {
        $args    = $event->getArguments();
        $context = (string) ($args['context'] ?? $args[0] ?? '');
        $table   = $args['subject'] ?? $args[1] ?? null;

        if ($context !== 'com_config.component' || !\is_object($table) || ($table->element ?? '') !== 'com_cspageprotector') {
            return;
        }

        if (!class_exists(LogHelper::class)) {
            return;
        }

        try {
            if (LogHelper::anonymizeEnabled(new Registry((string) ($table->params ?? '')))) {
                LogHelper::anonymizeStored(Factory::getContainer()->get(DatabaseInterface::class));
            }

            // Module protection changes what a page contains per visitor, so drop
            // any page-cache entries created before the new settings.
            // The site page cache lives in /cache on Joomla 5 but in
            // administrator/cache on Joomla 6 (and wherever cache_path points).
            $bases = array_unique(array_filter([
                (string) $this->getApplication()->get('cache_path', ''),
                JPATH_SITE . '/cache',
                JPATH_ADMINISTRATOR . '/cache',
            ]));

            // Also the article view cache and every module cache group: copies
            // stored before module/content protection was set up would otherwise
            // keep being served for their whole lifetime.
            foreach ($bases as $base) {
                if (!is_dir($base)) {
                    continue;
                }

                $controller = Factory::getContainer()->get(CacheControllerFactoryInterface::class)
                    ->createCacheController('output', ['defaultgroup' => 'page', 'cachebase' => $base]);

                $groups = ['page', 'com_content', 'com_modules', 'com_tags'];

                foreach (glob($base . '/mod_*', GLOB_ONLYDIR) ?: [] as $dir) {
                    $groups[] = basename($dir);
                }

                foreach (array_unique($groups) as $group) {
                    $controller->clean($group);
                }
            }
        } catch (\Throwable $e) {
            Log::add('cs-page-protector: after-save tasks failed: ' . $e->getMessage(), Log::WARNING, 'com_cspageprotector');
        }
    }

    /**
     * Challenge unverified visitors on protected pages.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onAfterRoute(): void
    {
        $app = $this->getApplication();

        if ($app->isClient('administrator')) {
            $this->warnAdminIfCaptchaCantRun($app);

            return;
        }

        if (!$this->isActive($app)) {
            return;
        }

        try {
            $params = ProtectionHelper::getParams();

            $this->guardSharedContent($app, $params);

            if (!ProtectionHelper::isProtectedRequest($app, $params)) {
                // Let through as a callback or form submit in a component
                // that has something protected: if it shows an HTML page
                // anyway, onAfterDispatch() replaces it with the check.
                if (ProtectionHelper::needsRenderCheck($app, $params)) {
                    $app->allowCache(false);
                    $this->renderCheck = true;

                    // A form the visitor's browser submits: the check comes
                    // first and the task runs once they've passed.
                    if ($this->isBrowserFormSubmit($app)) {
                        $this->guardFormSubmit($app, $params);
                    }
                }

                return;
            }

            $this->guard($app, $params);
        } catch (\Throwable $e) {
            // A bug in the protector must never take the site down. Log it and
            // let the page render normally.
            Log::add('cs-page-protector gatekeeper error: ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
        }
    }

    /**
     * Protection that applies on pages that aren't themselves protected:
     * protected modules and protected article text can appear anywhere.
     *
     * - Joomla's view and module caches key on the URL, not on who is
     *   viewing, so they're switched off for the request whenever module or
     *   content protection is in use.
     * - com_ajax calls into a protected module's helper are refused.
     * - com_content feeds that would carry protected articles are refused.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    private function guardSharedContent(CMSApplicationInterface $app, Registry $params): void
    {
        $hasModules = ProtectionHelper::hasModuleProtection($params);
        $targets    = ProtectionHelper::getContentTargets($app, $params);
        $hasContent = ProtectionHelper::hasContentTargets($targets);

        if (!$hasModules && !$hasContent) {
            return;
        }

        $app->set('caching', 0);

        $input  = $app->getInput();
        $option = ProtectionHelper::asciiLower((string) ProtectionHelper::singleValue($input, 'option'));
        $module = (string) ProtectionHelper::singleValue($input, 'module');

        if ($hasModules && $option === 'com_ajax' && $module !== '') {
            $type = 'mod_' . preg_replace('/^mod_/', '', ProtectionHelper::asciiLower($module));

            if (\in_array($type, ProtectionHelper::getProtectedModuleTypes($params), true) && !ProtectionHelper::visitorHasAccess($app, $params)) {
                $this->sendBlocked($app);
            }
        }

        // An array format/view/id can't be told apart, so it counts as a feed
        // of everything protected (feedIncludesProtected() with no view).
        $format = ProtectionHelper::singleValue($input, 'format', 'word', 'html');

        if (
            ($targets['articles'] !== [] || $targets['ranges'] !== [])
            && $option === 'com_content'
            && ($format === null || ProtectionHelper::asciiLower($format) !== 'html')
            && ProtectionHelper::feedIncludesProtected(
                (string) ProtectionHelper::singleValue($input, 'view'),
                (int) (ProtectionHelper::singleValue($input, 'id', 'int', '0') ?? 0),
                $targets
            )
            && !ProtectionHelper::visitorHasAccess($app, $params)
        ) {
            $this->sendBlocked($app);
        }

        // Tag and Smart Search feeds print the items' text directly and fire no
        // content event.
        if ($this->isUngatedFeed($app, $params) && !ProtectionHelper::visitorHasAccess($app, $params)) {
            $this->sendBlocked($app);
        }
    }

    /**
     * A non-HTML com_content request while content is protected: refused or
     * not (see guardSharedContent()), it depends on who is asking.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  boolean
     *
     * @since   0.3.0
     */
    private function isContentFeed(CMSApplicationInterface $app, Registry $params): bool
    {
        $input  = $app->getInput();
        $format = ProtectionHelper::singleValue($input, 'format', 'word', 'html');

        return ProtectionHelper::asciiLower((string) ProtectionHelper::singleValue($input, 'option')) === 'com_content'
            && ($format === null || ProtectionHelper::asciiLower($format) !== 'html')
            && ProtectionHelper::hasContentTargets(ProtectionHelper::getContentTargets($app, $params));
    }

    /**
     * A feed that can carry protected content without the content gate ever
     * seeing it: anything non-HTML from com_tags (a tag can carry any article
     * or contact) and the com_finder feed. The tag search field's AJAX call
     * returns tag names only. Exactly that call: an unknown task (a bare
     * `searchajax`) runs display(), so the feed.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  boolean
     *
     * @since   0.3.0
     */
    private function isUngatedFeed(CMSApplicationInterface $app, Registry $params): bool
    {
        if (!ProtectionHelper::hasContentTargets(ProtectionHelper::getContentTargets($app, $params))) {
            return false;
        }

        $input  = $app->getInput();
        $option = ProtectionHelper::asciiLower((string) ProtectionHelper::singleValue($input, 'option'));
        $format = ProtectionHelper::singleValue($input, 'format', 'word', 'html');
        $format = $format === null ? null : ProtectionHelper::asciiLower($format);

        if ($option === 'com_finder') {
            return $format === null || $format === 'feed';
        }

        return $option === 'com_tags'
            && $format !== 'html'
            && ProtectionHelper::asciiLower((string) ProtectionHelper::singleValue($input, 'task')) !== 'tags.searchajax';
    }

    /**
     * Protected article text shown somewhere other than its own protected
     * page (a blog or featured list, the archive, a tag list, an articles
     * module): swap it for a short notice for visitors who haven't passed.
     *
     * @param   EventInterface  $event  onContentPrepare (context, article, params, page)
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onContentPrepare(EventInterface $event): void
    {
        $app = $this->getApplication();

        if (!$this->isActive($app)) {
            return;
        }

        $context = (string) ($event->getArgument('context') ?? $event->getArgument(0) ?? '');
        $article = $event->getArgument('subject') ?? $event->getArgument(1);

        if (!\is_object($article) || !preg_match('/^(com_content|com_tags|mod_articles)/', $context)) {
            return;
        }

        // A category's own description: its id isn't an article's.
        if (str_ends_with($context, '.categories')) {
            return;
        }

        try {
            $params  = ProtectionHelper::getParams();
            $targets = ProtectionHelper::getContentTargets($app, $params);

            if (!ProtectionHelper::hasContentTargets($targets)) {
                return;
            }

            // com_tags rows describe the tagged item: articles and contacts
            // matter here (a contact's row carries its address and photo).
            $type       = isset($article->type_alias) ? (string) $article->type_alias : 'com_content.article';
            $categoryId = (int) ($article->core_catid ?? $article->catid ?? 0);

            if ($type === 'com_contact.contact') {
                $protected = ProtectionHelper::isProtectedContact((int) ($article->content_item_id ?? 0), $categoryId, $targets);
            } elseif ($type === 'com_content.article') {
                $articleId = (int) ($article->content_item_id ?? $article->id ?? 0);
                $protected = ProtectionHelper::isProtectedArticle($articleId, $categoryId, $targets);

                // Core's article modules hand over the intro text and nothing else.
                if (!$protected && $articleId === 0 && $categoryId === 0 && str_starts_with($context, 'mod_articles')) {
                    $protected = ProtectionHelper::isProtectedIntroText((string) ($article->text ?? ''), $targets);
                }
            } else {
                return;
            }

            if (!$protected) {
                return;
            }

            // Whatever this visitor sees, the page differs per visitor now.
            $this->pageDependsOnVisitor = true;
            $app->allowCache(false);

            if (ProtectionHelper::visitorHasAccess($app, $params)) {
                return;
            }

            $notice = '<p class="cspp-content-locked">'
                . htmlspecialchars(Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_CONTENT_LOCKED'), ENT_QUOTES, 'UTF-8')
                . '</p>';

            foreach (['text', 'introtext', 'fulltext', 'core_body'] as $field) {
                if (property_exists($article, $field) || $field === 'text') {
                    $article->$field = $field === 'fulltext' ? '' : $notice;
                }
            }

            if ($type === 'com_contact.contact' && property_exists($article, 'core_images')) {
                $article->core_images = '{}';
            }

            $this->lockItem($article);
        } catch (\Throwable $e) {
            // Fail closed: never print protected text after an error.
            $article->text = '';
            $article->introtext = '';
            $article->fulltext = '';
            $this->lockItem($article);
            $this->pageDependsOnVisitor = true;
            Log::add('cs-page-protector content gate error: ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
        }
    }

    /**
     * Keep a locked article's custom fields off the page: for templates that
     * print them from the item, and for onLockedItemDisplay().
     *
     * @param   object  $item  The article.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    private function lockItem(object $item): void
    {
        $item->jcfields = [];

        $this->lockedItems ??= new \SplObjectStorage();
        $this->lockedItems->attach($item);
    }

    /**
     * A locked article prints nothing through the display events. The fields
     * plugin loads an item's field values itself when these fire, so emptying
     * jcfields isn't enough, and other plugins' output (ratings, say) belongs
     * to the article too.
     *
     * @param   EventInterface  $event  onContentAfterTitle, onContentBeforeDisplay, onContentAfterDisplay
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function onLockedItemDisplay(EventInterface $event): void
    {
        $item = $event->getArgument('subject') ?? $event->getArgument(1);

        if (\is_object($item) && $this->lockedItems !== null && $this->lockedItems->contains($item)) {
            $event->stopPropagation();
        }
    }

    /**
     * Tell the page cache not to serve or store protected pages.
     *
     * @param   EventInterface  $event  onPageCacheSetCaching
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onPageCacheSetCaching(EventInterface $event): void
    {
        if ($this->isProtectedSafe()) {
            $this->addResult($event, false);
        }
    }

    /**
     * Belt and braces: also mark protected pages as excluded from the page cache.
     *
     * @param   EventInterface  $event  onPageCacheIsExcluded
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function onPageCacheIsExcluded(EventInterface $event): void
    {
        // Protected page, or a page carrying a protected module: either way the
        // output depends on the visitor and must never be cached.
        if ($this->pageDependsOnVisitor || $this->isProtectedSafe()) {
            $this->addResult($event, true);
        }
    }

    /**
     * The actual protection flow for a protected request.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    private function guard(CMSApplicationInterface $app, Registry $params): void
    {
        // Protected pages must never land in a browser or proxy shared cache.
        $app->allowCache(false);

        $decision = $this->decide($app, $params, '');

        if ($decision === self::DECISION_PASS) {
            return;
        }

        if ($decision === self::DECISION_BLOCK) {
            $this->sendBlocked($app);
        }

        // Hand the request to the challenge page. The URL and the active menu
        // item stay the same, so the visitor sees the challenge inside the
        // site's normal template and lands back here once they pass.
        $this->handToChallenge($app->getInput());
    }

    /**
     * The visitor may see the page (exempt, verified, or the captcha can't
     * run and the site lets visitors through).
     */
    private const DECISION_PASS = 0;

    /**
     * Plain 403: the captcha can't run and the site blocks, or a non-HTML format.
     */
    private const DECISION_BLOCK = 1;

    /**
     * Show the check.
     */
    private const DECISION_CHALLENGE = 2;

    /**
     * What happens to a visitor on a protected page (or, for the render-time
     * safety net, on a page a task showed): exempt (allow-listed IP, logged
     * in, a verified search engine) or holding a valid pass: pass. Otherwise
     * the check, or a plain 403 when the captcha can't run and the site is
     * set to block, or for non-HTML formats. Logs the event the same way for
     * both.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     * @param   string                   $detail  Log details for a challenge ('' on a protected page).
     *
     * @return  integer  One of the DECISION_* constants.
     *
     * @since   0.3.0
     */
    private function decide(CMSApplicationInterface $app, Registry $params, string $detail): int
    {
        $input     = $app->getInput();
        $ip        = IpHelper::getClientIp($params);
        $userAgent = $input->server->getString('HTTP_USER_AGENT', '');
        $itemId    = ProtectionHelper::getActiveItemId($app);
        $url       = Uri::getInstance()->toString();
        $captcha   = CaptchaHelper::getConfiguredPlugin($params);

        $exemption = ProtectionHelper::getExemption($app, $params, $ip, $userAgent);

        if ($exemption !== ProtectionHelper::EXEMPT_NONE) {
            if ($exemption === ProtectionHelper::EXEMPT_BOT && (int) $params->get('log_bots', 0) === 1) {
                LogHelper::log($params, LogHelper::EVENT_BOT, $ip, $userAgent, $url, $itemId, $captcha);
            }

            return self::DECISION_PASS;
        }

        if (VerificationHelper::isVerified($app, $params, $ip, $userAgent)) {
            return self::DECISION_PASS;
        }

        if (!CaptchaHelper::isAvailable($captcha)) {
            LogHelper::log(
                $params,
                LogHelper::EVENT_ERROR,
                $ip,
                $userAgent,
                $url,
                $itemId,
                $captcha,
                Text::sprintf('PLG_SYSTEM_CSPAGEPROTECTOR_LOG_CAPTCHA_UNAVAILABLE', $captcha)
            );

            return (string) $params->get('captcha_unavailable', 'allow') === 'allow' ? self::DECISION_PASS : self::DECISION_BLOCK;
        }

        // Feeds, JSON, raw and other non-HTML formats can't show a challenge,
        // and are an easy way to pull a protected category's content.
        $format = ProtectionHelper::singleValue($input, 'format', 'word', 'html');
        $format = $format === null ? 'array' : ProtectionHelper::asciiLower($format);

        if ($format !== 'html') {
            LogHelper::log($params, LogHelper::EVENT_BLOCKED, $ip, $userAgent, $url, $itemId, $captcha, 'format=' . $format);

            return self::DECISION_BLOCK;
        }

        LogHelper::log($params, LogHelper::EVENT_CHALLENGED, $ip, $userAgent, $url, $itemId, $captcha, $detail);

        return self::DECISION_CHALLENGE;
    }

    /**
     * Is this a form the visitor's browser submits as a page from this site
     * (a POST the browser marks `Sec-Fetch-Dest: document` and
     * `Sec-Fetch-Site: same-origin`), asking for an HTML answer? AJAX calls,
     * server-to-server callbacks, forms posted from other sites and browsers
     * that don't send the headers aren't: they keep the render-time safety
     * net (onAfterDispatch()).
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  boolean
     *
     * @since   0.3.1
     */
    private function isBrowserFormSubmit(CMSApplicationInterface $app): bool
    {
        $input  = $app->getInput();
        $format = ProtectionHelper::singleValue($input, 'format', 'word', 'html');

        return strtoupper((string) $input->getMethod()) === 'POST'
            && ProtectionHelper::asciiLower($input->server->getString('HTTP_SEC_FETCH_DEST', '')) === 'document'
            && ProtectionHelper::asciiLower($input->server->getString('HTTP_SEC_FETCH_SITE', '')) === 'same-origin'
            && $format !== null
            && ProtectionHelper::asciiLower($format) === 'html';
    }

    /**
     * A form submit the render-time safety net applies to (see
     * isBrowserFormSubmit()). The net replaces the page the task shows with
     * the check after the task ran, and what was posted is lost: a sign-up
     * form handing the visitor on to a payment provider never got there. So
     * a visitor without a pass gets the check before the task runs, and the
     * check page carries the form (VerificationHelper::holdForm()) so it is
     * sent on once they've passed (ChallengeController). The task then runs
     * once, with a pass, and the net lets its page through. Exempt visitors
     * and those with a pass go straight on; when the captcha can't run and
     * the site blocks, the task runs and the net decides, as before.
     *
     * @param   CMSApplicationInterface  $app     The application.
     * @param   Registry                 $params  Component params.
     *
     * @return  void
     *
     * @since   0.3.1
     */
    private function guardFormSubmit(CMSApplicationInterface $app, Registry $params): void
    {
        $decision = $this->decide($app, $params, 'form');

        if ($decision !== self::DECISION_CHALLENGE) {
            return;
        }

        // The posted fields as sent: Input's filters would change them.
        VerificationHelper::holdForm($_POST);

        $this->renderCheck = false;
        $this->handToChallenge($app->getInput());
    }

    /**
     * Point the request at the challenge page, keeping the URL to come back to.
     *
     * @param   Input  $input  The request input.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    private function handToChallenge(Input $input): void
    {
        $input->set('cspp_return', base64_encode(Uri::getInstance()->toString()));
        $input->set('cspp_inplace', 1);
        $input->set('option', 'com_cspageprotector');
        $input->set('view', 'challenge');
        $input->set('layout', 'default');
        $input->set('task', 'display');
        $input->set('format', 'html');
        $input->set('id', null);
        // controller=contact would make the dispatcher look for a
        // ContactController in this component (unset: no `controller` at all).
        $input->set('controller', null);
    }

    /**
     * Set in onAfterRoute when the request was let through as a real task
     * (a callback, a form submit) in a component that has something
     * protected (ProtectionHelper::needsRenderCheck()).
     *
     * @var    boolean
     * @since  0.3.0
     */
    private bool $renderCheck = false;

    /**
     * The document, response, breadcrumbs and messages as they were just
     * before the component ran, for the render-time safety net.
     *
     * @var    array<string, mixed>|null
     * @since  0.3.0
     */
    private ?array $renderSnapshot = null;

    /**
     * Render-time safety net, part 1: remember the page as it is just before
     * the component runs, so that if the task shows a page that has to be
     * replaced by the check, nothing it added (title, meta tags, scripts,
     * module buffers, headers and status, breadcrumbs, messages) is left.
     *
     * @param   EventInterface  $event  onAfterInitialiseDocument
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function onAfterInitialiseDocument(EventInterface $event): void
    {
        if (!$this->renderCheck) {
            return;
        }

        $app = $this->getApplication();

        try {
            $document = $app->getDocument();

            if (!$document instanceof HtmlDocument) {
                return;
            }

            $head = $document->getHeadData();
            unset($head['assetManager'], $head['scriptText']);

            $pathway = method_exists($app, 'getPathway') ? $app->getPathway()->getPathway() : null;

            $this->renderSnapshot = [
                'head'     => $head,
                'assets'   => (array) ($document->getWebAssetManager()->getManagerState()['activeAssets'] ?? []),
                'document' => $this->documentState($document),
                'buffer'   => Document::$_buffer,
                'response' => method_exists($app, 'getResponse') ? $app->getResponse() : null,
                // Copies: a view renames breadcrumbs in place (setItemName()).
                'pathway'  => \is_array($pathway) ? array_map(static fn ($item) => \is_object($item) ? clone $item : $item, $pathway) : null,
                'messages' => method_exists($app, 'getMessageQueue') ? $app->getMessageQueue() : null,
            ];
        } catch (\Throwable $e) {
            $this->renderSnapshot = null;
        }
    }

    /**
     * Render-time safety net, part 2. Which page a task shows is read from
     * the controller's source, and that reading can never be complete. So a
     * request let through as a callback in a component that has something
     * protected (see ProtectionHelper::needsRenderCheck()) is checked again
     * here, after it ran: if it produced an HTML page and the visitor has no
     * pass, that page is replaced by the check, exactly as on a protected
     * page (same status, headers, exemptions and log entry, detail "task").
     *
     * Not affected: callbacks that redirect or exit (they never get here),
     * an empty page, and other formats (raw, JSON, feeds).
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function onAfterDispatch(): void
    {
        if (!$this->renderCheck) {
            return;
        }

        $this->renderCheck = false;
        $app               = $this->getApplication();

        try {
            $document = $app->getDocument();

            if (!$document instanceof HtmlDocument) {
                return;
            }

            if (!$this->taskMadeAPage($app, $document)) {
                return;
            }

            $params   = ProtectionHelper::getParams();
            $decision = $this->decide($app, $params, 'task');

            if ($decision === self::DECISION_PASS) {
                return;
            }

            // Nothing the task set may reach the visitor.
            $this->restorePage($app, $document);
            $app->allowCache(false);

            if ($decision === self::DECISION_BLOCK) {
                $this->sendBlocked($app);
            }

            $this->handToChallenge($app->getInput());
            $document->setBuffer($this->renderChallenge($app), ['type' => 'component']);
        } catch (\Throwable $e) {
            // Fail closed: whatever the task produced is never shown.
            Document::$_buffer = null;

            try {
                Log::add('cs-page-protector render check error: ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
            } catch (\Throwable $ignored) {
                // Nothing more to do.
            }

            $this->sendBlocked($app);
        }
    }

    /**
     * What a document holds outside its head data.
     *
     * @param   HtmlDocument  $document  The page's document.
     *
     * @return  array<string, mixed>
     *
     * @since   0.3.0
     */
    private function documentState(HtmlDocument $document): array
    {
        return [
            'mime'      => $document->getMimeEncoding(),
            'charset'   => $document->getCharset(),
            'generator' => $document->getGenerator(),
            'base'      => $document->getBase(),
            'language'  => $document->getLanguage(),
            'direction' => $document->getDirection(),
            'tab'       => $document->_getTab(),
            'preload'   => $document->getPreloadManager()->getLinkProvider(),
        ];
    }

    /**
     * Did the task put anything on the page? The component printed something,
     * or changed a module position's buffer, the head, the document's other
     * settings, the assets, the breadcrumbs or the messages while it ran. A task that shows nothing
     * and leaves all of that alone isn't a page, whatever it did otherwise.
     *
     * @param   CMSApplicationInterface  $app       The application.
     * @param   HtmlDocument             $document  The page's document.
     *
     * @return  boolean
     *
     * @since   0.3.0
     */
    private function taskMadeAPage(CMSApplicationInterface $app, HtmlDocument $document): bool
    {
        $buffers = \is_array(Document::$_buffer) ? Document::$_buffer : [];
        $page    = $buffers['component'][''][''] ?? null;

        if (\is_string($page) && trim($page) !== '') {
            return true;
        }

        $snapshot = $this->renderSnapshot;

        if ($snapshot === null) {
            return false;
        }

        $before = \is_array($snapshot['buffer']) ? $snapshot['buffer'] : [];
        unset($buffers['component'], $before['component']);

        $head = $document->getHeadData();
        unset($head['assetManager'], $head['scriptText']);

        return $buffers != $before
            || $head != $snapshot['head']
            || $this->documentState($document) != $snapshot['document']
            || (array) ($document->getWebAssetManager()->getManagerState()['activeAssets'] ?? []) != $snapshot['assets']
            || (method_exists($app, 'getPathway') && $app->getPathway()->getPathway() != $snapshot['pathway'])
            || (method_exists($app, 'getMessageQueue') && $app->getMessageQueue() != $snapshot['messages']);
    }

    /**
     * Put the document, response, breadcrumbs and messages back the way they
     * were before the component ran (see onAfterInitialiseDocument()).
     *
     * @param   CMSApplicationInterface  $app       The application.
     * @param   HtmlDocument             $document  The page's document.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    private function restorePage(CMSApplicationInterface $app, HtmlDocument $document): void
    {
        $snapshot = $this->renderSnapshot;

        // Title, description, meta tags, links, scripts, styles, custom tags, script options.
        $document->resetHeadData();

        if ($snapshot !== null) {
            $document->setHeadData($snapshot['head']);

            // Not part of the head data. Without the sync no meta tag is added.
            $document->setMimeEncoding($snapshot['document']['mime'], false);
            $document->setCharset($snapshot['document']['charset']);
            $document->setTab($snapshot['document']['tab']);
            $document->setGenerator($snapshot['document']['generator']);
            $document->setBase($snapshot['document']['base']);
            $document->setLanguage($snapshot['document']['language']);
            $document->setDirection($snapshot['document']['direction']);
            $document->getPreloadManager()->setLinkProvider($snapshot['document']['preload']);
        } else {
            $document->setMetaData('rights', $app->get('MetaRights'));
        }

        // Assets (and inline scripts and styles) the task's view asked for.
        $assets = $document->getWebAssetManager();
        $assets->reset();

        foreach ((array) ($snapshot['assets'] ?? []) as $type => $names) {
            foreach ((array) $names as $name => $state) {
                if ($state === WebAssetManager::ASSET_STATE_ACTIVE) {
                    $assets->useAsset((string) $type, (string) $name);
                }
            }
        }

        // Module positions a view filled in itself.
        Document::$_buffer = $snapshot['buffer'] ?? null;

        // Headers and status the task set.
        if (($snapshot['response'] ?? null) !== null) {
            $app->setResponse($snapshot['response']);
        } else {
            $app->setHeader('Status', '200', true);
        }

        if (\is_array($snapshot['pathway'] ?? null) && method_exists($app, 'getPathway')) {
            $app->getPathway()->setPathway($snapshot['pathway']);
        }

        if (method_exists($app, 'getMessageQueue')) {
            $app->getMessageQueue(true);

            foreach ((array) ($snapshot['messages'] ?? []) as $message) {
                if (\is_array($message) && isset($message['message'])) {
                    $app->enqueueMessage((string) $message['message'], (string) ($message['type'] ?? 'message'));
                }
            }
        }
    }

    /**
     * Render the challenge page's component output, the way ComponentHelper::
     * renderComponent() would. The component's base path is given
     * explicitly: by now JPATH_COMPONENT points at the component that ran.
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  string
     *
     * @since   0.3.0
     */
    private function renderChallenge(CMSApplicationInterface $app): string
    {
        $option   = 'com_cspageprotector';
        $language = $app->getLanguage();
        $language->load($option, JPATH_SITE) || $language->load($option, JPATH_SITE . '/components/' . $option);

        $component = $app->bootComponent($option);

        if (!$component instanceof MVCFactoryServiceInterface) {
            throw new \RuntimeException('The challenge component can\'t be loaded.');
        }

        $scope      = $app->scope ?? null;
        $app->scope = $option;
        $level      = ob_get_level();

        ob_start();

        try {
            $controller = $component->getMVCFactory()->createController(
                'display',
                'Site',
                ['option' => $option, 'base_path' => JPATH_SITE . '/components/' . $option],
                $app,
                $app->getInput()
            );

            $controller->execute('display');

            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            $app->scope = $scope;
        }
    }

    /**
     * Stop with a plain 403 — used for non-HTML formats and when the captcha is
     * unavailable and the site is set to fail closed.
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  never
     *
     * @since   0.1.0
     */
    private function sendBlocked(CMSApplicationInterface $app): never
    {
        $app->setHeader('Status', '403', true);
        $app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store, private', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $app->sendHeaders();

        echo Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_BLOCKED_MESSAGE');

        $app->close();

        exit;
    }

    /**
     * Something is protected but the captcha can't run, so protection isn't
     * doing what the admin thinks. Say so on the Home Dashboard and on every
     * Page Protector admin page, including its Options, so it can't be missed.
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    private function warnAdminIfCaptchaCantRun(CMSApplicationInterface $app): void
    {
        $input = $app->getInput();

        // Page views only: a POST (saving Options) or a task (e.g. the "Enable
        // the captcha plugin" link) redirects to a page view that warns if
        // it still needs to, so warning here too would show it twice or stale.
        if (
            $input->getMethod() !== 'GET' || $input->getCmd('task', '') !== ''
            || $input->getCmd('format', 'html') !== 'html' || $input->getCmd('tmpl') === 'component'
        ) {
            return;
        }

        $option = $input->getCmd('option', '');
        $view   = $input->getCmd('view', '');

        $relevant = match ($option) {
            '', 'com_cpanel'      => \in_array($view, ['', 'cpanel'], true) && $input->getCmd('dashboard', '') === '',
            'com_cspageprotector' => true,
            'com_config'          => $input->getCmd('component', '') === 'com_cspageprotector',
            default               => false,
        };

        if (!$relevant || !class_exists(ProtectionHelper::class) || !ComponentHelper::isEnabled('com_cspageprotector')) {
            return;
        }

        try {
            if (!$app->getIdentity() || !$app->getIdentity()->authorise('core.manage', 'com_cspageprotector')) {
                return;
            }

            $params  = ProtectionHelper::getParams();
            $pages   = ProtectionHelper::hasPageProtection($params);
            $modules = ProtectionHelper::hasModuleProtection($params);

            if (!$pages && !$modules) {
                return;
            }

            $captcha = CaptchaHelper::getConfiguredPlugin($params);
            $problem = CaptchaHelper::getProblem($captcha);

            $reason = match ($problem) {
                CaptchaHelper::PROBLEM_NONE        => '',
                CaptchaHelper::PROBLEM_POW_MISSING => Text::sprintf('PLG_SYSTEM_CSPAGEPROTECTOR_ADMIN_POW_MISSING', JVERSION),
                CaptchaHelper::PROBLEM_MISSING     => Text::sprintf('PLG_SYSTEM_CSPAGEPROTECTOR_ADMIN_CAPTCHA_MISSING', $captcha),
                default                            => Text::sprintf('PLG_SYSTEM_CSPAGEPROTECTOR_ADMIN_CAPTCHA_DISABLED', $captcha),
            };

            if ($reason === '') {
                return;
            }

            $parts = [$reason];

            if ($pages) {
                $parts[] = (string) $params->get('captcha_unavailable', 'allow') === 'allow'
                    ? Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_ADMIN_IMPACT_PAGES_OPEN')
                    : Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_ADMIN_IMPACT_PAGES_BLOCKED');
            }

            if ($modules) {
                $parts[] = Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_ADMIN_IMPACT_MODULES_LOCKED');
            }

            $links = [];

            // One click to fix the common case: the plugin is there, just switched off.
            // Joomla sanitises messages down to plain links, so this is a GET
            // carrying the form token, checked with checkToken('get').
            if ($problem === CaptchaHelper::PROBLEM_DISABLED && $app->getIdentity()->authorise('core.edit.state', 'com_plugins')) {
                $return  = base64_encode(Uri::getInstance()->toString(['path', 'query']));
                $this->alertButtonStyleNeeded = true;
                $links[] = '<a class="btn btn-sm btn-warning cspp-alert-btn mt-2 me-2" href="'
                    . htmlspecialchars(
                        'index.php?option=com_cspageprotector&task=dashboard.enablecaptcha&' . Session::getFormToken() . '=1&return=' . urlencode($return),
                        ENT_QUOTES,
                        'UTF-8'
                    )
                    . '">' . htmlspecialchars(Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_ADMIN_ENABLE_CAPTCHA'), ENT_QUOTES, 'UTF-8') . '</a>';
            }

            if (!($option === 'com_cspageprotector' && \in_array($view, ['', 'dashboard'], true))) {
                $links[] = '<a href="index.php?option=com_cspageprotector&amp;view=dashboard">'
                    . htmlspecialchars(Text::_('PLG_SYSTEM_CSPAGEPROTECTOR_ADMIN_OPEN_DASHBOARD'), ENT_QUOTES, 'UTF-8')
                    . '</a>';
            }

            $app->enqueueMessage(
                htmlspecialchars(implode(' ', $parts), ENT_QUOTES, 'UTF-8')
                . ($links ? '<br>' . implode(' ', $links) : ''),
                'warning'
            );
        } catch (\Throwable $e) {
            // A warning that can't be worked out is not worth breaking the admin for.
        }
    }

    /**
     * Should the gatekeeper run at all on this request?
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private function isActive(CMSApplicationInterface $app): bool
    {
        if (!$app->isClient('site')) {
            return false;
        }

        // The plugin can outlive the component (e.g. a half-finished uninstall).
        if (!class_exists(ProtectionHelper::class)) {
            return false;
        }

        return ComponentHelper::isEnabled('com_cspageprotector');
    }

    /**
     * isProtectedRequest() wrapped for the page-cache events, which may fire
     * before onAfterRoute's own check. A task let through that the
     * render-time safety net may replace counts too: what it shows depends
     * on the visitor, so it is never served from or stored in the cache.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private function isProtectedSafe(): bool
    {
        $app = $this->getApplication();

        if (!$this->isActive($app)) {
            return false;
        }

        try {
            $params = ProtectionHelper::getParams();

            // A feed fires no content event, so nothing else marks it as
            // differing per visitor, and the cache plugin may run before us.
            return ProtectionHelper::isProtectedRequest($app, $params)
                || ProtectionHelper::needsRenderCheck($app, $params)
                || $this->isUngatedFeed($app, $params)
                || $this->isContentFeed($app, $params);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Add a value to a result-aware event (J5 typed events and generic events alike).
     *
     * @param   EventInterface  $event  The event.
     * @param   mixed           $value  The result to add.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    private function addResult(EventInterface $event, mixed $value): void
    {
        if ($event instanceof ResultAwareInterface) {
            $event->addResult($value);

            return;
        }

        $result   = (array) $event->getArgument('result', []);
        $result[] = $value;
        $event->setArgument('result', $result);
    }
}
