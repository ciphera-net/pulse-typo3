<?php

declare(strict_types=1);

/*
 * This file is part of the "Pulse Analytics" extension for TYPO3 CMS.
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */

namespace Ciphera\PulseAnalytics\Service;

/**
 * Decides what the Pulse tag says.
 *
 * Pure logic: no TYPO3 services, no request, no container. Everything comes in
 * as arguments and plain arrays come out, so the part that decides what the tag
 * says is testable without booting TYPO3, and reads side by side with the same
 * decisions in the Drupal, Joomla, Astro, Framer and Docusaurus integrations.
 *
 * The URLs, the domain grammar and the rule that the companion script is a
 * SECOND request are copied from those rather than re-derived. Two Pulse install
 * surfaces disagreeing about what the tag looks like is a drift bug waiting to
 * happen.
 */
final class TagBuilder
{
    public const SCRIPT_URL = 'https://js.ciphera.net/script.js';
    public const COMPANION_URL = 'https://js.ciphera.net/script.interactions.js';

    /**
     * A registrable hostname.
     *
     * Labels of letters, digits and hyphens, at least one dot, a letter-only
     * TLD. Deliberately strict — the value lands inside an HTML attribute, and
     * this shape cannot carry a quote, a space or a bracket.
     * `GeneralUtility::implodeAttributes()` runs every value through
     * htmlspecialchars(), so this is the second of two guards rather than the
     * only one; it is kept because a domain that cannot be a hostname is a typo,
     * and a typo is an install that reports nothing and looks fine.
     */
    public const DOMAIN_PATTERN = '/^(?=.{1,253}$)(?!-)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*\.[a-z]{2,63}$/';

    /**
     * Options for every asset this extension registers.
     *
     * `priority => true` is what puts the tag in <head> rather than before
     * </body>: PageRenderer calls renderJavaScript(true) for the head pass and
     * renderJavaScript(false) for the footer, and AssetCollector splits on this
     * flag.
     */
    public const ASSET_OPTIONS = ['priority' => true];

    /**
     * Lower-case a domain and strip a scheme, path, port and trailing dot.
     *
     * Keeps "www." — the Pulse site may be registered either way and the site
     * owner can edit it.
     */
    public static function normalizeDomain(string $input): string
    {
        $value = mb_strtolower(trim($input));
        $value = (string)preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = (string)preg_replace('#[/?\#].*$#', '', $value);
        $value = (string)preg_replace('/:.*$/', '', $value);

        return rtrim($value, '.');
    }

    public static function isValidDomain(string $domain): bool
    {
        return (bool)preg_match(self::DOMAIN_PATTERN, $domain);
    }

    /**
     * Attributes for the core tracker tag.
     *
     * 🔴 `defer` is the STRING 'defer', not a boolean, and TYPO3 is the only
     * surface in this family where that is true. `GeneralUtility::implodeAttributes()`
     * has no bare-attribute path at all — every attribute is emitted as
     * `name="value"` — so `true` would be cast to `"1"` and render `defer="1"`.
     * Valid HTML, since any value makes a boolean attribute true, but not the
     * tag the Pulse docs show.
     *
     * ⚠️ The same function DROPS any attribute whose value casts to an empty
     * string, because it is called with `$keepBlankAttributes = false`. That is
     * why an absent domain is handled by not adding the key rather than by
     * adding an empty one — the two happen to coincide here, but relying on the
     * coincidence would break the moment someone passes `true` instead.
     *
     * Compare: Drupal and Joomla both emit a bare `defer` from a boolean, and
     * Docusaurus's HTML minifier rewrites a bare `defer` into `defer="defer"`.
     * Four surfaces, one intent, three different serialisers.
     *
     * @return array<string, string>
     */
    public static function trackerAttributes(string $domain = '', string $api = ''): array
    {
        $attributes = ['defer' => 'defer'];

        $domain = self::normalizeDomain($domain);

        if ($domain !== '' && self::isValidDomain($domain)) {
            $attributes['data-domain'] = $domain;
        }

        $api = rtrim(trim($api), '/');

        if ($api !== '') {
            $attributes['data-api'] = $api;
        }

        return $attributes;
    }

    /**
     * Attributes for the companion tag.
     *
     * No data-domain: the tracker resolves the domain once, from the core
     * script, and a second copy is a second thing to keep in step.
     *
     * @return array<string, string>
     */
    public static function companionAttributes(): array
    {
        return ['defer' => 'defer'];
    }
}
