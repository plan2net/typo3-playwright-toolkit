<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Setup\Check;

use Plan2net\PlaywrightToolkit\Setup\Result;

final class AdditionalConfiguration
{
    public function __construct(private readonly string $file)
    {
    }

    public function run(): Result
    {
        $own = (string) @file_get_contents($this->file);
        $files = [$this->file => $own];

        // TYPO3 auto-loads this one file, but it may include another, and the call then
        // lives in the included one.
        foreach (self::includedFiles($this->file, $own) as $included) {
            $files[$included] = (string) @file_get_contents($included);
        }

        foreach ($files as $file => $php) {
            $called = self::calledNames($php);

            // The second call is for a project that merges the settings itself.
            foreach (['configureCurrentRequest', 'resolveCurrentRequestSettings'] as $call) {
                if (\in_array($call, $called, true)) {
                    return Result::pass($file . ' calls ' . $call . '()');
                }
            }
        }

        return Result::fail($this->file . ' does not call TestContext::configureCurrentRequest()');
    }

    /**
     * One level deep, and only literal paths: this reads the file, it does not run it.
     *
     * @return list<string>
     */
    private static function includedFiles(string $file, string $php): array
    {
        $includes = [\T_INCLUDE, \T_INCLUDE_ONCE, \T_REQUIRE, \T_REQUIRE_ONCE];
        $files = [];
        $path = null;

        foreach (@token_get_all($php) as $token) {
            if (\is_array($token) && \in_array($token[0], $includes, true)) {
                $path = '';

                continue;
            }

            if (null === $path) {
                continue;
            }

            if (\is_array($token) && \T_DIR === $token[0]) {
                $path .= \dirname($file);
            } elseif (\is_array($token) && \T_CONSTANT_ENCAPSED_STRING === $token[0]) {
                $path .= trim($token[1], '"\'');
            } elseif (';' === $token) {
                if (is_file($path)) {
                    $files[] = $path;
                }

                $path = null;
            }
        }

        return $files;
    }

    /**
     * Tokenised, so a call named in a comment or a string does not count as one.
     *
     * @return list<string>
     */
    private static function calledNames(string $php): array
    {
        $names = [];
        $tokens = @token_get_all($php);

        foreach ($tokens as $position => $token) {
            if (!\is_array($token) || \T_STRING !== $token[0]) {
                continue;
            }

            $next = $tokens[$position + 1] ?? null;
            if ('(' === $next) {
                $names[] = $token[1];
            }
        }

        return $names;
    }
}
