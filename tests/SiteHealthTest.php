<?php
/**
 * The Site Health check for outdated theme copies.
 *
 * @package SugarCommerce\Templates
 */

declare( strict_types=1 );

namespace SugarCommerce\Templates\Tests;

use SugarCommerce\Templates\SiteHealth;
use InvalidArgumentException;

/**
 * Class SiteHealthTest
 */
final class SiteHealthTest extends TemplatesTestCase {

	/**
	 * A check for the plugin's loader, as a plugin would register it.
	 *
	 * @param array<string, mixed> $args In place of the defaults.
	 *
	 * @return SiteHealth
	 */
	private function check( array $args = [] ): SiteHealth {
		return SiteHealth::register(
			$args + [
				'id'             => 'myplugin_templates',
				'label'          => 'My Plugin',
				'sources'        => $this->loader(),
				'debug_constant' => 'MYPLUGIN_TEMPLATE_DEBUG_MODE',
				'folder'         => 'wp-content/plugins/my-plugin/templates/',
			]
		);
	}

	/**
	 * Some shipped templates, and a theme with copies of two of them out of date.
	 */
	private function outdated_copies(): void {
		$this->write( 'plugin/templates/cart/cart.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		$this->write( 'plugin/templates/receipt/pending.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		$this->write( 'plugin/templates/receipt/items.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		$this->write( 'themes/child/myplugin/cart/cart.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		$this->write( 'themes/parent/myplugin/receipt/pending.php', "<?php\n/**\n * @version 0.9.0\n */\n" );
		$this->write( 'themes/child/myplugin/receipt/items.php', "<?php\n// Copied without its docblock.\n" );
	}

	/**
	 * Registering adds the check to WordPress's list under its id.
	 */
	public function test_it_is_in_wordpresss_list(): void {
		$check = $this->check();
		$tests = apply_filters( 'site_status_tests', [ 'direct' => [ 'other' => [] ] ] );

		$this->assertArrayHasKey( 'other', $tests['direct'] );
		$this->assertSame( 'My Plugin', $tests['direct']['myplugin_templates']['label'] );
		$this->assertSame( [ $check, 'check' ], $tests['direct']['myplugin_templates']['test'] );
	}

	/**
	 * Content when no copy is out of date.
	 */
	public function test_good_when_nothing_is_out_of_date(): void {
		$this->write( 'plugin/templates/cart/cart.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		$this->write( 'themes/child/myplugin/cart/cart.php', "<?php\n/**\n * @version 1.0.0\n */\n" );

		$this->assertSame(
			[
				'label'       => 'Your theme’s My Plugin templates are up to date',
				'status'      => 'good',
				'badge'       => [
					'label' => 'My Plugin',
					'color' => 'blue',
				],
				'description' => '<p>Any My Plugin template your theme overrides is the same version as the plugin’s.</p>',
				'actions'     => '',
				'test'        => 'myplugin_templates',
			],
			$this->check()->check()
		);
	}

	/**
	 * Each old copy is listed by its path from the themes folder, with both versions.
	 */
	public function test_the_old_copies_are_listed(): void {
		$this->outdated_copies();

		$result = $this->check()->check();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'myplugin_templates', $result['test'] );
		$this->assertSame( 'Your theme overrides 2 My Plugin templates with older copies', $result['label'] );
		$this->assertStringContainsString( '<li><code>parent/myplugin/receipt/pending.php</code> is version 0.9.0; the plugin’s is 1.0.0.</li>', $result['description'] );
		$this->assertStringContainsString( '<li><code>child/myplugin/receipt/items.php</code> is version (none); the plugin’s is 1.0.0.</li>', $result['description'] );
		$this->assertStringNotContainsString( 'cart/cart.php', $result['description'] );
		$this->assertStringContainsString( 'wp-content/plugins/my-plugin/templates/', $result['description'] );
		$this->assertStringContainsString( 'define <code>MYPLUGIN_TEMPLATE_DEBUG_MODE</code> as true', $result['description'] );

		// One is singular.
		$this->write( 'themes/child/myplugin/receipt/items.php', "<?php\n/**\n * @version 1.0.0\n */\n" );

		$this->assertSame( 'Your theme overrides 1 My Plugin template with an older copy', $this->check()->check()['label'] );
	}

	/**
	 * Without a debug constant or a folder, the advice does without them.
	 */
	public function test_the_advice_without_a_constant_or_a_folder(): void {
		$this->outdated_copies();

		$result = $this->check(
			[
				'debug_constant' => '',
				'folder'         => '',
			]
		)->check();

		$this->assertStringNotContainsString( 'wp-config.php', $result['description'] );
		$this->assertStringContainsString( 'Compare each copy with the plugin’s own and bring', $result['description'] );
	}

	/**
	 * A plugin's own wording replaces the library's, key by key, and is escaped.
	 */
	public function test_a_plugins_own_wording(): void {
		$this->outdated_copies();

		$result = $this->check(
			[
				'badge_color' => 'orange',
				'strings'     => [
					'outdated_label'       => static fn( int $count ): string => "$count stale <copies>",
					'outdated_description' => 'Old copies & how to fix them.',
					'row'                  => 'v%1$s < v%2$s',
					'none'                 => '?',
					'debug'                => 'Try %s.',
				],
			]
		)->check();

		$this->assertSame( '2 stale <copies>', $result['label'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertStringStartsWith( '<p>Old copies &amp; how to fix them.</p><ul>', $result['description'] );
		$this->assertStringContainsString( '</code> v0.9.0 &lt; v1.0.0</li>', $result['description'] );
		$this->assertStringContainsString( '</code> v? &lt; v1.0.0</li>', $result['description'] );
		$this->assertStringEndsWith( '<p>Try <code>MYPLUGIN_TEMPLATE_DEBUG_MODE</code>.</p>', $result['description'] );

		$this->write( 'themes/parent/myplugin/receipt/pending.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		$this->write( 'themes/child/myplugin/receipt/items.php', "<?php\n/**\n * @version 1.0.0\n */\n" );

		$good = $this->check(
			[
				'strings' => [
					'good_label'       => 'All fresh',
					'good_description' => 'Nothing <stale>.',
				],
			]
		)->check();

		$this->assertSame( 'All fresh', $good['label'] );
		$this->assertSame( '<p>Nothing &lt;stale&gt;.</p>', $good['description'] );
	}

	/**
	 * A Loader's sources include the folders an add-on adds through its filter.
	 */
	public function test_an_add_ons_copies_are_checked_through_the_loader(): void {
		$this->write( 'addon/dashboard.php', "<?php\n/**\n * @version 2.1.0\n */\n" );
		$this->write( 'themes/child/myplugin/affiliates/dashboard.php', "<?php\n/**\n * @version 2.0.9\n */\n" );

		add_filter(
			'myplugin_template_scan_paths',
			fn( array $paths ): array => $paths + [
				'Affiliates' => [
					'default_path'  => $this->root . '/addon/',
					'template_path' => 'myplugin/affiliates/',
				],
			]
		);

		$this->assertStringContainsString( 'child/myplugin/affiliates/dashboard.php', $this->check()->check()['description'] );
	}

	/**
	 * Sources as a callable, or as the array itself.
	 */
	public function test_sources_as_a_callable_or_an_array(): void {
		$this->outdated_copies();

		$sources = $this->loader()->scan_paths( 'Mine' );

		$this->assertSame( 'recommended', $this->check( [ 'sources' => static fn(): array => $sources ] )->check()['status'] );
		$this->assertSame( 'recommended', $this->check( [ 'sources' => $sources ] )->check()['status'] );
		$this->assertSame( 'good', $this->check( [ 'sources' => [] ] )->check()['status'] );
	}

	/**
	 * A file name in the report is escaped.
	 */
	public function test_a_file_name_is_escaped(): void {
		$this->write( 'plugin/templates/a<b>.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		$this->write( 'themes/child/myplugin/a<b>.php', "<?php\n" );

		$description = $this->check()->check()['description'];

		$this->assertStringContainsString( '<code>child/myplugin/a&lt;b&gt;.php</code>', $description );
	}

	/**
	 * Without an id, a label and sources there is nothing to register.
	 */
	public function test_the_essentials_are_required(): void {
		$this->expectException( InvalidArgumentException::class );

		new SiteHealth(
			[
				'id'    => 'myplugin_templates',
				'label' => 'My Plugin',
			]
		);
	}
}
