<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Driver;

final class TestDatabaseService
{
    /**
     * @var string
     */
    public const HOST_VARIABLE = 'PLAYWRIGHT_DB_TEST_HOST';
    /**
     * @var string
     */
    public const PORT_VARIABLE = 'PLAYWRIGHT_DB_TEST_PORT';
    /**
     * @var string
     */
    public const USER_VARIABLE = 'PLAYWRIGHT_DB_TEST_USER';
    /**
     * @var string
     */
    public const PASSWORD_VARIABLE = 'PLAYWRIGHT_DB_TEST_PASSWORD';

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly string $password,
    ) {
    }

    public static function fromEnvironment(Engine $engine): self
    {
        return new self(
            host: self::read(self::HOST_VARIABLE) ?? 'db-test',
            port: (int) (self::read(self::PORT_VARIABLE) ?? (string) $engine->defaultPort()),
            user: self::read(self::USER_VARIABLE) ?? 'db',
            password: self::read(self::PASSWORD_VARIABLE) ?? 'db',
        );
    }

    private static function read(string $name): ?string
    {
        // PHP-FPM surfaces the container environment through either of these,
        // depending on how the pool was configured.
        $value = getenv($name);
        if (!is_string($value) || '' === $value) {
            $value = $_SERVER[$name] ?? null;
        }

        return is_string($value) && '' !== $value ? $value : null;
    }
}
