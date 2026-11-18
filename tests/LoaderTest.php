<?php
/**
 * Finding and loading templates, and a theme overriding them.
 *
 * @package SugarCommerce\Templates
 */

declare( strict_types=1 );

namespace SugarCommerce\Templates\Tests;

use SugarCommerce\Templates\Loader;

/**
 * Class LoaderTest
 */
final class LoaderTest extends TemplatesTestCase {

	/**
	 * The child theme's copy wins over the parent's, and the parent's over the plugin's.
	 */
	public function test_the_child_theme_then_the_parent_then_the_plugin(): void {
		$loader = $this->loader();

		$this->write( 'plugin/templates/receipt/pending.php', 'PLUGIN' );

		$this->assertSame( $this->root . '/plugin/templates/receipt/pending.php', $loader->locate( 'receipt/pending' ) );
		$this->assertSame( 'PLUGIN', $loader->html( 'receipt/pending' ) );

		$this->write( 'themes/parent/myplugin/receipt/pending.php', 'PARENT' );

		$this->assertSame( $this->root . '/themes/parent/myplugin/receipt/pending.php', $loader->locate( 'receipt/pending' ) );
		$this->assertSame( 'PARENT', $loader->html( 'receipt/pending' ) );

		$this->write( 'themes/child/myplugin/receipt/pending.php', 'CHILD' );

		$this->assertSame( $this->root . '/themes/child/myplugin/receipt/pending.php', $loader->locate( 'receipt/pending' ) );
		$this->assertSame( 'CHILD', $loader->html( 'receipt/pending' ) );

		// The copy only counts in the theme's folder for the plugin, not its root.
		$this->write( 'themes/child/receipt/pending.php', 'ROOT' );

		$this->assertSame( 'CHILD', $loader->html( 'receipt/pending' ) );

		// A name may carry its .php already.
		$this->assertSame( 'CHILD', $loader->html( 'receipt/pending.php' ) );
		$this->assertSame( [], $this->wrong() );
	}

	/**
	 * An array of names is a list of preferences: the first that exists anywhere wins.
	 */
	public function test_several_names_are_preferences(): void {
		$loader = $this->loader();

		$this->write( 'plugin/templates/product/single-book.php', 'BOOK' );
		$this->write( 'plugin/templates/product/single.php', 'SINGLE' );
		$this->write( 'themes/child/myplugin/product/single.php', 'THEMED SINGLE' );

		// The plugin's specific template beats the theme's general one.
		$this->assertSame( $this->root . '/plugin/templates/product/single-book.php', $loader->locate( [ 'product/single-book', 'product/single' ] ) );
		$this->assertSame( $this->root . '/themes/child/myplugin/product/single.php', $loader->locate( [ 'product/single-film', 'product/single' ] ) );
	}

	/**
	 * The theme's folder is the `{prefix}_template_path` filter's to change.
	 */
	public function test_the_theme_folder_can_be_renamed(): void {
		$loader = $this->loader();

		$this->write( 'plugin/templates/receipt/pending.php', 'PLUGIN' );
		$this->write( 'themes/child/shop/receipt/pending.php', 'SHOP' );

		$this->assertSame( 'myplugin/', $loader->template_path() );

		add_filter( 'myplugin_template_path', static fn(): string => 'shop' );

		$this->assertSame( 'shop/', $loader->template_path() );
		$this->assertSame( 'SHOP', $loader->html( 'receipt/pending' ) );
	}

	/**
	 * Two plugins bundling the library each have their own hooks.
	 */
	public function test_another_plugins_hooks_do_not_touch_this_one(): void {
		$this->write( 'plugin/templates/receipt/pending.php', 'PLUGIN' );
		$this->write( 'themes/child/shop/receipt/pending.php', 'SHOP' );

		add_filter( 'otherplugin_template_path', static fn(): string => 'shop' );
		add_filter( 'otherplugin_locate_template', static fn(): string => '/nowhere.php' );

		$this->assertSame( 'PLUGIN', $this->loader()->html( 'receipt/pending' ) );
	}

