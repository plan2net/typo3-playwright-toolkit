<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Imaging;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\TestContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * gifBuild() encodes straight into the path it has just found missing, so a
 * request arriving meanwhile reads a truncated image. The forked child writes;
 * the parent calls gifBuild() as soon as the file appears.
 */
final class ConcurrentGifBuildTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const TEST_ID = 'ABCD1234EFGH5678';

    private const ATTEMPTS = 3;

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is not available.');
        }

        $_SERVER[TestContext::TEST_ID_SERVER_KEY] = self::TEST_ID;
    }

    protected function tearDown(): void
    {
        unset($_SERVER[TestContext::TEST_ID_SERVER_KEY]);
        foreach (glob(self::imagesDirectory() . '/*') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function aReaderArrivingWhenTheOutputAppearsGetsTheFinishedImage(): void
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            [$observed, $complete] = $this->raceOnce();

            self::assertStringEndsWith("\xff\xd9", $observed, sprintf('Attempt %d read %d of %d bytes.', $attempt, strlen($observed), strlen($complete)));
            self::assertSame($complete, $observed);
        }

        self::assertSame([], glob(self::imagesDirectory() . '/' . self::TEST_ID . '-*'), 'The scratch file must be renamed away.');
    }

    /**
     * @return array{string, string}
     */
    private function raceOnce(): array
    {
        $writer = GeneralUtility::makeInstance(GifBuilder::class);
        $writer->start(self::configuration(), ['reproducer' => bin2hex(random_bytes(12))]);
        $reader = clone $writer;

        $directory = self::imagesDirectory();
        $before = self::finishedImages($directory);

        $pid = pcntl_fork();
        if (-1 === $pid) {
            self::fail('Could not fork the writer.');
        }
        if (0 === $pid) {
            $writer->gifBuild();
            posix_kill(posix_getpid(), SIGKILL);
        }

        $deadline = microtime(true) + 15;
        while (true) {
            $appeared = array_values(array_diff(self::finishedImages($directory), $before));
            if ([] !== $appeared) {
                break;
            }
            if (microtime(true) > $deadline) {
                $exited = pcntl_waitpid($pid, $status, WNOHANG);
                self::fail(sprintf('The writer did not create an output file; child %s.', 0 === $exited ? 'still running' : 'exited with status ' . $status));
            }
            usleep(50);
        }

        $reader->gifBuild();
        $observed = (string) file_get_contents($appeared[0]);

        pcntl_waitpid($pid, $status);

        return [$observed, (string) file_get_contents($appeared[0])];
    }

    /**
     * Big enough that encoding takes a few milliseconds, the window the reader must hit.
     *
     * @return array<int|string, mixed>
     */
    private static function configuration(): array
    {
        $configuration = ['XY' => '1920,840', 'format' => 'jpg', 'quality' => '85', 'backColor' => '#ffffff'];
        for ($index = 1; $index <= 1680; ++$index) {
            $configuration[$index] = 'BOX';
            $configuration[$index . '.'] = [
                'dimensions' => sprintf('%d,%d,32,30', (($index - 1) % 60) * 32, intdiv($index - 1, 60) * 30),
                'color' => '#' . substr(hash('sha256', (string) $index), 0, 6),
            ];
        }

        return $configuration;
    }

    /**
     * @return list<string>
     */
    private static function finishedImages(string $directory): array
    {
        $finished = [];
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (!str_starts_with(basename($file), self::TEST_ID)) {
                $finished[] = $file;
            }
        }

        return $finished;
    }

    private static function imagesDirectory(): string
    {
        return rtrim(Environment::getPublicPath(), '/') . '/typo3temp/assets/images';
    }
}
