<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Report;

use MergeMultisite\Report\PostMigrationTodoWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * PostMigrationTodoWriter pure logic: the markdown sections, the
 * permalink/homepage/theme steps, per-plugin install commands, and
 * the report/log path list.
 *
 * @package MergeMultisite
 */
#[CoversClass( PostMigrationTodoWriter::class )]
final class PostMigrationTodoWriterTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function data( array $overrides = array() ): array {
		return array_merge(
			array(
				'generated_at' => '2026-10-16 12:00:00',
				'destination_admin_url' => 'https://example.test/wp-admin/',
				'login_note' => 'Log in with the destination admin account you created.',
				'front_page' => array(
					'blog_id' => 7,
					'show_on_front' => 'page',
					'page_on_front' => 101,
					'page_on_front_destination' => 501,
					'page_for_posts' => 102,
					'page_for_posts_destination' => 502,
				),
				'active_plugins_union' => array( 'akismet', 'wordpress-seo' ),
				'report_paths' => array( '/repo/var/reports/migrate-20261016.json' ),
				'log_path' => '/repo/var/logs/migrate-20261016.log',
			),
			$overrides
		);
	}

	public function testMarkdownContainsAdminPermalinkAndThemeSteps(): void {
		$markdown = ( new PostMigrationTodoWriter() )->toMarkdown( $this->data() );

		self::assertStringContainsString( '## Destination admin', $markdown );
		self::assertStringContainsString( 'Admin URL: https://example.test/wp-admin/', $markdown );
		self::assertStringContainsString( '## Permalinks', $markdown );
		self::assertStringContainsString( '    wp rewrite flush', $markdown );
		self::assertStringContainsString( '## Theme', $markdown );
		self::assertStringContainsString( '    wp theme activate <theme-slug>', $markdown );
	}

	public function testMarkdownShowsHomepageCommandsWithMigratedFrontPage(): void {
		$markdown = ( new PostMigrationTodoWriter() )->toMarkdown( $this->data() );

		self::assertStringContainsString( '## Homepage (Settings → Reading)', $markdown );
		self::assertStringContainsString( 'Migrated front page: site 7, destination post ID 501.', $markdown );
		self::assertStringContainsString( '    wp option update show_on_front page', $markdown );
		self::assertStringContainsString( '    wp option update page_on_front 501', $markdown );
		self::assertStringContainsString( '    wp option update page_for_posts 502', $markdown );
	}

	public function testMarkdownHandlesMissingFrontPageData(): void {
		$data = $this->data( array( 'front_page' => null ) );
		$markdown = ( new PostMigrationTodoWriter() )->toMarkdown( $data );

		self::assertStringContainsString( 'Set it to "A static page" and select the migrated front page.', $markdown );
		self::assertStringNotContainsString( 'wp option update page_on_front', $markdown );
	}

	public function testMarkdownHandlesUnmigratedFrontPage(): void {
		$data = $this->data(
			array(
				'front_page' => array(
					'blog_id' => 7,
					'show_on_front' => 'page',
					'page_on_front' => 101,
					'page_on_front_destination' => null,
					'page_for_posts' => 0,
					'page_for_posts_destination' => null,
				),
			)
		);
		$markdown = ( new PostMigrationTodoWriter() )->toMarkdown( $data );

		self::assertStringContainsString( 'The source front page was not migrated', $markdown );
		self::assertStringContainsString( 'post ID 101 on site 7', $markdown );
	}

	public function testMarkdownListsPluginInstallCommands(): void {
		$markdown = ( new PostMigrationTodoWriter() )->toMarkdown( $this->data() );

		self::assertStringContainsString( '## Plugins to install on the destination', $markdown );
		self::assertStringContainsString( 'akismet, wordpress-seo', $markdown );
		self::assertStringContainsString( '    wp plugin install akismet --force', $markdown );
		self::assertStringContainsString( '    wp plugin install akismet --force --activate', $markdown );
		self::assertStringContainsString( '    wp plugin install wordpress-seo --force', $markdown );
		self::assertStringContainsString( '    wp plugin install wordpress-seo --force --activate', $markdown );
	}

	public function testMarkdownListsReportAndLogPaths(): void {
		$markdown = ( new PostMigrationTodoWriter() )->toMarkdown( $this->data() );

		self::assertStringContainsString( '## Reports and logs written this run', $markdown );
		self::assertStringContainsString( '- /repo/var/reports/migrate-20261016.json', $markdown );
		self::assertStringContainsString( '- /repo/var/logs/migrate-20261016.log', $markdown );
	}

	public function testMarkdownHandlesNoActivePlugins(): void {
		$data = $this->data( array( 'active_plugins_union' => array() ) );
		$markdown = ( new PostMigrationTodoWriter() )->toMarkdown( $data );

		self::assertStringContainsString( '_No active source plugins detected._', $markdown );
		self::assertStringNotContainsString( 'wp plugin install', $markdown );
	}
}
