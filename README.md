# Cybersalt Page Protector

A Joomla 5/6 package that puts a challenge page in front of selected pages on your site, so scrapers have to prove themselves before they get the content.

> **Status: in development (v0.1.0).** Not yet installable. This README will grow as the extension does.

## What it does

When a visitor asks for a protected page, the system plugin checks whether they're exempt or have already passed. If not, they're sent to a challenge page. Once they solve it they go straight back to the page they asked for, and they aren't asked again until their pass expires.

- **Choose what's protected.** Either protect only the menu items you pick, or protect everything except the ones you pick.
- **Uses Joomla's own captcha framework.** The default is Joomla 6.1's core Proof-of-Work captcha, which needs no third-party account and asks real visitors to do nothing but wait a moment. Any other Joomla captcha plugin (reCAPTCHA, Turnstile, hCaptcha and so on) can be swapped in from the options.
- **Search engines aren't blocked.** Verified search-engine crawlers pass straight through, so protected pages can still be indexed.
- **Allowlists.** Exempt specific IP addresses or CIDR ranges (IPv4 and IPv6), logged-in users, or whole user groups.
- **Signed pass cookie.** A visitor who passes gets an HMAC-signed cookie tied to their browser (and optionally their IP), so a pass taken from one client can't be reused by a fleet of others. One click in the admin resets every outstanding pass.
- **Safe behind proxies.** Proxy headers such as `X-Forwarded-For` are only trusted when you say the site sits behind a proxy, so a scraper can't fake an allowlisted address.
- **Activity log.** Challenges, passes and failures are logged, with optional IP anonymisation and a retention window.

## Requirements

- Joomla 5.x or 6.x
- PHP 8.1 or higher
- For the default challenge: Joomla 6.1 or later (core Proof-of-Work captcha). On older versions, choose another captcha plugin.

## Package contents

| Extension | Purpose |
|---|---|
| `com_cspageprotector` | Admin dashboard, options, activity log, and the front-end challenge page |
| `plg_system_cspageprotector` | Intercepts requests for protected pages and sends unverified visitors to the challenge |

## Development

```bash
composer install
composer lint:php
composer lint:phpcs
```

This repo includes [Joomla-Brain](https://github.com/cybersalt/Joomla-Brain) as a submodule at `.joomla-brain`. Clone with `--recurse-submodules`, or run `git submodule update --init` after cloning.

## Support

[Cybersalt Consulting Ltd.](https://cybersalt.com) · support@cybersalt.com

## License

GNU General Public License version 2 or later. See [LICENSE](LICENSE).
