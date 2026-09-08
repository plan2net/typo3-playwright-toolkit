<?php

declare(strict_types=1);

use Plan2net\PlaywrightToolkit\Compatibility\AtomicOutputGifBuilder;
use Plan2net\PlaywrightToolkit\Compatibility\ScopedGifBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;

return static function (ContainerConfigurator $configurator): void {
    $replacement = property_exists(GifBuilder::class, 'filenamePrefix')
        ? ScopedGifBuilder::class
        : AtomicOutputGifBuilder::class;

    $configurator->services()
        ->set(GifBuilder::class)
        ->public()
        ->share(false)
        ->factory([$replacement, 'create']);
};
