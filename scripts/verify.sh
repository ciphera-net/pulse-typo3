#!/usr/bin/env bash
# Install a real TYPO3, install this extension into it, and read the tag out of
# the served HTML.
#
# 🔴 This is the check that matters. Every surface in this family has emitted a
# different byte-string from the same intent, and no amount of reading the
# source tells you which one you got:
#
#   Drupal      'defer' => TRUE   -> <script defer ...>
#   Joomla      'defer' => true   -> <script ... defer ...>   (+ wanted to append ?mediaVersion)
#   Docusaurus  {defer: true}     -> <script ... defer="defer" ...>  (its HTML minifier rewrote it)
#   TYPO3       'defer' => 'defer'-> <script defer="defer" ...>
#
# TYPO3's GeneralUtility::implodeAttributes() has NO bare-attribute path — every
# attribute is emitted as name="value" — so a boolean true would have rendered
# defer="1". That is valid HTML and would never have been reported.
#
#   ./scripts/verify.sh 14
#   ./scripts/verify.sh 13
#
# Needs php (8.2+) and composer. Uses SQLite, so no database server is required.
# Exits non-zero if any case does not match.

set -uo pipefail

MAJOR="${1:-14}"
EXT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="${TYPO3_WORK:-${TMPDIR:-/tmp}/pulse-typo3-verify}"
APP="$WORK/v$MAJOR"
HTTP_PORT="${HTTP_PORT:-18100}"

for tool in php composer curl; do
  command -v "$tool" >/dev/null || { echo "REFUSING: $tool is not on PATH"; exit 1; }
done

mkdir -p "$WORK"

if [ ! -f "$APP/config/system/settings.php" ]; then
  echo "==> creating a TYPO3 v$MAJOR app in $APP (first run, a few minutes)"
  rm -rf "$APP"
  composer create-project "typo3/cms-base-distribution:^$MAJOR" "$APP" --no-interaction --quiet || {
    echo "REFUSING: could not create the TYPO3 $MAJOR base distribution"; exit 1; }
  # ⚠️ The driver is `sqlite`, not `pdo_sqlite`. The setup command rejects the
  # Doctrine name with "The connection type pdo_sqlite does not exist".
  # Generated password: nobody logs into this throwaway, but a literal one in a
  # public repository is a thing somebody eventually reuses.
  ADMIN_PASS="fixture-$(LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)"
  ( cd "$APP" && php vendor/bin/typo3 setup \
      --driver=sqlite --dbname=t3fixture \
      --admin-username=admin --admin-user-password="$ADMIN_PASS" \
      --admin-email=admin@example.com \
      --project-name="Pulse Fixture" \
      --create-site="http://127.0.0.1:$HTTP_PORT/" \
      --server-type=other --no-interaction --force ) | tail -1
fi

echo "==> installing the extension"
rm -rf "$APP/packages/pulse_analytics"
mkdir -p "$APP/packages"
cp -R "$EXT_DIR" "$APP/packages/pulse_analytics"
rm -rf "$APP/packages/pulse_analytics/.git"
( cd "$APP" \
  && composer config repositories.pulse path 'packages/pulse_analytics' --no-interaction \
  && composer require ciphera/pulse-analytics:@dev --no-interaction --quiet \
  && php vendor/bin/typo3 extension:setup >/dev/null 2>&1 )

cd "$APP"
php -S "127.0.0.1:$HTTP_PORT" -t public > "$WORK/php-$MAJOR.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT
for _ in $(seq 1 30); do
  curl -sf -o /dev/null "http://127.0.0.1:$HTTP_PORT/" && break
  sleep 0.5
done

FAILURES=0
CORE='https://js\.ciphera\.net/script\.js'
COMP='https://js\.ciphera\.net/script\.interactions\.js'

