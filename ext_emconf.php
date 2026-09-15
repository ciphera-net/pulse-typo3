<?php

/**
 * ⚠️ DEPRECATED SINCE TYPO3 14.2, AND KEPT ANYWAY — ON PURPOSE.
 *
 * `composer.json` is the source of truth for this extension's metadata, which is
 * what TYPO3 14.2+ wants. This file exists because the deprecation note itself
 * says, verbatim: "For the time being, ext_emconf.php may still need to be kept
 * for third-party tooling such as TYPO3 TER or Tailor."
 *
 * TER and `tailor ter:publish` are exactly that tooling, and this extension is
 * published through them — so deleting this file would not be "following the
 * deprecation", it would be breaking the release path. **Deprecated is not the
 * same as omit.**
 *
 * 🔴 Delete it when TYPO3 v15 lands (where `ext_emconf.php` is no longer
 * evaluated) AND tailor/TER read the metadata from composer.json. Verify the
 * second half before acting on the first.
 *
 * Keep `version` in step with composer.json's `extra.typo3/cms.version`.
 */
$EM_CONF[$_EXTKEY] = [
    'title' => 'Pulse Analytics',
    'description' => 'Privacy-first web analytics. No cookies, no personal data, and the script it adds to your pages is under 3 KB.',
    'category' => 'fe',
    'author' => 'Ciphera',
    'author_company' => 'Ciphera BV',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
