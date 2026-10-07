# Changelog

All notable changes to Cybersalt Page Protector are documented here. Only released versions are listed. People whose ideas or reports shaped a change are credited on that entry.

## [0.1.0] - Unreleased (first version, in development)

### 🚀 New
- Proof-of-work check in front of selected menu items, using Joomla 6.1's core Proof-of-Work captcha through Joomla's captcha framework (any installed captcha plugin can be chosen instead).
- Protection modes: only the selected pages, or every page except the selected ones.
- "Also catch other routes to the same content": protected content reached through another menu item, a bare `index.php` link or a forged `Itemid` is still protected, including articles and subcategories inside a protected category.
- Challenge rendered in place, inside the site template, at the same URL. It starts and continues automatically, so a real visitor waits about half a second.
- Site-wide pass: a signed cookie (HMAC, bound to the browser and optionally the IP) with a configurable lifetime. Admins get a "Reset all passes" action, plus "Clear my pass" for testing in their own browser.
- Exemptions: logged-in users or chosen user groups, IP and CIDR allow-list, and search engines verified by forward-confirmed reverse DNS.
- Non-HTML formats (feeds, JSON, raw) on protected pages get a 403 instead of the content.
- Protected pages kept out of Joomla's page cache and sent with no-store headers.
- Dashboard: health checks, 24-hour stats linking to the filtered log, protected pages, most-challenged IPs, recent events, support panel.
- Event log with a filter for every column, full-width detail rows, CSV download, text dump, delete selected and Clear All.
- Click-to-copy on IP addresses. With shortened IPs it copies the firewall-ready range (`/24` for IPv4, `/48` for IPv6).
- Branded Options tabs, post-install card, custom ACL actions for viewing and managing the log.

### 🔍 Security / Privacy
- IP shortening on by default (GDPR-friendly): the log stores only the network part of each address. Saving Options with it on also shortens any full addresses already in the log. Protection still uses the full address during the request.
- No vendor or support mentions anywhere in front-end output.
- ACL gate on every admin controller method; CSRF token on every state-changing action.
- Return URL after the check is restricted to the same site (no open redirect).
- Proxy IP headers trusted only when explicitly configured.
- CSV export neutralises spreadsheet formulas in scraper-supplied values.