	/**
	 * An add-on's templates are found in its own folder and overridden from its own theme folder.
	 */
	public function test_an_add_on_passes_its_own_folders(): void {
		$loader = $this->loader();
		$addon  = $this->root . '/addon/';

		$this->write( 'addon/dashboard.php', 'ADDON <?php echo esc_html( $name ); ?>' );

		$this->assertSame( 'ADDON Ada', $loader->html( 'dashboard', [ 'name' => 'Ada' ], 'myplugin/affiliates/', $addon ) );

		// Not looked for among the plugin's own.
		$this->assertSame( '', $loader->locate( 'dashboard' ) );

		$this->write( 'themes/child/myplugin/affiliates/dashboard.php', 'THEMED <?php echo esc_html( $name ); ?>' );

		$this->assertSame( 'THEMED Ada', $loader->html( 'dashboard', [ 'name' => 'Ada' ], 'myplugin/affiliates', $addon ) );
	}

	/**
	 * `{slug}-{name}` when there is one, `{slug}` when there is not.
	 */
	public function test_a_template_part_falls_back_to_its_slug(): void {
		$addon = $this->root . '/addon/';

		$this->write( 'addon/card.php', 'CARD' );
		$this->write( 'addon/card-wide.php', 'WIDE' );

		$this->assertSame( 'WIDE', $this->part( 'card', 'wide', $addon ) );
		$this->assertSame( 'CARD', $this->part( 'card', 'narrow', $addon ) );
		$this->assertSame( 'CARD', $this->part( 'card', '', $addon ) );

		// The variant is preferred wherever it is: a theme's copy of the
		// general template does not beat the plugin's variant.
		$this->write( 'themes/child/myplugin/card.php', 'THEMED CARD' );

		$this->assertSame( 'WIDE', $this->part( 'card', 'wide', $addon ) );
		$this->assertSame( 'THEMED CARD', $this->part( 'card', 'narrow', $addon ) );
		$this->assertSame( [], $this->wrong() );
	}

	/**
	 * Each argument is a variable, and all of them are `$args`.
	 */
	public function test_the_arguments_are_variables_and_args(): void {
		$loader = $this->loader();
		$addon  = $this->root . '/addon/';

		$this->write(
			'addon/vars.php',
			'<?php echo esc_html( $greeting . "|" . $args["greeting"] . "|" . count( $args ) . "|" . ( isset( $this ) ? "this" : "no-this" ) . "|" . ( isset( $loader ) ? "loader" : "no-loader" ) . "|" . basename( __FILE__ ) );'
		);

		$this->assertSame( 'hello|hello|1|no-this|no-loader|vars.php', $loader->html( 'vars', [ 'greeting' => 'hello' ], 'myplugin/', $addon ) );

		// A key that cannot be a variable stays in $args only.
		$this->write( 'addon/odd.php', '<?php echo esc_html( $args["two words"] . "|" . implode( ",", array_keys( get_defined_vars() ) ) );' );

		$this->assertSame( 'kept|__file,args', $loader->html( 'odd', [ 'two words' => 'kept' ], 'myplugin/', $addon ) );
		$this->assertSame( [], $this->wrong() );

		// One named after something that means something already is
		// refused as a variable, and said to be.
		$this->write( 'addon/reserved.php', '<?php echo esc_html( is_array( $args ) ? "args-is-the-array" : "args-replaced" );' );

		$this->assertSame( 'args-is-the-array', $loader->html( 'reserved', [ 'args' => 'replaced' ], 'myplugin/', $addon ) );
		$this->assertSame( [ Loader::class . '::variables' ], $this->wrong() );

		$this->assertSame( 'args-is-the-array', $loader->html( 'reserved', [ '_POST' => 'replaced' ], 'myplugin/', $addon ) );
		$this->assertCount( 2, $this->wrong() );

		// The loader's own variable cannot be replaced by an argument to
		// load a different file.
		$this->write( 'addon/file.php', 'THE RIGHT FILE' );

		$GLOBALS['tl_wrong'] = [];

		$this->assertSame( 'THE RIGHT FILE', $loader->html( 'file', [ '__file' => '/etc/hosts' ], 'myplugin/', $addon ) );
		$this->assertSame( [ Loader::class . '::variables' ], $this->wrong() );

		// And any `__` name is the loader's: refused as a variable, said
		// to be, and still in $args.
		$this->write( 'addon/under.php', '<?php echo esc_html( ( isset( $__mine ) ? "set" : "unset" ) . "|" . $args["__mine"] );' );

		$this->assertSame( 'unset|kept', $loader->html( 'under', [ '__mine' => 'kept' ], 'myplugin/', $addon ) );
		$this->assertCount( 2, $this->wrong() );

		// One underscore is an ordinary name.
		$this->write( 'addon/single.php', '<?php echo esc_html( $_mine );' );

		$this->assertSame( 'fine', $loader->html( 'single', [ '_mine' => 'fine' ], 'myplugin/', $addon ) );
		$this->assertCount( 2, $this->wrong() );
	}

