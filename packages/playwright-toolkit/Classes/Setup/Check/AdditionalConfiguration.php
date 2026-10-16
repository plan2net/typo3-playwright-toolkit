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
        [$includedFiles, $unreadableIncludeLines] = self::includes($this->file, $own);
        foreach ($includedFiles as $included) {
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

        $reason = $this->file . ' does not call TestContext::configureCurrentRequest()';
        if ([] !== $unreadableIncludeLines) {
            $reason .= '. The include on line ' . implode(', ', $unreadableIncludeLines)
                . ' builds its path at run time, so this check cannot follow it;'
                . ' write the path out, as in __DIR__ . \'/additional-testing.php\'';
        }

        return Result::fail($reason);
    }

    /**
     * One level deep, and only literal paths: this reads the file, it does not run it.
     *
     * @return array{list<string>, list<int>} the included files, and the lines of includes whose path is built at run time
     */
    private static function includes(string $file, string $php): array
    {
        $includes = [\T_INCLUDE, \T_INCLUDE_ONCE, \T_REQUIRE, \T_REQUIRE_ONCE];
        $files = [];
        $unreadableLines = [];
        $path = null;
        $line = 0;
        $literal = true;

        foreach (@token_get_all($php) as $token) {
            if (\is_array($token) && \in_array($token[0], $includes, true)) {
                $path = '';
                $line = $token[2];
                $literal = true;

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
                if (!$literal) {
                    $unreadableLines[] = $line;
                } elseif (is_file($path)) {
                    $files[] = $path;
                }

                $path = null;
            } elseif (!\in_array($token, ['.', '(', ')'], true) && !(\is_array($token) && \T_WHITESPACE === $token[0])) {
                $literal = false;
            }
        }

        return [$files, $unreadableLines];
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
