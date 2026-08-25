<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\DowngradeLevelSetList;

/**
 * The extension ships into consumer projects, so its PHP floor is the floor of
 * the oldest TYPO3 it supports — not the version this repo is developed on.
 * Running this rewrites 8.2/8.3-only syntax (readonly classes, typed class
 * constants, #[\Override]) into its 8.1 equivalent.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Classes',
        __DIR__ . '/Configuration',
        __DIR__ . '/Tests',
    ])
    ->withSets([DowngradeLevelSetList::DOWN_TO_PHP_81]);
