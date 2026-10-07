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
