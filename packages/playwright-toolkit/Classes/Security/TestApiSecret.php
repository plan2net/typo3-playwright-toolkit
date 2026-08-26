<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Security;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;

final class TestApiSecret
{
    /**
     * @var string
     */
    public const HEADER = 'X-Playwright-Toolkit-Secret';
    /**
     * @var string
     */
    public const SERVER_KEY = 'HTTP_X_PLAYWRIGHT_TOOLKIT_SECRET';
    /**
     * @var string
     */
    public const ENV_NAME = 'PLAYWRIGHT_TOOLKIT_SECRET';

    public function __construct(
        private readonly string $secretFile,
    ) {
    }

    public static function inVarPath(): self
    {
        return new self(Environment::getVarPath() . '/playwright/api-secret');
    }

    // For the boot listener, which runs before any PSR-7 request exists.
    public function matchesCurrentRequest(): bool
    {
        return $this->matches(trim((string) ($_SERVER[self::SERVER_KEY] ?? '')));
    }

    public function matches(string $candidate): bool
    {
        $secret = $this->resolve();

        if (null === $secret || '' === $candidate) {
            return false;
        }

        return hash_equals($secret, $candidate);
    }

    public function ensureExists(): string
    {
        $existing = $this->resolve();
        if (null !== $existing) {
            return $existing;
        }

        $directory = \dirname($this->secretFile);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException(
                sprintf('Could not create the directory for the test API secret at "%s".', $directory),
                1724150000
            );
        }

        $secret = bin2hex(random_bytes(32));

        $handle = fopen($this->secretFile, 'w');
        if (false === $handle) {
            throw new \RuntimeException(
                sprintf('Could not write the test API secret to "%s".', $this->secretFile),
                1724150001
            );
        }

        // Before the write, so the file is never readable while it holds the secret.
        chmod($this->secretFile, 0o600);
        fwrite($handle, $secret);
        fclose($handle);

        return $secret;
    }

    public function matchesInspectToken(string $testId, string $token): bool
    {
        return InspectToken::verify((string) $this->resolve(), $testId, $token, time());
    }

    public function inspectTokenLapsed(string $testId, string $token): bool
    {
        return InspectToken::lapsed((string) $this->resolve(), $testId, $token, time());
    }

    public function file(): string
    {
        return $this->secretFile;
    }

    // Names no path and no configuration state: a caller who cannot authenticate
    // learns only that.
    public static function unauthorizedResponse(): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
    }

    private function resolve(): ?string
    {
        $fromEnvironment = getenv(self::ENV_NAME);
        if (\is_string($fromEnvironment) && '' !== trim($fromEnvironment)) {
            return trim($fromEnvironment);
        }

        if (!is_readable($this->secretFile)) {
            return null;
        }

        $contents = trim((string) file_get_contents($this->secretFile));

        return '' === $contents ? null : $contents;
    }
}
