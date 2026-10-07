# CLAUDE.md - cs-page-protector

Project notes for Claude. Layers on top of `~/.claude/CLAUDE.md` and the vault's `CLAUDE.md`.

## What this repo is

Cybersalt Page Protector: a Joomla 5/6 package that puts a proof-of-work check in front of selected menu items to slow down scrapers. v1 uses Joomla 6.1's core Proof-of-Work captcha (`plg_captcha_powcaptcha`, ALTCHA-based) through Joomla's captcha framework, so other captcha plugins already work and new challenge types can be added later.

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
│   │   └── LogHelper.php           #__cspageprotector_log writes + pruning
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

`com_ajax` and `com_cspageprotector` are never intercepted (the PoW widget fetches its challenge through `com_ajax&plugin=powcaptcha&group=captcha`).

## Gotchas found while building

- The PoW plugin only registers with `CaptchaRegistry` when it's **enabled**; `Captcha::getInstance()` on a disabled one falls into the legacy path and renders nothing. Always check `PluginHelper::isEnabled('captcha', ...)` first (`CaptchaHelper::isAvailable()`).
- `plg_system_cache` serves cached pages from its own `onAfterRoute`. We answer `onPageCacheSetCaching` with `false` for protected requests so it neither serves nor stores them.
- Legacy captcha plugins (e.g. J5 reCAPTCHA) read their own POST field when `checkAnswer(null)`; passing `''` makes them fail. `ChallengeController` passes `null` when our field is empty.
- `pass_salt` must be a field in `config.xml` (hidden) or com_config drops it on the next Options save.
- Filter-only keys (`since`, `ua`, `menu_item`) are in `filter_fields` so searchtools shows them as active; ordering uses a separate `ORDERABLE` allowlist.

## Joomla version posture

J5 + J6 (`5\.[0-9]+|6\.[0-9]+`). Core PoW needs 6.1+; the dashboard says so on older sites.

## Build

```powershell
.\build-package.ps1
```

## Languages

en-GB only during the pre-release test loop (Brain wishlist timing exception). Add the other 16 languages before the first published release.

## Versioning (Tim, 2026-10-07)

One version number per unreleased cycle. Rebuilds keep the same version, and the zip timestamp tells them apart. The changelog lists only released versions plus a single "Unreleased" entry for the version in progress. Bump only when a build goes to someone else (client site, tester, release). No upgrade-migration code or `sql/updates` stubs for versions that were never released.

## Credit people in the changelog (Tim, 2026-10-07)

When an issue or idea from someone is acted on, the changelog entry that ships it names them by **first name only** (Tim, 2026-10-07), in both `CHANGELOG.md` and `CHANGELOG.html`. Format: end the bullet with `Idea from Bjørn (#4).` (or `Suggested by …` / `Reported by …`), and for joint ideas `Ideas from Bjørn and Julie (#1).` Credit only what actually shipped, in the release that ships it. The person who suggested an issue is named in its body ("Idea from …") or in a comment; check both before writing the entry.

Credits map for the open issues (keep this updated as issues are filed):

| Issue | Credit |
|---|---|
| #1 Email and phone number protection | Bjørn; visual obfuscation approach: Julie (comment on #1) |
| #3 Protect information in modules | Bjørn |
| #4 Partial email cloaking | Bjørn, building on Julie's visual obfuscation idea |
| #5 Warn when no captcha is available | Bjørn (question) |
| #2, #6, #7, #8, #9, #10, #11 | Tim (no credit line needed) |

## Module protection (#3, Bjørn)

- `onAfterModuleList` drops protected modules (hide mode, and always on the challenge page so there's never a second captcha). `onRenderModule` swaps content for the placeholder *before* chrome, so the module keeps its title/box. Both Joomla 5.4 and 6.1 read the list back with `getArgument('modules')` after dispatch.
- The placeholder links to `index.php?option=com_cspageprotector&view=challenge&tmpl=component&cspp_return=<b64>` (plain non-SEF so the base64 survives). `tmpl=component` matters: without it the check renders inside the page's full layout (hero, other modules) and looks like the page asking a second time (Tim hit this on j6.basicjoomla.com/stageit). The challenge view logs those as `challenged` with details `module`; in-place page challenges set `cspp_inplace=1` so they aren't logged twice.
- Pages with a protected module answer `onPageCacheIsExcluded` → never stored in the page cache. Saving Options cleans the `page` cache group.
- **Gotcha: Joomla 6 keeps the site cache in `administrator/cache`** (J5: `/cache`). Clean both. Also, when testing with direct DB edits and global caching on, component params come from the `_system` cache; clear it (`php cli/joomla.php cache:clean`) or results look like leaks that aren't there.
- **Inline module check (Tim's expectation):** clicking "Show content" must run the check *inside the module* and just reload the page, not send the visitor to another screen. `renderModulePlaceholder()` prints a small verify form per placeholder plus, once per page, the PoW widget inside an inert `<template>`; `media/js/module.js` clones it into the clicked box only (one captcha per page), solves, copies `event.detail.payload` into the form (ALTCHA race) and submits. Non-PoW captchas and no-JS fall back to the `tmpl=component` check page.
