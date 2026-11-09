# Changelog

All notable changes to Cybersalt Page Protector are documented here. Only released versions are listed. People whose ideas or reports shaped a change are credited on that entry.

## [0.3.1] - 2026-11-08

### 🐛 Fixed
- In a component that has something protected, a sign-up or checkout form that hands the visitor on to a payment provider (OS Membership's PayPal sign-up, for example) showed visitors who hadn't passed the check instead of going on to pay, and passing the check didn't bring them back to the form. The same happened to such a form shown again with an error message. Now a visitor who hasn't passed gets the check before the form is sent on, and once they pass, the form is sent on with everything they filled in (except attached files, which have to be chosen again).

## [0.3.0] - 2026-11-08

### 🔍 Security
- "Also catch other routes to the same content" covers more ways of reaching a protected page: the detail pages of a protected list (a member directory's profile pages, say) whatever menu item the link uses, contacts and news feeds inside a protected category, and links that reach a page through a controller task instead of a view. A public menu item still opens its own page (#16).
- A protected menu item's language copies and Menu Item Aliases are protected too. In "Every page except" mode, an excluded page's language copies stay open.
- Contact lists, tag lists and feeds, the Smart Search feed, article modules and custom fields no longer show the content of protected articles or contacts to visitors who haven't passed.
- Pages that show protected content are kept out of Joomla's page cache.
- A request in a component that has something protected is checked again after the component has run, so a page that only shows up at that point gets the check too (#16).
- General hardening of how requests are matched to protected pages. If that matching ever fails with an error, the page now gets the check instead of opening.

### 🚀 New
- **Also protect these views** on the Protection tab: list `component:view` pairs, one per line (for example `com_osmembership:member`), and every page of that view is protected, whatever menu item the link uses or none. Use it for detail pages the automatic rule can't guess, such as K2 (`itemlist` → `item`) or Community Builder (`userslist` → `userprofile`). Entries that aren't `component:view` are refused when Options are saved, and so are pages that have to stay reachable without the check (login, registration, password reset, privacy, front-end site settings, payment returns and callbacks) and pages that are never checked (`com_ajax`, `com_cspageprotector`), each with the reason. The dashboard lists the protected views (#16).
- The dashboard warns when a protected menu item is unpublished, expired or deleted. Joomla doesn't load such an item, so it protects nothing, not even its content reached through other links.

### 🔧 Changed
- In a component that has something protected, a form that shows itself again with an error message, instead of redirecting back to its page, now gets the check for visitors who haven't passed. Joomla's own forms redirect, so they aren't affected. Point payment returns and callbacks at a page that isn't protected.
- In "Every page except" mode, a form submit or callback is left open when it is sent to an excluded page, by that page's address or its `Itemid`.

### 🐛 Fixed
- Tag menu items got the check on sites with strict routing on, even when no tag was protected. Tags are now compared one by one, so an excluded tag menu item opens in "Every page except" mode, and protecting one tag no longer puts the check on every public tag menu item.
- Installing or updating from the command line (`php cli/joomla.php extension:install --path=…` or `--url=…`) failed with "Unable to install extension" (0.2.1 too) on sites whose Live Site setting is a bare address without a path, such as `https://www.example.com`. The install card is now only shown on the administrator's installer page, and an error in it can no longer stop an install.
- A malformed pass cookie no longer logs a PHP warning. It simply counts as no pass.
- Ad, affiliate and campaign link parameters that end in `_id` (`campaign_id`, `ad_id`, `aff_id`, `click_id` and the like) were taken for record ids, so a public page reached from an ad could get the check. They're now ignored, unless one of the component's menu items carries that name in its link (#16).
- Joomla's Changelog button on System > Manage > Extensions opened an empty window, because the manifest pointed at `CHANGELOG.html` and Joomla can only read its XML format. The component and package now point at the changelog feed on the update server. Suggested by Phil.

## [0.2.1] - 2026-10-20

### 📦 Packaging
- The install zip now includes `CHANGELOG.md` and `CHANGELOG.html`, and the build refuses to run without them.
- Removed the `<targetplatform>` tag from the three install manifests. Joomla only reads it from update feeds, and the Joomla Extensions Directory checker flags it as unknown in an install manifest. The update feed still limits updates to Joomla 5 and 6.

### 🔧 Changed
- Code tidy-ups so the Joomla Extensions Directory malware scan has nothing to flag: two control-character filters are written as `[[:cntrl:]]` instead of hex escapes, and the check page decodes its return address once instead of twice. Behaviour is the same, except that the CSV download now also drops the DEL control character.
- The dashboard's Documentation and Changelog links now open the new documentation pages at docs.cybersalt.com instead of the GitHub README.

## [0.2.0] - 2026-10-10

### 🚀 New
- Clear warnings when no captcha can run. The install card (on install and on every update) says so and links to the fix. The dashboard check now says what it means for visitors right now: protected pages open to everyone (or an error for every visitor, depending on the setting) and protected modules locked. While something is protected and the captcha can't run, a warning also shows on the Home Dashboard and on every Page Protector admin page, including its Options. When the captcha plugin is only switched off, the warning has an "Enable the captcha plugin" button that switches it back on in one click. The README explains what to use on each Joomla version. Prompted by a question from Bjørn (#5).
- "No guarantee" notice: bots and hackers are persistent, Page Protector does its best but can't guarantee bad actors won't find another way around it, and if there's information you don't want online, don't put it online. It shows in a red box on the install card and at the top of every Page Protector admin page (dashboard, Event Log, every Options tab) until someone who can change Options clicks "I understand". That's once per site, and the dashboard then shows who accepted it and when. Also in the README and on the Protection tab (#14).

### 🐛 Fixed
- The install screen showed the description twice, once in Joomla's message box and again in the post-install card. Only the card is shown now (#2).
- "Reset all passes" now takes effect straight away on sites with Joomla's cache switched on. The new settings were saved, but the cached copy could keep old passes working until the cache expired.

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
