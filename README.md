# Pulse Analytics for TYPO3

Privacy-first analytics for a TYPO3 site, in one extension. No cookies, no
personal data, and the script it adds to your pages is under 3 KB.

[Pulse Analytics](https://pulse.ciphera.net) is built by
[Ciphera](https://ciphera.net), a company in Belgium, and hosted in Europe.

## Requirements

TYPO3 13.4 LTS or 14 LTS, PHP 8.2 or later. No database tables, no dependencies
beyond TYPO3 core. You need a Pulse Analytics account with a site registered for
the same domain; the free plan is enough to start.

## Install

```bash
composer require ciphera/pulse-analytics
vendor/bin/typo3 extension:setup
```

That is the whole setup. The tracker uses each visitor's own hostname by
default, so a single-site or multi-site installation both work with no
configuration at all.

To pin a domain explicitly, go to **Admin Tools → Settings → Configure
Extensions → pulse_analytics**.

## Settings

| Setting | Default | What it does |
|---|---|---|
| Domain | empty | The domain this site is registered under in Pulse. **Leave empty on a multi-site installation** — see below. |
| Record clicks, copies and form submits | off | Loads the companion script. A second, separate request — see below. |
| Custom API origin | empty | Route events through your own proxy origin. A bare origin, not a full URL. |

🔑 **Leave Domain empty if this TYPO3 serves more than one site.** Extension
configuration is installation-wide, so a pinned domain would label every site's
traffic as that one domain. Empty means the tracker reads the browser's own
hostname, which is the correct answer for every site independently.

## Four things worth knowing

**It runs on the front end only, never in the TYPO3 backend.** The backend uses
the same `PageRenderer` and the same `AssetRenderer` as the front end, so this
is an explicit `ApplicationType` guard rather than something that comes free —
and there is a test that fails if it is removed.

**What lands in your pages is the literal tag:**

```html
<script defer="defer" data-domain="example.com" src="https://js.ciphera.net/script.js"></script>
```

No inline JavaScript, so **a strict Content-Security-Policy needs no nonce and
no `unsafe-inline` for this extension** — only `script-src https://js.ciphera.net`
and `connect-src https://pulse-api.ciphera.net`.

**No cache-busting query is appended to the script URL.** TYPO3 leaves a
fully-qualified external URL alone, and there is an assertion that keeps it that
way — appending a per-installation query to a third-party CDN URL would make
every TYPO3 site request a distinct URL from ours.

**The interaction capture is a second request on purpose.** The core script's
size is a published claim, and nothing is folded into it to make a feature look
free.

## Changing settings needs a cache flush

The tag is part of the rendered page, so pages already in the page cache keep
the old one. Flush the frontend cache after changing the domain:

```bash
vendor/bin/typo3 cache:flush
```

TYPO3 has no per-setting cache tag for extension configuration, so this is
manual. A stale tag still reports data — just under the previous domain — which
is exactly the kind of thing nobody notices, so it is worth doing deliberately.

## A mistyped domain is dropped, never injected

If the Domain setting holds something that cannot be a hostname, the extension
omits `data-domain` and lets the tracker auto-detect, rather than injecting
rubbish into your pages. A working install one attribute short beats a broken
one.

## What Pulse Analytics measures

Pageviews, referrers, countries, devices, time on page and scroll depth, plus
goals, funnels and campaigns. It sets no cookies and stores no personal data.
Visitors whose browser sends Do Not Track or Global Privacy Control are not
counted at all — so if you test in Brave or Firefox and see nothing, that is the
tracker behaving correctly. Safari is the easiest browser to verify an install
in.

## Licence

**GPL-2.0-or-later**, and that is not a choice we made.

Every other public Pulse integration — Astro, Docusaurus, Framer, Google Tag
Manager — is Apache-2.0. This extension is not, because TYPO3's licensing
position is that TYPO3 and installed extensions "form a single combined
program", so "your extensions fall under the GNU General Public License, version
2 or later"; the `composer.json` reference adds that the `license` key "has to be
GPL-2.0-only or GPL-2.0-or-later". Apache-2.0 is compatible with GPLv3 but not
GPLv2, so it cannot satisfy that. The Drupal and Joomla integrations are GPL for
the same kind of reason. The divergence is deliberate and unavoidable, not drift.

TYPO3 is a registered trademark of the TYPO3 Association. This is an independent
extension and is not affiliated with or endorsed by the TYPO3 project or the
TYPO3 Association.
