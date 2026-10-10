# CLAUDE.md - cs-page-protector

Project notes for Claude. Layers on top of `~/.claude/CLAUDE.md` and the vault's `CLAUDE.md`.

## What this repo is

Cybersalt Page Protector: a Joomla 5/6 package that puts a proof-of-work check in front of selected menu items and modules to slow down scrapers. **v0.2.0 released 2026-10-10** (GitHub + cs-Release-Manager; 0.1.0 was 2026-10-07). v1 uses Joomla 6.1's core Proof-of-Work captcha (`plg_captcha_powcaptcha`, ALTCHA-based) through Joomla's captcha framework, so other captcha plugins already work and new challenge types can be added later.

Vault note: `04.knowledge/cs-page-protector.md` - history, decisions, open items. Update it on every meaningful change.

## Layout

```
packages/
├── com_cspageprotector/            Component
│   ├── admin/src/Helper/           ALL protection logic lives here (shared by plugin + site)
│   │   ├── ProtectionHelper.php    Is this request protected? Is the visitor exempt?
│   │   ├── VerificationHelper.php  Session flag + signed pass cookie
│   │   ├── CaptchaHelper.php       Wrapper over Joomla\CMS\Captcha\Captcha
│   │   ├── BotVerifier.php         Forward-confirmed reverse DNS for search engines
│   │   ├── IpHelper.php            Client IP, CIDR matching, anonymising
│   │   ├── LogHelper.php           #__cspageprotector_log writes + pruning
│   │   └── NoticeHelper.php        "No guarantee" red box + per-site acceptance record
│   ├── admin/                      Dashboard, Event Log, Options (config.xml)
│   └── site/                       Challenge view + ChallengeController::verify
├── plg_system_cspageprotector/     Gatekeeper: onAfterRoute + page-cache events
└── pkg_cspageprotector/            Package manifest, script.php (enables plugins, seeds ACL, install card)
```

## How a protected request flows

1. `onAfterRoute` asks `ProtectionHelper::isProtectedRequest()` (memoised; also used by the page-cache events, which can fire first).
2. Exempt (IP / user / group / verified bot) or valid pass -> normal page.
3. Non-HTML format -> plain 403.
4. Otherwise the plugin rewrites the input to `option=com_cspageprotector&view=challenge` and stores the original URL in `cspp_return`. Same URL, same Itemid, same template.
5. The form POSTs to `task=challenge.verify`; on success `VerificationHelper::markVerified()` and a 303 back.

`com_cspageprotector` and `com_ajax` plugin calls are never challenged (the PoW widget fetches its challenge through `com_ajax&plugin=powcaptcha&group=captcha`). `com_ajax&module=` calls into a protected module type get a 403 for visitors without a pass.

"Every page except" mode (`all_except`) leaves open only what was excluded: `ProtectionHelper::isExcludedRequest()` matches option/view/id/layout exactly, or an article/category inside an excluded com_content category. Never trust the `Itemid` alone.

### Content gate (other routes to protected articles)

With "also catch other routes" on, `guardSharedContent()` 403s non-HTML com_content requests (feeds) that would include a protected article, and `onContentPrepare` replaces a protected article's text with `PLG_SYSTEM_CSPAGEPROTECTOR_CONTENT_LOCKED` in `com_content.*`, `com_tags.*` and `mod_articles*` contexts. It fails closed. Smart Search snippets aren't covered (stored at index time).

## Gotchas found while building