	/**
	 * Whether a template exists, in the theme or the plugin, without a notice when it does not.
	 */
	public function test_exists(): void {
		$loader = $this->loader();

		$this->write( 'plugin/templates/receipt/pending.php', 'PLUGIN' );
		$this->write( 'themes/parent/myplugin/cart/banner.php', 'BANNER' );
		$this->write( 'addon/dashboard.php', 'ADDON' );

		$this->assertTrue( $loader->exists( 'receipt/pending' ) );
		$this->assertTrue( $loader->exists( 'cart/banner' ) );
		$this->assertTrue( $loader->exists( [ 'nothing/here', 'cart/banner' ] ) );
		$this->assertFalse( $loader->exists( 'nothing/here' ) );
		$this->assertFalse( $loader->exists( 'dashboard' ) );
		$this->assertTrue( $loader->exists( 'dashboard', 'myplugin/affiliates/', $this->root . '/addon/' ) );
		$this->assertSame( [], $this->wrong() );

		// Only a theme's copy: with the theme ignored, there is none.
		$this->assertFalse( $this->loader( true )->exists( 'cart/banner' ) );

		// A name that leaves the folders is refused, and said to be.
		$this->assertFalse( $loader->exists( '../secret' ) );
		$this->assertSame( [ Loader::class . '::file' ], $this->wrong() );
	}

	/**
	 * A template that loads itself stops at the limit with a notice, rather than hanging.
	 */
	public function test_a_template_that_loads_itself_stops(): void {
		$loader = $this->loader();

		$GLOBALS['tl_loader'] = $loader;

		$this->write( 'plugin/templates/loop.php', 'x<?php $GLOBALS["tl_loader"]->get( "loop" );' );

		$html = $loader->html( 'loop' );

		unset( $GLOBALS['tl_loader'] );

		$this->assertSame( str_repeat( 'x', Loader::MAX_DEPTH ), $html );
		$this->assertSame( [ Loader::class . '::get' ], $this->wrong() );
		$this->assertStringContainsString( 'nested more than 64 deep at loop', $GLOBALS['tl_wrong'][0][1] );

		// The count comes back down: the same loader loads normally after.
		$this->write( 'plugin/templates/once.php', 'ONCE' );

		$this->assertSame( 'ONCE', $loader->html( 'once' ) );

		// Including after a template that threw on the way down.
		$this->write( 'plugin/templates/throws.php', '<?php throw new \RuntimeException( "x" );' );

		for ( $i = 0; $i < Loader::MAX_DEPTH + 1; $i++ ) {
			try {
				$loader->html( 'throws' );
			} catch ( \RuntimeException ) {
				// Expected.
			}
		}

		$this->assertSame( 'ONCE', $loader->html( 'once' ) );
		$this->assertCount( 1, $this->wrong() );

		// Nesting up to the limit is allowed.
		$GLOBALS['tl_loader'] = $loader;

		$this->write( 'plugin/templates/deep.php', '<?php echo esc_html( (string) $n ); if ( $n > 1 ) { $GLOBALS["tl_loader"]->get( "deep", [ "n" => $n - 1 ] ); }' );

		$html = $loader->html( 'deep', [ 'n' => Loader::MAX_DEPTH ] );

		unset( $GLOBALS['tl_loader'] );

		$this->assertStringEndsWith( '321', $html );
		$this->assertCount( 1, $this->wrong() );
	}

