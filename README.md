<img src="packages/com_cspageprotector/media/images/logo.svg" width="96" alt="Cybersalt Page Protector logo">

# Cybersalt Page Protector

Put a proof-of-work check in front of the Joomla pages you choose, so scrapers have to spend real computing time before they get your content.

A real visitor sees a short "Checking your browser" step once. Their browser solves a small puzzle in about a second, they get a pass, and they browse every protected page normally until the pass expires. A scraper pulling thousands of pages has to solve that puzzle again and again. Scrapers that don't run JavaScript or keep cookies never get the content at all.

## What it does

- **Protect by menu item.** Pick the menu items to protect, or protect the whole site and pick the ones to leave open.
- **Catches other routes to the same content.** A protected article reached through a different menu item or a bare `index.php` link is still protected, and so is anything inside a protected category.
- **Uses Joomla's own captcha system.** Joomla 6.1's core Proof-of-Work captcha is the default: no third-party service, nothing to click. Any other installed Joomla captcha plugin can be used instead, and more challenge options are planned.
- **Visitors see your own template.** The URL and menu item stay the same and the check shows inside the site's normal template, then sends the visitor back to the page they asked for.
- **One pass covers the site.** The pass is a signed cookie tied to the browser (and, if you want, the IP). You can reset every pass at once from the dashboard.
- **Sensible exemptions.** Logged-in users or chosen user groups, allow-listed IPs and CIDR ranges, and verified search engines (Google, Bing, Apple, Yandex, Baidu, Yahoo). Search engines are checked with forward-confirmed reverse DNS, so a scraper that only claims to be Googlebot is still challenged. AI crawlers such as GPTBot and ClaudeBot are not exempt.
- **Feeds and JSON can't leak content.** Non-HTML requests for protected pages get a plain 403 instead of the content.
- **Kept out of caches.** Protected pages are excluded from Joomla's page cache and sent with no-store headers.
- **Dashboard and event log.** Health checks, 24-hour stats, the most-challenged IPs (copy one to block it at your firewall), and a full event log with filters, CSV download and a text dump for support tickets.

## Requirements

- Joomla 5.x or 6.x (PHP 8.1+). Joomla 6.x needs PHP 8.3+.
- **Joomla 6.1 or later for the core Proof-of-Work captcha.** On Joomla 5 or 6.0, choose another installed captcha plugin.
- MySQL or MariaDB.

## Installation

1. Download `pkg_cspageprotector_v*.zip` from the [releases page](https://github.com/cybersalt/cs-page-protector/releases).
2. In Joomla, go to **System → Install → Extensions** and upload the zip.
3. The installer enables the gatekeeper plugin and, on Joomla 6.1+, switches on the core **CAPTCHA - Proof of Work** plugin so the check can run. Enabling it only makes it available; it doesn't change the captcha your other forms use.

## Configuration

**Components → Cybersalt Page Protector → Options**

| Tab | What's there |
|---|---|
| Protection | Protect selected pages or everything except selected pages; the menu item picker; "also catch other routes to the same content". |
| Challenge | Captcha plugin, what to do if it's unavailable, auto-start and auto-continue, how long a pass lasts, tie pass to IP, HTTP status of the check page, heading and message. |
| Exemptions | Logged-in users, user groups, verified search engines, allow-listed IPs, and where the visitor IP comes from (direct, Cloudflare, X-Forwarded-For, X-Real-IP). |
| Logging | Event log on/off, shorten IPs (on by default, GDPR-friendly), retention days. |
| Support | Support email, page and name shown on the dashboard. Nothing support-related is ever shown to site visitors. |
| Permissions | Who can view the dashboard and log, and who can delete log entries. |

The difficulty of the proof-of-work puzzle is set in the **CAPTCHA - Proof of Work** plugin's own settings (Easy / Moderate / Hard / Custom).

**Behind Cloudflare or another proxy?** Set *Visitor IP comes from* to match, otherwise every visitor looks like the proxy's IP. The dashboard warns you if it spots Cloudflare headers.

## What it can and can't do

Proof of work raises the cost of scraping; it doesn't make scraping impossible. A determined scraper running a real headless browser can solve the puzzle. It just has to pay for it on every pass it collects, and every pass is tied to its browser fingerprint. Pair it with firewall blocks on the IPs the dashboard flags.

Protection is by menu item. Content that a visitor can reach with no menu item at all, or that's shown in a module on an unprotected page, isn't covered.

## How it works

```
Request ──► System plugin (onAfterRoute)
              │  protected page?  ── no ──► normal page
              │  exempt (IP / user / verified bot)? ── yes ──► normal page
              │  valid pass (session or signed cookie)? ── yes ──► normal page
              │  non-HTML format? ── yes ──► 403
              ▼
            Challenge view rendered in place (same URL, same template)
              │  captcha solved in the browser, form POSTs to challenge.verify
              ▼
            Captcha checked through Joomla's captcha framework
              ├─ pass ──► signed pass cookie + session flag ──► back to the page
              └─ fail ──► message, challenge shown again
```

## Development

```bash
composer install
composer lint:php
composer lint:phpcs
```

This repo includes [Joomla-Brain](https://github.com/cybersalt/Joomla-Brain) as a submodule at `.joomla-brain`. Clone with `--recurse-submodules`, or run `git submodule update --init` after cloning.

## Building

```powershell
.\build-package.ps1
```

Creates `pkg_cspageprotector_v{version}_{YYYYMMDD}_{HHMM}.zip` in the repo root. Uses 7-Zip (never `Compress-Archive`), refuses to build if any PHP/XML/INI file has a UTF-8 BOM, if manifest versions disagree, or if the package contains an empty folder.

## Layout

```
packages/
├── com_cspageprotector/        Component: admin dashboard, log, options; site challenge view; shared helpers
├── plg_system_cspageprotector/ System plugin: the gatekeeper
└── pkg_cspageprotector/        Package manifest, install script, post-install card
```

## License

GNU General Public License version 2 or later. See [LICENSE](LICENSE).

## Author

[Cybersalt](https://cybersalt.com) · support@cybersalt.com
