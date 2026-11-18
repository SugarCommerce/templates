<?php
/**
 * A child theme, a parent theme, a plugin and an add-on, in a folder of their own.
 *
 * @package SugarCommerce\Templates
 */

declare( strict_types=1 );

namespace SugarCommerce\Templates\Tests;

use SugarCommerce\Templates\Loader;
use PHPUnit\Framework\TestCase;

/**
 * Each test gets a child and a parent theme in a temporary folder, a
 * plugin's templates folder and an add-on's beside them, a clean set of
 * hooks, and a record of every _doing_it_wrong() call.
 */
abstract class TemplatesTestCase extends TestCase {

	/**
	 * The temporary folder.
	 */
	protected string $root = '';

	/**
	 * Make the folders and point the theme lookup at them.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->root = sys_get_temp_dir() . '/sugarcommerce-templates-' . bin2hex( random_bytes( 4 ) );

		foreach ( [ 'themes/child', 'themes/parent', 'plugin/templates', 'addon' ] as $folder ) {
			mkdir( $this->root . '/' . $folder, 0777, true );
		}

		$GLOBALS['tl_hooks']  = [];
		$GLOBALS['tl_wrong']  = [];
		$GLOBALS['tl_themes'] = [
			'child'  => $this->root . '/themes/child',
			'parent' => $this->root . '/themes/parent',
			'root'   => $this->root . '/themes',
		];
	}

	/**
	 * Remove the folders.
	 */
	protected function tearDown(): void {
		$this->remove( $this->root );

		$GLOBALS['tl_hooks'] = [];

		parent::tearDown();
	}

	/**
	 * A loader for the plugin, as a plugin would make it.
	 *
	 * @param bool $debug Whether to ignore the theme.
	 *
	 * @return Loader
	 */
	protected function loader( bool $debug = false ): Loader {
		return new Loader( 'myplugin', $this->root . '/plugin/templates/', 'myplugin/', '2.3.0', $debug );
	}

	/**
	 * The methods _doing_it_wrong() was called about.
	 *
	 * @return array<int, string>
	 */
	protected function wrong(): array {
		return array_column( $GLOBALS['tl_wrong'], 0 );
	}

	/**
	 * Write a file under the temporary folder.
	 *
	 * @param string $path     Relative to it.
	 * @param string $contents What it holds.
	 */
	protected function write( string $path, string $contents ): void {
		$file = $this->root . '/' . $path;

		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0777, true );
		}

		file_put_contents( $file, $contents );
	}

	/**
	 * Remove a folder and everything in it.
	 *
	 * @param string $folder The folder.
	 */
	private function remove( string $folder ): void {
		if ( ! is_dir( $folder ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $folder, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}

		rmdir( $folder );
	}
}
