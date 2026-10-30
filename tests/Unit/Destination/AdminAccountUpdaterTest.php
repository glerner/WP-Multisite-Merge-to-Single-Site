<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Destination;

use MergeMultisite\Destination\AdminAccountUpdater;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Login validation/sanitization for AdminAccountUpdater: the checks that
 * stop a typo'd config value from being written to wp_users.
 *
 * @package MergeMultisite
 */
#[CoversClass( AdminAccountUpdater::class )]
final class AdminAccountUpdaterTest extends TestCase {

	public function testValidateLoginAcceptsTypicalLogins(): void {
		$updater = new AdminAccountUpdater();

		self::assertNull( $updater->validateLogin( 'myadm' ) );
		self::assertNull( $updater->validateLogin( 'myadmin_random' ) );
		self::assertNull( $updater->validateLogin( 'admin.user-name@example' ) );
		self::assertNull( $updater->validateLogin( str_repeat( 'a', 60 ) ) );
	}

	public function testValidateLoginRejectsEmpty(): void {
		$updater = new AdminAccountUpdater();

		self::assertNotNull( $updater->validateLogin( '' ) );
	}

	public function testValidateLoginRejectsOver60Characters(): void {
		$updater = new AdminAccountUpdater();

		$problem = $updater->validateLogin( str_repeat( 'a', 61 ) );

		self::assertNotNull( $problem );
		self::assertStringContainsString( '60', (string) $problem );
	}

	public function testValidateLoginRejectsCharactersWordpressStrips(): void {
		$updater = new AdminAccountUpdater();

		$problem = $updater->validateLogin( 'bad<login>' );

		self::assertNotNull( $problem );
		self::assertStringContainsString( 'badlogin', (string) $problem );
	}

	public function testSanitizeLoginKeepsWordpressAllowedCharacters(): void {
		$updater = new AdminAccountUpdater();

		self::assertSame( 'a b_c-d.e@f', $updater->sanitizeLogin( 'a b_c-d.e@f' ) );
		self::assertSame( 'badlogin', $updater->sanitizeLogin( 'bad<login>' ) );
	}

	public function testNicenameForMatchesSanitizeTitleShape(): void {
		$updater = new AdminAccountUpdater();

		self::assertSame( 'myadmin_random', $updater->nicenameFor( 'myadmin_random' ) );
		self::assertSame( 'john-doe', $updater->nicenameFor( 'John Doe' ) );
	}
}