	/**
	 * JSON a template can print into a script block or a quoted attribute.
	 */
	public function test_json_is_safe_in_a_script_and_an_attribute(): void {
		$json = Loader::json(
			[
				'name' => '</script><script>alert(1)</script>',
				'q'    => "it's \"quoted\" & more",
				'url'  => 'https://example.com/a/b',
				'n'    => 3,
			]
		);

		$this->assertSame(
			'{"name":"\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E","q":"it\u0027s \u0022quoted\u0022 \u0026 more","url":"https://example.com/a/b","n":3}',
			$json
		);
		$this->assertStringNotContainsString( '<', $json );
		$this->assertStringNotContainsString( '"quoted', $json );
		$this->assertSame( [ 'name', 'q', 'url', 'n' ], array_keys( json_decode( $json, true ) ) );
		$this->assertSame( 'null', Loader::json( null ) );
		$this->assertSame( '"a"', Loader::json( 'a' ) );
		$this->assertSame( [], $this->wrong() );

		// A value with no JSON form is null, and a notice.
		$this->assertSame( 'null', Loader::json( INF ) );
		$this->assertSame( [ Loader::class . '::json' ], $this->wrong() );
	}

	/**
	 * The arguments filter, and the actions either side, hear the template's name, nested templates included.
	 */
	public function test_the_hooks_around_a_template(): void {
		$loader = $this->loader();
		$heard  = [];

		$GLOBALS['tl_loader'] = $loader;

		$this->write( 'plugin/templates/receipt/receipt.php', '<h2><?php echo esc_html( $title ); ?></h2><?php $GLOBALS["tl_loader"]->get( "order/downloads" ); $GLOBALS["tl_loader"]->get( "order/licenses" );' );
		$this->write( 'plugin/templates/order/downloads.php', 'D' );
		$this->write( 'plugin/templates/order/licenses.php', 'L' );

		add_filter(
			'myplugin_template_args',
			static fn( array $args, string $name ): array => 'receipt/receipt' === $name ? [ 'title' => 'Filtered' ] + $args : $args,
			10,
			2
		);
		add_action(
			'myplugin_before_template_part',
			static function ( string $name, string $path, string $located ) use ( &$heard ): void {
				$heard[] = 'before ' . $name . ' ' . basename( $located );
			},
			10,
			3
		);
		add_action(
			'myplugin_after_template_part',
			static function ( string $name ) use ( &$heard ): void {
				$heard[] = 'after ' . $name;
			}
		);

		$html = $loader->html( 'receipt/receipt', [ 'title' => 'Order 1' ] );

		unset( $GLOBALS['tl_loader'] );

		$this->assertSame( '<h2>Filtered</h2>DL', $html );
		$this->assertSame(
			[
				'before receipt/receipt receipt.php',
				'before order/downloads downloads.php',
				'after order/downloads',
				'before order/licenses licenses.php',
				'after order/licenses',
				'after receipt/receipt',
			],
			$heard
		);
	}

