<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Driver;

interface TestDatabaseDriver
{
    public function engine(): Engine;

    /**
     * @return array<string, mixed> empty when the test ID is empty
     */
    public function connectionOverrides(string $testId): array;

    /**
     * @return array<string, mixed>
     */
    public function templateConnectionOverrides(): array;

    /**
     * Any database of this engine will do. Postgres cannot clone a template another
     * session holds open, so it must not answer with the template.
     *
     * @return array<string, mixed>
     */
    public function schemaConnectionOverrides(): array;

    public function templateFingerprint(): ?string;

    /**
     * Unlike templateFingerprint(), a connection failure must throw here rather
     * than read as "not prepared yet".
     */
    public function templateExists(): bool;

    public function createEmptyTemplate(): void;

    public function seedTemplate(TemplateSeed $seed): void;

    public function finaliseTemplate(string $fingerprint): void;

    public function materialise(string $testId): void;

    public function isolateProcessedFiles(string $testId): void;

    /** Lets cleanup tell "nothing to do" from "something else owns this name". */
    public function exists(string $testId): bool;

    public function drop(string $testId): void;

    public function hasSeededSession(
        string $testId,
        string $plainSessionId,
        int $sessionUserId,
    ): bool;

    /**
     * @return array{ok: bool, detail: string}
     */
    public function checkTestDatabase(string $testId): array;
}
