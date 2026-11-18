<?php
/**
 * Which of a theme's copies are older than the plugin's.
 *
 * @package SugarCommerce\Templates
 */

declare( strict_types=1 );

namespace SugarCommerce\Templates\Tests;

use SugarCommerce\Templates\Overrides;

/**
 * Class OverridesTest
 */
final class OverridesTest extends TemplatesTestCase {

	/**
	 * Every template in a folder, relative to it, sorted, and nothing that is not PHP.
	 */
	public function test_files_lists_the_templates(): void {
		$this->write( 'plugin/templates/receipt/pending.php', '' );
		$this->write( 'plugin/templates/cart/cart.php', '' );
		$this->write( 'plugin/templates/cart/notes.txt', '' );
		$this->write( 'plugin/templates/a.php', '' );

		$this->assertSame( [ 'a.php', 'cart/cart.php', 'receipt/pending.php' ], Overrides::files( $this->root . '/plugin/templates' ) );
		$this->assertSame( [], Overrides::files( $this->root . '/not-there' ) );
		$this->assertSame( [], Overrides::files( '' ) );
	}

	/**
	 * The `@version` in a docblock, however the file was saved, and '' when there is none.
	 */
	public function test_version_reads_the_docblock(): void {
		$this->write( 'a.php', "<?php\n/**\n * The cart.\n *\n * @version 1.4.2\n */\n" );
		$this->write( 'b.php', "<?php\r/**\r * @version   2.0.0-beta.1\r */\r" );
		$this->write( 'c.php', "<?php\r\n/**\r\n * @VERSION 3.1\r\n */\r\n" );
		$this->write( 'd.php', "<?php\n// Copied without its docblock.\n" );
		$this->write( 'e.php', "<?php\n/* Version: 9.9.9 */\n// mentions @version in passing\n" );

		$this->assertSame( '1.4.2', Overrides::version( $this->root . '/a.php' ) );
		$this->assertSame( '2.0.0-beta.1', Overrides::version( $this->root . '/b.php' ) );
		$this->assertSame( '3.1', Overrides::version( $this->root . '/c.php' ) );
		$this->assertSame( '', Overrides::version( $this->root . '/d.php' ) );
		$this->assertSame( '', Overrides::version( $this->root . '/e.php' ) );
		$this->assertSame( '', Overrides::version( $this->root . '/missing.php' ) );
	}

	/**
	 * Older copies and copies with no version are listed; up-to-date and newer ones are not.
	 */
	public function test_outdated_lists_the_old_copies(): void {
		$this->write( 'plugin/templates/cart/cart.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		$this->write( 'plugin/templates/receipt/pending.php', "<?php\n/**\n * @version 1.2.0\n */\n" );
		$this->write( 'plugin/templates/receipt/items.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		$this->write( 'plugin/templates/account/account.php', "<?php\n/**\n * @version 1.0.0\n */\n" );

		$sources = $this->loader()->scan_paths( 'My Plugin' );

		$this->assertSame( [], Overrides::outdated( $sources ) );

		// Up to date, and newer: not listed.
		$this->write( 'themes/child/myplugin/cart/cart.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		$this->write( 'themes/child/myplugin/account/account.php', "<?php\n/**\n * @version 1.0.1\n */\n" );

		$this->assertSame( [], Overrides::outdated( $sources ) );

		// Older, in the parent; and one with no version at all.
		$this->write( 'themes/parent/myplugin/receipt/pending.php', "<?php\n/**\n * @version 1.1.9\n */\n" );
		$this->write( 'themes/child/myplugin/receipt/items.php', "<?php\n// Copied without its docblock.\n" );

		$this->assertSame(
			[
				[
					'source'        => 'My Plugin',
					'name'          => 'receipt/items.php',
					'file'          => $this->root . '/themes/child/myplugin/receipt/items.php',
					'version'       => '1.0.0',
					'theme_version' => '',
				],
				[
					'source'        => 'My Plugin',
					'name'          => 'receipt/pending.php',
					'file'          => $this->root . '/themes/parent/myplugin/receipt/pending.php',
					'version'       => '1.2.0',
					'theme_version' => '1.1.9',
				],
			],
			Overrides::outdated( $sources )
		);

		// Neither the plugin's nor the theme's says a version: nobody can say what it was copied from.
		$this->write( 'plugin/templates/unversioned.php', "<?php\n" );
		$this->write( 'themes/child/myplugin/unversioned.php', "<?php\n" );

		$this->assertContains( 'unversioned.php', array_column( Overrides::outdated( $sources ), 'name' ) );

		unlink( $this->root . '/themes/child/myplugin/unversioned.php' );

		// A copy in the parent that the child replaces is not the one loaded.
		$this->write( 'themes/child/myplugin/receipt/pending.php', "<?php\n/**\n * @version 1.2.0\n */\n" );

		$this->assertSame( [ 'receipt/items.php' ], array_column( Overrides::outdated( $sources ), 'name' ) );
	}

	/**
	 * Every source is checked under its own theme folder and reported by its own name.
	 */
	public function test_each_source_is_checked(): void {
		$this->write( 'plugin/templates/cart/cart.php', "<?php\n/**\n * @version 1.0.0\n */\n" );
		$this->write( 'addon/dashboard.php', "<?php\n/**\n * @version 2.1.0\n */\n" );
		$this->write( 'themes/child/myplugin/affiliates/dashboard.php', "<?php\n/**\n * @version 2.0.9\n */\n" );

		// The add-on's file in the plugin's folder is not its copy.
		$this->write( 'themes/child/myplugin/dashboard.php', "<?php\n/**\n * @version 0.1.0\n */\n" );

		$outdated = Overrides::outdated(
			[
				'My Plugin'  => $this->loader()->sources(),
				'Affiliates' => [
					'default_path'  => $this->root . '/addon',
					'template_path' => 'myplugin/affiliates',
				],
			]
		);

		$this->assertCount( 1, $outdated );
		$this->assertSame( 'Affiliates', $outdated[0]['source'] );
		$this->assertSame( '2.0.9', $outdated[0]['theme_version'] );
		$this->assertSame( '2.1.0', $outdated[0]['version'] );
	}
}