- The PoW plugin only registers with `CaptchaRegistry` when it's **enabled**; `Captcha::getInstance()` on a disabled one falls into the legacy path and renders nothing. Always check `PluginHelper::isEnabled('captcha', ...)` first (`CaptchaHelper::isAvailable()`).
- `plg_system_cache` serves cached pages from its own `onAfterRoute`. We answer `onPageCacheSetCaching` with `false` for protected requests so it neither serves nor stores them.
- Legacy captcha plugins (e.g. J5 reCAPTCHA) read their own POST field when `checkAnswer(null)`; passing `''` makes them fail. `ChallengeController` passes `null` when our field is empty.
- `pass_salt` must be a field in `config.xml` (hidden) or com_config drops it on the next Options save.
- Filter-only keys (`since`, `ua`, `menu_item`) are in `filter_fields` so searchtools shows them as active; ordering uses a separate `ORDERABLE` allowlist.
- **Joomla's view cache (`com_content`) and module cache would hand a verified visitor's render to a scraper.** While module or content protection is in use, the plugin sets `caching` to 0 for the request and forces `cache=0`/`owncache=0` on protected modules. Page cache is handled separately by the page-cache events.
- **Proxy headers are only believed from a trusted hop:** Cloudflare's published ranges for the Cloudflare source, otherwise the `trusted_proxies` list (private/loopback when it's empty). X-Forwarded-For is walked right to left, skipping trusted hops. `IpHelper::getClientIp()`.
- **Search-engine DNS checks are budgeted** (`BotVerifier::LOOKUPS_PER_MINUTE`, plus a negative cache per /24 or /64), so fake Googlebot UAs can't flood DNS.
- **Return URLs go through `ProtectionHelper::safeReturnUrl()`** (exact host and port, or a `/path`). Use it for any new redirect.
- **Writing component params outside an Options save: use `ProtectionHelper::saveParams()`.** With caching on, `ComponentHelper` reads params from the `_system` cache group, so a bare `UPDATE #__extensions` doesn't show until that cache expires. `saveParams()` cleans `_system` in every cache base. Any value written this way also needs a hidden field in `config.xml`, or the next Options save drops it.
- **"No guarantee" notice (#14, Tim):** red box at the top of every Page Protector admin page and on the install card until accepted, once per site, by someone with `core.options`. Recorded in params `notice_accepted_by/_name/_at`. Options and the installer wrap everything in one form whose hidden `task` field beats a `formaction` URL, so the accept button builds its own POST form in JS with the session token.
- **Admin warning when the captcha can't run (#5):** the system plugin enqueues it on the Home Dashboard and every Page Protector admin page, including its own dashboard and Options (Tim: "that's where people need to read it"). Page views only: not on POSTs or `task=` requests, or it shows stale after the redirect. When the plugin is only disabled it carries an "Enable the captcha plugin" link (`dashboard.enablecaptcha`, needs `core.edit.state` on com_plugins). **Joomla sanitises message HTML (`Joomla.sanitizeHtml`)**: buttons and forms are stripped, links survive, so it's a GET with the form token in the URL (`checkToken('get')`). Enabling a plugin must clean the `com_plugins` cache group (`ProtectionHelper::cleanCacheGroup()`).
- Admin views call `PermissionHelper::requireView()` themselves, because `task=<view>.display` skips the DisplayController check.
- **Browser testing from Windows Playwright needs `http://localhost:8086`**, not the WSL IP: ALTCHA needs Web Crypto, which needs a secure context.

## Joomla version posture

J5 + J6 (`5\.[0-9]+|6\.[0-9]+`). Core PoW needs 6.1+; the dashboard says so on older sites.

## Build

```powershell
.\build-package.ps1
```

Checks for BOMs, version agreement across the three manifests and empty folders, then builds `pkg_cspageprotector_v{version}_{timestamp}.zip`. CI (`.github/workflows/ci.yml`) runs `php -l` on 8.1–8.4, phpcs with `phpcs.xml` (PSR-12 minus `PSR1.Files.SideEffects` and `Generic.Files.LineLength`, the way Joomla 4+ core does it; `composer lint:phpcs` locally), xmllint and the same structure checks.

## Releasing

Updates are served by **cs-Release-Manager on cybersalt.com** (package 31, element `pkg_cspageprotector`), not a GitHub `updates.xml`. The flow lives in the vault: `04.knowledge/cybersalt-com/release-manager-publish-flow.md`. In short:

1. Bump the version in all three manifests, move the changelog entry (md + html) to the new version.
2. Build, copy to `pkg_cspageprotector_v{version}.zip`, note the SHA-256.
3. Commit, push, wait for CI green, tag `v{version}`, `gh release create` with the clean zip only.
4. Web Services multipart upload to `/api/index.php/v1/csreleasemanager/packageversions` (`is_latest=1`, `is_stable=1`, min Joomla 5.0, PHP 8.1, release notes from a file), check the returned SHA-256 matches.
5. MCP `update_release_manager_package_version` with `max_joomla_version=6`.
6. Verify `api.updatexml` shows `(5|6)\.[0-9]+`, the `api.userdownload` hash matches, and an install from the live URL works.

The cybersalt.com article is 868 (`/extensions/page-protector`); its `{cs-download}` shortcode picks up the new version by itself.

## Languages

17 languages (en-GB, cs-CZ, de-DE, el-GR, es-ES, fr-FR, it-IT, ja-JP, nb-NO, nl-NL, nn-NO, pl-PL, pt-BR, ru-RU, sv-SE, tr-TR, zh-CN). Any new or changed en-GB key must be translated into all 16 others before release. Keep key order the same as en-GB, keep placeholders/HTML identical, no BOM, LF endings. The manifests' `<languages>` blocks must list every folder on disk.

## Versioning (Tim, 2026-10-07)

One version number per unreleased cycle. Rebuilds keep the same version, and the zip timestamp tells them apart. The changelog lists only released versions plus a single "Unreleased" entry for the version in progress (start one for the next version when work on it begins). Bump only when a build goes to someone else (client site, tester, release). No upgrade-migration code or `sql/updates` stubs for versions that were never released.

## Credit people in the changelog (Tim, 2026-10-07)

When an issue or idea from someone is acted on, the changelog entry that ships it names them by **first name only** (Tim, 2026-10-07), in both `CHANGELOG.md` and `CHANGELOG.html`. Format: end the bullet with `Idea from Bjørn (#4).` (or `Suggested by …` / `Reported by …`), and for joint ideas `Ideas from Bjørn and Julie (#1).` Credit only what actually shipped, in the release that ships it. The person who suggested an issue is named in its body ("Idea from …") or in a comment; check both before writing the entry.

Credits map for the open issues (keep this updated as issues are filed):

| Issue | Credit |
|---|---|
| #1 Email and phone number protection | Bjørn; visual obfuscation approach: Julie (comment on #1) |
| #3 Protect information in modules | Bjørn (shipped and credited in 0.1.0, closed) |
| #4 Partial email cloaking | Bjørn, building on Julie's visual obfuscation idea |
| #5 Warn when no captcha is available | Bjørn (question; shipped and credited in 0.2.0, closed) |
| #9 Turnstile and other captchas | Tim for now; Bjørn and Julie also asked for it on stream (WMW #355). Ask Tim before crediting |
| #13 Send failed visitors to an info page | Bjørn |
| #2, #6, #7, #8, #10, #11, #12, #14, #15 | Tim (no credit line needed) |

## Module protection (#3, Bjørn)

- `onAfterModuleList` drops protected modules (hide mode, and always on the challenge page so there's never a second captcha). `onRenderModule` swaps content for the placeholder *before* chrome, so the module keeps its title/box. Both Joomla 5.4 and 6.1 read the list back with `getArgument('modules')` after dispatch.
- The normal path is the **inline check** (last bullet). The placeholder's "Show content" button is also a link to `index.php?option=com_cspageprotector&view=challenge&tmpl=component&cspp_return=<b64>` (plain non-SEF so the base64 survives), used as the fallback for non-PoW captchas and no-JS. `tmpl=component` matters: without it the check renders inside the page's full layout (hero, other modules) and looks like the page asking a second time (Tim hit this on j6.basicjoomla.com/stageit). The challenge view logs those as `challenged` with details `module`; in-place page challenges set `cspp_inplace=1` so they aren't logged twice. Inline passes POST `cspp_source=module` and are logged as `passed` with details `module`.
- `{loadmodule}` in an article goes through `onRenderModule` too, so it gets the placeholder.
- Pages with a protected module answer `onPageCacheIsExcluded` → never stored in the page cache. Saving Options cleans the `page` cache group.
- **Gotcha: Joomla 6 keeps the site cache in `administrator/cache`** (J5: `/cache`). Clean both. Also, when testing with direct DB edits and global caching on, component params come from the `_system` cache; clear it (`php cli/joomla.php cache:clean`) or results look like leaks that aren't there.
- **Inline module check (Tim's expectation):** clicking "Show content" must run the check *inside the module* and just reload the page, not send the visitor to another screen. `renderModulePlaceholder()` prints a small verify form per placeholder plus, once per page, the PoW widget inside an inert `<template>`; `media/js/module.js` clones it into the clicked box only (one captcha per page), solves, copies `event.detail.payload` into the form (ALTCHA race) and submits. Non-PoW captchas and no-JS fall back to the `tmpl=component` check page.