	/**
	 * The HTML version returns the output and leaves nothing printed or buffered.
	 */
	public function test_the_html_version_buffers_and_returns(): void {
		$loader = $this->loader();
		$level  = ob_get_level();

		$this->write( 'plugin/templates/receipt/pending.php', '<p class="pending">WAITING</p>' );

		ob_start();
		$html    = $loader->html( 'receipt/pending' );
		$printed = (string) ob_get_clean();

		$this->assertSame( '', $printed );
		$this->assertSame( '<p class="pending">WAITING</p>', $html );
		$this->assertSame( $level, ob_get_level() );

		// get() prints.
		ob_start();
		$loader->get( 'receipt/pending' );
		$this->assertSame( '<p class="pending">WAITING</p>', (string) ob_get_clean() );

		// A template that throws closes its buffer before the exception
		// leaves, so the rest of the page is not swallowed.
		$this->write( 'addon/throws.php', '<?php echo "PARTIAL"; throw new \RuntimeException( "inside" );' );

		try {
			$loader->html( 'throws', [], 'myplugin/', $this->root . '/addon/' );
			$this->fail( 'The exception should have reached the caller.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'inside', $e->getMessage() );
		}

		$this->assertSame( $level, ob_get_level() );

		// And so is any buffer the template opened itself before throwing.
		$this->write( 'addon/opens-throws.php', '<?php echo "A"; ob_start(); echo "B"; ob_start(); throw new \RuntimeException( "deeper" );' );

		try {
			$loader->html( 'opens-throws', [], 'myplugin/', $this->root . '/addon/' );
			$this->fail( 'The exception should have reached the caller.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'deeper', $e->getMessage() );
		}

		$this->assertSame( $level, ob_get_level() );

		// A template that opens buffers and returns without closing them:
		// what it printed into them is its output, in order, and nothing
		// is left open.
		$this->write( 'addon/opens.php', '<?php echo "A"; ob_start(); echo "B"; ob_start(); echo "C";' );

		$this->assertSame( 'ABC', $loader->html( 'opens', [], 'myplugin/', $this->root . '/addon/' ) );
		$this->assertSame( $level, ob_get_level() );
	}

	/**
	 * A template nobody has is a notice and no output, never a fatal.
	 */
	public function test_a_missing_template_is_a_notice(): void {
		$loader = $this->loader();

		$this->assertSame( '', $loader->html( 'nothing/here' ) );
		$this->assertSame( '', $loader->locate( 'nothing/here' ) );
		$this->assertSame( [ Loader::class . '::get' ], $this->wrong() );

		// Quoting the plugin's version, as _doing_it_wrong() asks.
		$this->assertSame( '2.3.0', $GLOBALS['tl_wrong'][0][2] );
		$this->assertStringContainsString( 'nothing/here', $GLOBALS['tl_wrong'][0][1] );
	}

	/**
	 * The `{prefix}_locate_template` filter has the last word on what a name resolves to.
	 */
	public function test_the_file_found_can_be_filtered(): void {
		$loader = $this->loader();

		$this->write( 'addon/instead.php', 'INSTEAD' );

		add_filter(
			'myplugin_locate_template',
			fn( string $located, $names ): string => 'special' === $names ? $this->root . '/addon/instead.php' : $located,
			10,
			2
		);

		$this->assertSame( 'INSTEAD', $loader->html( 'special' ) );
	}

	/**
	 * The `{prefix}_get_template` filter swaps the file, but not for one that does not exist.
	 */
	public function test_the_file_can_be_swapped_but_not_for_a_missing_one(): void {
		$loader = $this->loader();

		$this->write( 'plugin/templates/receipt/pending.php', 'PLUGIN' );
		$this->write( 'addon/instead.php', 'INSTEAD' );

		$instead = fn(): string => $this->root . '/addon/instead.php';

		add_filter( 'myplugin_get_template', $instead );
		$html = $loader->html( 'receipt/pending' );
		remove_filter( 'myplugin_get_template', $instead );

		$this->assertSame( 'INSTEAD', $html );
		$this->assertSame( [], $this->wrong() );

		$missing = fn(): string => $this->root . '/addon/not-there.php';

		add_filter( 'myplugin_get_template', $missing );
		$html = $loader->html( 'receipt/pending' );
		remove_filter( 'myplugin_get_template', $missing );

		$this->assertSame( '', $html );
		$this->assertSame( [ Loader::class . '::get' ], $this->wrong() );
	}

	/**
	 * A name that climbs out of the template folders is refused, wherever it would land.
	 */
	public function test_a_name_cannot_leave_the_template_folders(): void {
		$loader = $this->loader();

		// Things that exist where a climbing name would reach: a file in
		// the theme's root beside myplugin/, one above the themes, and one
		// in the plugin's folder above templates/.
		$this->write( 'themes/child/secret.php', 'THEME ROOT' );
		$this->write( 'themes/outside.php', 'OUTSIDE' );
		$this->write( 'plugin/main.php', 'MAIN' );
		$this->write( 'plugin/templates/receipt/pending.php', 'PLUGIN' );

		$names = [ '../secret', '../../outside', 'receipt/../../main', '../main', '/etc/hosts', '/' . $this->root . '/plugin/main', 'C:\\Windows\\win', 'C:main', "receipt/pending\0", "receipt/pending\n", "receipt\x7Fpending", ' ', '' ];

		foreach ( $names as $name ) {
			$GLOBALS['tl_wrong'] = [];

			// Refused, and said to be, before anything is looked for.
			$this->assertSame( '', $loader->locate( $name ), $name );
			$this->assertSame( [ Loader::class . '::file' ], $this->wrong(), $name );

			// And again on loading, which then has nothing to load.
			$this->assertSame( '', $loader->html( $name ), $name );
			$this->assertSame( [ Loader::class . '::file', Loader::class . '::file', Loader::class . '::get' ], $this->wrong(), $name );
		}

		// And not through an add-on's folders either.
		$this->assertSame( '', $loader->html( '../outside', [], 'myplugin/', $this->root . '/addon/' ) );

		// A dot that is not a climb is an ordinary name.
		$this->write( 'plugin/templates/v1.2/card..wide.php', 'DOTS' );

		$this->assertSame( 'DOTS', $loader->html( 'v1.2/card..wide' ) );
	}

	/**
	 * With the debug switch on, copies in the theme are ignored.
	 */
	public function test_debug_mode_ignores_the_theme(): void {
		$this->write( 'plugin/templates/receipt/pending.php', 'PLUGIN' );
		$this->write( 'themes/child/myplugin/receipt/pending.php', 'CHILD' );

		$debugging = $this->loader( true );
		$normal    = $this->loader();

		$this->assertTrue( $debugging->debugging() );
		$this->assertFalse( $normal->debugging() );
		$this->assertSame( $this->root . '/plugin/templates/receipt/pending.php', $debugging->locate( 'receipt/pending' ) );
		$this->assertSame( $this->root . '/themes/child/myplugin/receipt/pending.php', $normal->locate( 'receipt/pending' ) );
		$this->assertSame( 'PLUGIN', $debugging->html( 'receipt/pending' ) );
	}

	/**
	 * The loader's folders, and the folders an add-on adds for the outdated-copy report.
	 */
	public function test_sources_and_scan_paths(): void {
		$loader = new Loader( 'myplugin', $this->root . '/plugin/templates', 'myplugin', '2.3.0' );

		$this->assertSame( $this->root . '/plugin/templates/', $loader->default_path() );
		$this->assertSame(
			[
				'default_path'  => $this->root . '/plugin/templates/',
				'template_path' => 'myplugin/',
			],
			$loader->sources()
		);
		$this->assertSame( [ 'My Plugin' => $loader->sources() ], $loader->scan_paths( 'My Plugin' ) );

		add_filter(
			'myplugin_template_scan_paths',
			fn( array $paths ): array => $paths + [
				'Affiliates' => [
					'default_path'  => $this->root . '/addon/',
					'template_path' => 'myplugin/affiliates/',
				],
			]
		);

		$this->assertSame( [ 'My Plugin', 'Affiliates' ], array_keys( $loader->scan_paths( 'My Plugin' ) ) );
	}

	/**
	 * Load a template part and return what it printed.
	 *
	 * @param string $slug  The general template.
	 * @param string $name  The variant.
	 * @param string $addon The add-on's folder.
	 *
	 * @return string
	 */
	private function part( string $slug, string $name, string $addon ): string {
		ob_start();
		$this->loader()->part( $slug, $name, [], 'myplugin/', $addon );

		return (string) ob_get_clean();
	}
}
