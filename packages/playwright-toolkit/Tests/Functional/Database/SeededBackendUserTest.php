<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Functional\Database;

use Plan2net\PlaywrightToolkit\Database\SeededBackendUser;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SeededBackendUserTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'plan2net/playwright-toolkit',
    ];

    #[Test]
    public function itsPasswordMatchesNoHashAlgorithmSoTheLoginFormCannotAcceptIt(): void
    {
        $this->expectException(InvalidPasswordHashException::class);

        GeneralUtility::makeInstance(PasswordHashFactory::class)
            ->get((string)SeededBackendUser::row(1)['password'], 'BE');
    }

    #[Test]
    public function theSameLookupDoesResolveARealHash(): void
    {
        $password = 'correct horse battery staple';
        $hash = GeneralUtility::makeInstance(PasswordHashFactory::class)
            ->getDefaultHashInstance('BE')
            ->getHashedPassword($password);
        if (null === $hash) {
            self::fail('TYPO3 could not hash a password at all.');
        }

        $instance = GeneralUtility::makeInstance(PasswordHashFactory::class)->get($hash, 'BE');

        self::assertTrue($instance->checkPassword($password, $hash));
    }
}
