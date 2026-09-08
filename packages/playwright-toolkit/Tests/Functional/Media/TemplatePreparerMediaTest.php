<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Media;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\PlaywrightToolkit\Database\TemplatePreparer;
use Plan2net\PlaywrightToolkit\Media\MediaManifest;
use Plan2net\PlaywrightToolkit\Media\MediaSeeder;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TemplatePreparerMediaTest extends FunctionalTestCase
{
    /**
     * @var string
     */
    private const MEDIA_PATH = 'playwright-media-sources';

    /**
     * @var string
     */
    private const PNG_4X3 = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAACXBIWXMAAA7EAAAOxAGVKw4b'
        . 'AAAAFElEQVQImWMU6YligAEmBiSAwgEAJHgBAMXOa18AAAAASUVORK5CYII=';

    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    private string $mediaDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'the-encryption-key';

        $fixturesPath = rtrim(Environment::getProjectPath(), '/') . '/playwright-fixtures';
        GeneralUtility::mkdir_deep($fixturesPath);
        file_put_contents(
            $fixturesPath . '/pages.sql',
            "INSERT INTO pages (uid, pid, title) VALUES (99, 0, 'Fixture root');"
        );

        $this->mediaDirectory = rtrim(Environment::getProjectPath(), '/') . '/' . self::MEDIA_PATH;
        GeneralUtility::rmdir($this->mediaDirectory, true);
        GeneralUtility::mkdir_deep($this->mediaDirectory);
        file_put_contents($this->mediaDirectory . '/hero.png', (string) base64_decode(self::PNG_4X3, true));

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
            'fixturesPath' => 'playwright-fixtures',
            'fixtureManifest' => 'pages.sql',
            'preseededSessionId' => 'playwright_test_session',
            'sessionUserId' => '1',
            'mediaPath' => self::MEDIA_PATH,
        ];
    }

    #[Test]
    public function seedsTheMediaAndWritesTheManifest(): void
    {
        $this->get(TemplatePreparer::class)->prepare();

        self::assertSame(['hero.png'], array_keys($this->manifest()));
        self::assertGreaterThan(0, $this->manifest()['hero.png']);
    }

    #[Test]
    public function rebuildsNothingWhenTheSourcesAndTheDestinationAreUnchanged(): void
    {
        $preparer = $this->get(TemplatePreparer::class);
        $preparer->prepare();

        self::assertFalse($preparer->prepare()['built']);
    }

    #[Test]
    public function rebuildsWhenAPublishedFileWentMissing(): void
    {
        $preparer = $this->get(TemplatePreparer::class);
        $preparer->prepare();

        unlink(rtrim(Environment::getPublicPath(), '/') . '/' . MediaSeeder::BASE_PATH . 'hero.png');

        self::assertTrue($preparer->prepare()['built'], 'a missing published file did not force a rebuild');
        self::assertFileExists(rtrim(Environment::getPublicPath(), '/') . '/' . MediaSeeder::BASE_PATH . 'hero.png');
    }

    #[Test]
    public function removesTheManifestWhenMediaSeedingIsSwitchedOff(): void
    {
        $this->get(TemplatePreparer::class)->prepare();
        self::assertFileExists($this->get(MediaManifest::class)->file());

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit']['mediaPath'] = '';

        $this->get(TemplatePreparer::class)->prepare();

        self::assertFileDoesNotExist($this->get(MediaManifest::class)->file());
    }

    /**
     * @return array<string, int>
     */
    private function manifest(): array
    {
        $file = $this->get(MediaManifest::class)->file();
        self::assertFileExists($file);

        /** @var array<string, int> $decoded */
        $decoded = (array) json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