# 🔴 THE FLUSH MUST BE INSIDE THE RETRY LOOP, and getting that wrong produced a
# test that was reliably one step behind itself.
#
# `config/system/settings.php` is a PHP file that TYPO3 `require`s, so the web
# server's opcache holds it for up to `opcache.revalidate_freq` seconds (2 by
# default). Write the config, flush, request immediately, and the page renders
# with the PREVIOUS config — and then TYPO3 caches that page. Retrying the
# request alone can never recover: retry 1 renders stale and writes the page
# cache, retries 2..N are served that same stale entry. Every case saw the
# previous case's tag, consistently, in five runs across both branches.
#
# Flushing on every attempt makes each retry a fresh render, so the loop rides
# out the opcache window instead of freezing the first wrong answer.
#
# The page cache is deliberately left ON rather than nulled out in the fixture:
# a cached page is where an analytics tag most easily goes wrong, so it is worth
# exercising rather than configuring away.
#
# $1 label, $2 domain, $3 companion, $4 api, $5 must-match ERE, $6 must-NOT-match ERE
check() {
  php "$EXT_DIR/scripts/set-config.php" config/system/settings.php "$2" "$3" "$4"
  local tags
  for _ in $(seq 1 20); do
    php vendor/bin/typo3 cache:flush >/dev/null 2>&1
    tags=$(curl -s "http://127.0.0.1:$HTTP_PORT/" \
      | grep -oE '<script[^>]*ciphera[^>]*></script>' | tr '\n' ' ')
    printf '%s' "$tags" | grep -qE "$5" && break
    sleep 0.5
  done
  if printf '%s' "$tags" | grep -qE "$5"; then
    echo "    ✅ $1"
  else
    echo "    ❌ $1"
    echo "       expected to match: $5"
    echo "       got: ${tags:-<nothing>}"
    FAILURES=$((FAILURES + 1))
  fi
  if [ -n "${6:-}" ] && printf '%s' "$tags" | grep -qE "$6"; then
    echo "    ❌ $1 — unexpectedly present: $6"
    echo "       got: $tags"
    FAILURES=$((FAILURES + 1))
  fi
}

echo "==> checking the served HTML"

# 🔴 `script\.js\?` as the must-NOT-match is the assertion that TYPO3 leaves our
# CDN url alone. Joomla did not — it appended ?mediaVersion — so this is checked
# rather than assumed. Do not relax it.
check "domain only, and no query appended to the CDN url" \
  "example.com" "0" "" \
  "<script defer=\"defer\" data-domain=\"example\.com\" src=\"$CORE\"></script>" \
  "script\.js\?"

check "domain + api + companion, companion carries no domain" \
  "chosen.dev" "1" "https://proxy.example.com/" \
  "<script defer=\"defer\" data-domain=\"chosen\.dev\" data-api=\"https://proxy\.example\.com\" src=\"$CORE\"></script> <script defer=\"defer\" src=\"$COMP\"></script>" \
  ""

check "no domain configured means auto-detect, not an empty attribute" \
  "" "0" "" \
  "^<script defer=\"defer\" src=\"$CORE\"></script> \$" \
  "data-domain"

check "an invalid domain is dropped, never injected" \
  'bad" onload="x' "0" "" \
  "^<script defer=\"defer\" src=\"$CORE\"></script> \$" \
  "onload"

# 🔴 The backend uses the same PageRenderer and the same AssetRenderer as the
# frontend, so without the ApplicationType guard the tracker would be injected
# into the TYPO3 backend. Companion on, to catch both tags.
php "$EXT_DIR/scripts/set-config.php" config/system/settings.php "example.com" "1" ""
# Flush on every attempt, for the opcache reason above.
for _ in $(seq 1 20); do
  php vendor/bin/typo3 cache:flush >/dev/null 2>&1
  curl -s "http://127.0.0.1:$HTTP_PORT/" | grep -q 'script.interactions.js' && break
  sleep 0.5
done
BE_HITS=$(curl -s "http://127.0.0.1:$HTTP_PORT/typo3/" | grep -c 'ciphera')
FE_HITS=$(curl -s "http://127.0.0.1:$HTTP_PORT/" | grep -c 'js.ciphera.net')
if [ "$BE_HITS" -eq 0 ] && [ "$FE_HITS" -gt 0 ]; then
  echo "    ✅ the TYPO3 backend carries no tag, while the frontend still does"
else
  echo "    ❌ backend references: $BE_HITS (want 0); frontend references: $FE_HITS (want >0)"
  FAILURES=$((FAILURES + 1))
fi

echo
if [ "$FAILURES" -eq 0 ]; then
  echo "TYPO3 v$MAJOR: ALL CHECKS PASSED"
else
  echo "TYPO3 v$MAJOR: $FAILURES FAILURE(S)"
fi
exit "$FAILURES"
