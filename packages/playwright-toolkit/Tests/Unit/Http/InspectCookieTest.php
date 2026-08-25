<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Tests\Unit\Http;

use Plan2net\PlaywrightToolkit\Http\InspectProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InspectCookieTest extends TestCase
{
    /** Script must never read a backend session, and no other site may send it. */
    #[Test]
    public function isHttpOnlyAndSameSite(): void
    {
        $header = InspectProvider::cookieHeader('be_typo_user', 'the-jwt');

        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringContainsString('Path=/', $header);
    }

    /** No Max-Age and no Expires: closing the browser leaves the test database. */
    #[Test]
    public function lastsOnlyForTheBrowserSession(): void
    {
        $header = InspectProvider::cookieHeader('be_typo_user', 'the-jwt');

        self::assertStringNotContainsString('Max-Age', $header);
        self::assertStringNotContainsString('Expires', $header);
    }

    /** A backend session must never travel over plain http, so this is not optional. */
    #[Test]
    public function isAlwaysSecure(): void
    {
        self::assertStringContainsString('Secure', InspectProvider::cookieHeader('a', 'b'));
    }

    #[Test]
    public function encodesAValueThatWouldOtherwiseBreakTheHeader(): void
    {
        $header = InspectProvider::cookieHeader('name', 'a b;c');

        self::assertStringStartsWith('name=a%20b%3Bc;', $header);
    }
}
