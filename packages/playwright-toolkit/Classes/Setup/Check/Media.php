<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup\Check;

use Plan2net\PlaywrightToolkit\Media\MediaSources;
use Plan2net\PlaywrightToolkit\Setup\Result;

final class Media
{
    public function __construct(
        private readonly string $mediaPath,
        private readonly string $directory,
    ) {
    }

    public function run(): Result
    {
        if ('' === $this->mediaPath) {
            return Result::pass('not configured');
        }

        if (!is_dir($this->directory)) {
            return Result::fail($this->directory . ' does not exist');
        }

        return Result::of(fn(): string => sprintf(
            '%d file(s), %d online media, %d described in %s',
            \count(MediaSources::scan($this->directory)),
            \count(MediaSources::onlineMedia($this->directory)),
            \count(MediaSources::metadata($this->directory)),
            $this->mediaPath
        ));
    }
}
