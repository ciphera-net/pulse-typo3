<?php

declare(strict_types=1);

/*
 * This file is part of the "Pulse Analytics" extension for TYPO3 CMS.
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License, either version 2 of the License, or any
 * later version.
 */

namespace Ciphera\PulseAnalytics\EventListener;

use Ciphera\PulseAnalytics\Service\TagBuilder;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;

/**
 * Adds the Pulse Analytics tracker to every front-end page.
 *
 * Hooked to BeforeJavaScriptsRenderingEvent rather than to a page-generation
 * event because that is the moment the collector is read: AssetRenderer
 * dispatches this event and only then calls getJavaScripts(), so anything added
 * here is included in the same pass.
 */
final class AddTracker
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function __invoke(BeforeJavaScriptsRenderingEvent $event): void
    {
        // 🔴 The backend uses the same PageRenderer and the same AssetRenderer,
        // so without this guard the tracker would be injected into the TYPO3
        // backend. The event carries no request, so the request comes from
        // $GLOBALS — which is still how 57 places in v14 core reach it, and is
        // not deprecated.
        if (!$this->isFrontend()) {
            return;
        }

        // Only the non-inline, priority pass: PageRenderer calls
        // renderJavaScript(true) for <head> and renderJavaScript(false) for the
        // footer. Acting on both would add the asset twice — harmless, since
        // AssetCollector keys on the identifier, but it would also mean this
        // listener runs when it has nothing to do.
        if ($event->isInline() || !$event->isPriority()) {
            return;
        }

        $settings = $this->settings();
        $collector = $event->getAssetCollector();

        $collector->addJavaScript(
            'pulse_analytics_tracker',
            TagBuilder::SCRIPT_URL,
            TagBuilder::trackerAttributes($settings['domain'], $settings['api']),
            TagBuilder::ASSET_OPTIONS,
        );

        if ($settings['companion'] !== true) {
            return;
        }

        $collector->addJavaScript(
            'pulse_analytics_companion',
            TagBuilder::COMPANION_URL,
            TagBuilder::companionAttributes(),
            TagBuilder::ASSET_OPTIONS,
        );
    }

    private function isFrontend(): bool
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        if (!$request instanceof ServerRequestInterface) {
            return false;
        }

        // fromRequest() throws when the request carries no applicationType,
        // which happens outside a real TYPO3 application. Not rendering is the
        // right answer there, not an exception on someone else's page.
        try {
            return ApplicationType::fromRequest($request)->isFrontend();
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * @return array{domain: string, companion: bool, api: string}
     */
    private function settings(): array
    {
        try {
            $raw = $this->extensionConfiguration->get('pulse_analytics');
        } catch (ExtensionConfigurationExtensionNotConfiguredException | ExtensionConfigurationPathDoesNotExistException) {
            // An extension installed but never opened in the Settings module has
            // no configuration row at all. That is a working install with the
            // domain auto-detected, not an error worth a white screen.
            $raw = [];
        }

        return [
            'domain' => trim((string)($raw['domain'] ?? '')),
            'companion' => (bool)($raw['companion'] ?? false),
            'api' => trim((string)($raw['api'] ?? '')),
        ];
    }
}
