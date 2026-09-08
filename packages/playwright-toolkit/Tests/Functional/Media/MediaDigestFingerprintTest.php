<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Media;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Configuration\ToolkitConfiguration;
use Plan2net\PlaywrightToolkit\Database\SeedSources;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MediaDigestFingerprintTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const MEDIA_PATH = 'media-fingerprint';

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private string $mediaDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaDirectory = rtrim(Environment::getProjectPath(), '/') . '/' . self::MEDIA_PATH;
        GeneralUtility::rmdir($this->mediaDirectory, true);
        GeneralUtility::mkdir_deep($this->mediaDirectory);
    }

    #[Test]
    public function movesWhenAMediaFixtureChanges(): void
    {
        file_put_contents($this->mediaDirectory . '/hero.png', 'one');
        $sources = $this->get(SeedSources::class);
        $configuration = self::configurationWithMediaPath(self::MEDIA_PATH);

        $before = $sources->snapshot($configuration)->fingerprint;
        file_put_contents($this->mediaDirectory . '/hero.png', 'two');

        self::assertNotSame($before, $sources->snapshot($configuration)->fingerprint);
    }

    #[Test]
    public function movesWhenMediaSeedingIsSwitchedOn(): void
    {
        file_put_contents($this->mediaDirectory . '/hero.png', 'one');
        $sources = $this->get(SeedSources::class);

        self::assertNotSame(
            $sources->snapshot(self::configurationWithMediaPath(''))->fingerprint,
            $sources->snapshot(self::configurationWithMediaPath(self::MEDIA_PATH))->fingerprint
        );
    }

    private static function configurationWithMediaPath(string $mediaPath): ToolkitConfiguration
    {
        return new ToolkitConfiguration(
            fixturesPath: '',
            fixtureManifest: [],
            preseededSessionId: 'playwright_test_session',
            sessionUserId: 1,
            cleanupMinimumAgeMs: 3600000,
            mediaPath: $mediaPath,
        );
    }
}
