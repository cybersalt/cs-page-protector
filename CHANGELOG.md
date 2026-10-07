# Changelog

All notable changes to Cybersalt Page Protector are documented here. Only released versions are listed. People whose ideas or reports shaped a change are credited on that entry.

## [0.1.0] - 2026-10-07

### 🚀 New
- Proof-of-work check in front of selected menu items, using Joomla 6.1's core Proof-of-Work captcha through Joomla's captcha framework (any installed captcha plugin can be chosen instead).
- Protection modes: only the selected pages, or every page except the selected ones.
- "Also catch other routes to the same content": protected content reached through another menu item, a bare `index.php` link or a forged `Itemid` is still protected, including articles and subcategories inside a protected category. Blog, featured and tag lists and article modules show a short notice in place of a protected article's text, and feeds that would include it get a 403.
- **Protect modules**, not just pages: pick modules or whole module positions on the new Modules tab. Visitors who haven't passed see a placeholder with a "Show content" button, or nothing at all. Clicking the button runs the check right inside the module and reloads the page with the content; one pass unlocks every protected module. A page never shows more than one captcha. Pages carrying a protected module are kept out of the page cache. Idea from Bjørn (#3).
- Challenge rendered in place, inside the site template, at the same URL. It starts and continues automatically, so a real visitor waits about half a second.
- Site-wide pass: a signed cookie (HMAC, bound to the browser and optionally the IP) with a configurable lifetime. Admins get a "Reset all passes" action, plus "Clear my pass" for testing in their own browser.
- Exemptions: logged-in users or chosen user groups, IP and CIDR allow-list, and search engines verified by forward-confirmed reverse DNS.
- Non-HTML formats (feeds, JSON, raw) on protected pages get a 403 instead of the content.
- Protected pages kept out of Joomla's page cache and sent with no-store headers.
- Dashboard: health checks, 24-hour stats linking to the filtered log, protected pages, most-challenged IPs, recent events, support panel.
- Event log with a filter for every column, full-width detail rows, CSV download, text dump, delete selected and Clear All.
- Click-to-copy on IP addresses. With shortened IPs it copies the firewall-ready range (`/24` for IPv4, `/48` for IPv6).
- Branded Options tabs, post-install card, custom ACL actions for viewing and managing the log.
- 17 languages: English, Dutch, German, Swedish, Norwegian (Bokmål and Nynorsk), Spanish, French, Italian, Brazilian Portuguese, Russian, Polish, Czech, Greek, Japanese, Simplified Chinese and Turkish.

### 🔍 Security / Privacy
- IP shortening on by default (GDPR-friendly): the log stores only the network part of each address. Saving Options with it on also shortens any full addresses already in the log. Protection still uses the full address during the request.
- No vendor or support mentions anywhere in front-end output.
- ACL gate on every admin controller method; CSRF token on every state-changing action.
- Return URL after the check is restricted to the same site, matching host and port exactly (no open redirect).
- Proxy IP headers are only believed when the request comes from Cloudflare's address ranges or from a listed trusted proxy, and X-Forwarded-For is read from the right, so a forged header can't impersonate an allow-listed IP.
- "Every page except" mode only leaves open exactly what was excluded; an article outside an excluded category is still checked, whatever `Itemid` is sent.
- Joomla's view and module caches are bypassed while modules or content are protected, and AJAX calls into protected module types are refused, so a cached or AJAX copy never reaches a visitor who hasn't passed.
- Search engine verification is rate-limited and caches failures per network, so fake crawler user agents can't flood DNS lookups.
- CSV export neutralises spreadsheet formulas in scraper-supplied values.
