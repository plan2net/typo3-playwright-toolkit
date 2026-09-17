<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\SetupCache;

use Plan2net\PlaywrightToolkit\Database\Driver\Engine;

final class DeltaHeader
{
    /**
     * @var string
     */
    public const PREFIX = '-- playwright-setup-cache ';

    /**
     * @param array<string, string> $tables table => hash it must have after the delta is applied
     * @param array<string, mixed>  $state  what the setup returned
     */
    public function __construct(
        public readonly Engine $engine,
        public readonly string $templateFingerprint,
        public readonly array $tables,
        public readonly array $state,
    ) {
    }

    public static function fromLine(string $line): ?self
    {
        if (!str_starts_with($line, self::PREFIX)) {
            return null;
        }

        try {
            $payload = json_decode(substr($line, strlen(self::PREFIX)), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $engine = is_string($payload['engine'] ?? null) ? Engine::tryFrom($payload['engine']) : null;
        if (null === $engine
            || !is_string($payload['templateFingerprint'] ?? null)
            || !is_array($payload['tables'] ?? null)
            || !is_array($payload['state'] ?? null)
        ) {
            return null;
        }

        /** @var array<string, string> $tables */
        $tables = $payload['tables'];
        /** @var array<string, mixed> $state */
        $state = $payload['state'];

        return new self(
            engine: $engine,
            templateFingerprint: $payload['templateFingerprint'],
            tables: $tables,
            state: $state,
        );
    }

    public function appliesTo(Engine $engine, string $templateFingerprint): bool
    {
        return $engine === $this->engine && $templateFingerprint === $this->templateFingerprint;
    }

    public function toLine(): string
    {
        return self::PREFIX . json_encode([
            'engine' => $this->engine->value,
            'templateFingerprint' => $this->templateFingerprint,
            'tables' => $this->tables,
            'state' => $this->state,
        ], JSON_THROW_ON_ERROR);
    }
}
