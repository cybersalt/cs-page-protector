# Logo - cs-page-protector

Two variants live in `packages/com_cspageprotector/media/images/` and install to `media/com_cspageprotector/images/`:

- **`logo.svg`** - duotone: cobalt `#0102E1` browser window, orange `#FE9904` padlock corner badge
- **`logo-mono.svg`** - all-cobalt fallback

Both are 1200×1200 intrinsic SVGs (Tabler 24-unit paths wrapped in `scale(50)`), per the cs-* extension-logo convention.

## What it is

Tabler Icons `browser` (MIT licensed) with a hand-drawn padlock badge in the top-right corner. The browser window reads "a web page"; the padlock reads "locked behind a check". Same base-icon-plus-corner-badge construction as the rest of the family (template + shield, news + bolt, server + sparkle).

## Source of truth

The canonical copy belongs in the vault at `04.knowledge/cybersalt-com/branding/extension-logos/cs-page-protector.svg` (+ `-mono`). Update it there first, then copy here.

## Where it's used

- Post-install card (package `script.php`)
- Branded header on every Options tab (`BrandheaderField`)
- Dashboard support panel
- README
