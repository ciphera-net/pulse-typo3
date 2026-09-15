# Releasing pulse_analytics (TYPO3)

## Verified against real installs, 15-09-2026

`./scripts/verify.sh <major>` creates a TYPO3 base distribution, installs it
against SQLite with a real site configuration, installs this extension, serves
the site and reads the tag back out of the emitted HTML. Five cases:

| TYPO3 | Result |
|---|---|
| **14.3.7** (current LTS) | ✅ all five, three consecutive runs |
| **13.4** (older LTS) | ✅ all five, two consecutive runs |

The cases: the tag with a domain and **no query appended to the CDN url**;
domain + API origin + companion, with the companion carrying no domain of its
own; no domain configured meaning auto-detect rather than an empty attribute; an
invalid domain dropped rather than injected; and **zero** references to
`ciphera` in the TYPO3 backend while the front end still has them.

```bash
./scripts/verify.sh 14
HTTP_PORT=18101 ./scripts/verify.sh 13
```

Needs `php` 8.2+ and `composer`. Uses SQLite, so no database server is required.
⚠️ The driver name is **`sqlite`**, not `pdo_sqlite` — the setup command rejects
the Doctrine name outright.

**The backend guard is mutation-tested.** Replacing the `ApplicationType` check
with a no-op makes the run report `backend references: 2 (want 0)` — the tracker
really does reach the TYPO3 backend without it.

## 🔴 Four surfaces, one intent, four different byte-strings

This is why the harness renders a page instead of reading the source:

| Surface | What we pass | What ships |
|---|---|---|
| Drupal | `'defer' => TRUE` | `<script defer …>` |
| Joomla | `'defer' => true` | `<script … defer …>`, and it wanted to append `?mediaVersion` |
| Docusaurus | `{defer: true}` | `<script … defer="defer" …>` — its **HTML minifier** rewrote a bare `defer` |
| **TYPO3** | `'defer' => 'defer'` | `<script defer="defer" …>` |

TYPO3's `GeneralUtility::implodeAttributes()` has **no bare-attribute path at
all** — every attribute is emitted as `name="value"` — so a boolean `true` would
be cast to `"1"` and render `defer="1"`. Valid HTML, since any value makes a
boolean attribute true, and it would never have been reported. Hence the string.

⚠️ The same function **drops any attribute whose value casts to an empty
string**, because `$keepBlankAttributes` is false. An absent domain is therefore
handled by not adding the key, not by adding an empty one — the two coincide
today, and relying on the coincidence would break the moment somebody passes a
boolean.

✅ **TYPO3 leaves a fully-qualified external URL alone.** `UriResource`'s
`getPublicUri()` returns the identifier's URI unchanged and `isPublished()` is
always true, so nothing is appended. That is asserted rather than assumed,
because **Joomla did append a cache-buster** in exactly this position.

## 🔴 Two corrections to what was previously recorded about TYPO3

1. **`ext_emconf.php` is deprecated, and this extension ships one anyway.**
   Earlier notes said to be "composer-first and not lead with `ext_emconf.php`".
   That is right about direction and wrong as an instruction to omit the file:
   TYPO3's own 14.2 deprecation note says, verbatim, *"For the time being,
   ext_emconf.php may still need to be kept for third-party tooling such as
   TYPO3 TER or Tailor."* TER and `tailor ter:publish` are precisely that
   tooling, and they are this extension's release path. **Deprecated is not the
   same as omit.** Delete it when TYPO3 v15 lands *and* tailor reads metadata
   from `composer.json` — verify the second half before acting on the first.
2. **`AssetCollector::addJavaScript(..., ['external' => true])` is not a thing
   in TYPO3 14.** The option appears nowhere in `Page/` in the v14 source. The
   options that exist and matter here are **`priority => true`** (which is what
   puts the tag in `<head>` — `PageRenderer` calls `renderJavaScript(true)` for
   the head and `renderJavaScript(false)` for the footer) and `csp`.

## Publishing to TER

Publishing is **immediate — there is no human review gate.** TYPO3's own
security documentation is candid about this: *"only a small percentage of the
extensions available in the TER have been reviewed by the TYPO3 Security team"*
and *"everybody can publish an extension"*. Review, where it happens, is
post-hoc and selective.

1. Register the extension key **`pulse_analytics`** on
   `extensions.typo3.org` under a typo3.org account.
2. Create a **personal access token** on extensions.typo3.org and export it as
   `TYPO3_API_TOKEN`. Username/password auth also works and is *"highly
   discouraged"*.
3. Tag the release, then:
   ```bash
   composer require --dev typo3/tailor
   TYPO3_API_TOKEN=… vendor/bin/tailor ter:publish 1.0.0
   ```
4. Keep `ext_emconf.php`'s `version` in step with `composer.json`.

Packagist is separate and additive: `composer require ciphera/pulse-analytics`
resolves from Packagist, the TER key serves the non-Composer installs.

## Licence — forced, not chosen

**GPL-2.0-or-later.** TYPO3's position is that TYPO3 and installed extensions
"form a single combined program", so "your extensions fall under the GNU General
Public License, version 2 or later", and the `composer.json` reference states
the `license` key *"has to be GPL-2.0-only or GPL-2.0-or-later"*. Apache-2.0 is
GPLv3-compatible but **not** GPLv2-compatible, so it cannot be used.

That makes **three** of this queue's surfaces forced onto GPL — Drupal, Joomla
and TYPO3 — against Apache-2.0 everywhere else. Each says so in its README so it
reads as the constraint it is rather than as drift.

## Release steps

1. Bump `version` in `ext_emconf.php` and `extra.typo3/cms.version` if present.
2. `./scripts/verify.sh 14` and `HTTP_PORT=18101 ./scripts/verify.sh 13`.
3. Tag: `git tag -a v1.0.0 -m "Release 1.0.0" && git push origin v1.0.0`.
4. `tailor ter:publish` (above).

## Notes for the next person

- 🔴 **`config/system/settings.php` is a PHP file behind the opcache.** The
  verification harness flushes TYPO3's cache **inside** its retry loop, not once
  before it, and that is load-bearing. Write config → flush → request
  immediately and the page renders with the *previous* config, then TYPO3
  caches that page; retrying the request alone can never recover, because every
  later retry is served the stale entry the first retry just created. The
  symptom was every case seeing the previous case's tag, reproducibly, in five
  runs across both branches — a test that was reliably one step behind itself.
- ⚠️ **`BeforeJavaScriptsRenderingEvent` carries no request**, which is why the
  frontend guard reads `$GLOBALS['TYPO3_REQUEST']`. That is not deprecated —
  v14 core reaches for it in 57 places, including frontend classes.
- The listener acts only on the **non-inline, priority** pass. Acting on both
  would be harmless (the collector keys on the identifier) but would mean
  running when there is nothing to do.
- `ExtensionConfiguration::get()` **throws** when the extension has never been
  opened in the Settings module. That is a working install with the domain
  auto-detected, not an error worth a white screen, so it is caught.
