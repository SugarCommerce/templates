<?php
/**
 * Resolving, compiling and rendering templates.
 *
 * @package   SugarCommerce\Templates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Templates;

/**
 * Class Engine
 *
 * ```php
 * $engine = new Engine( __DIR__ . '/templates', __DIR__ . '/var/cache' );
 *
 * echo $engine->render( 'admin.files', array( 'page' => $page ) );
 * ```
 *
 * A template compiles to a PHP file, which is then `require`d. So a template
 * *is* code, and this is a renderer rather than a sandbox: never compile a
 * template somebody else wrote. Content an operator can edit belongs in a
 * string with placeholders, not here.
 *
 * @since 1.0.0
 */
final class Engine {

	/**
	 * How many layouts deep a template may nest.
	 *
	 * A layout that extends itself is a hang rather than an error, so this
	 * turns it into one that names the file.
	 */
	private const int MAX_LAYOUTS = 16;

	private readonly Compiler $compiler;

	/**
	 * @param string $root      Directory templates are resolved against.
	 * @param string $cache     Directory for compiled output. Created if it
	 *                          does not exist.
	 * @param string $extension Template file extension.
	 * @param bool   $always    Recompile on every render. For development,
	 *                          where a template's own `@include`s can change
	 *                          without its mtime moving.
	 */
	public function __construct(
		private readonly string $root,
		private readonly string $cache,
		private readonly string $extension = '.blade.php',
		private readonly bool $always = false,
		?Compiler $compiler = null,
	) {
		$this->compiler = $compiler ?? new Compiler();
	}

	/**
	 * Escape a value for HTML.
	 *
	 * What `{{ }}` compiles to. Static because compiled templates call it by
	 * name rather than holding a reference to anything.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Value to print.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException On a value with no sensible string
	 *                                   form. Printing "Array" into a page is
	 *                                   a bug that survives review; this does
	 *                                   not.
	 */
	public static function e( mixed $value ): string {
		if ( null === $value || false === $value ) {
			return '';
		}

		if ( true === $value ) {
			return '1';
		}

		if ( is_array( $value ) || ( is_object( $value ) && ! $value instanceof \Stringable ) ) {
			throw new \InvalidArgumentException(
				'Cannot print a ' . get_debug_type( $value ) . '. Pick a value out of it, or use a helper.'
			);
		}

		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Render a template.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Template name, e.g. `admin.files` or `admin/files`.
	 * @param array  $data Variables for its scope.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When a template is missing, or layouts nest
	 *                           too deeply to be anything but a loop.
	 */
	public function render( string $name, array $data = array() ): string {
		$environment = new Environment( $this );
		$output      = $this->evaluate( $name, $data, $environment );

		// A template that extends a layout has its own output discarded: what
		// it contributes is its sections, and the layout decides where those
		// land. The layout may extend another in turn.
		for ( $depth = 0; null !== ( $layout = $environment->take_layout() ); $depth++ ) {
			if ( $depth >= self::MAX_LAYOUTS ) {
				throw new \RuntimeException( 'Layouts nested more than ' . self::MAX_LAYOUTS . ' deep, starting at ' . $name . '. A layout probably extends itself.' );
			}

			$output = $this->evaluate( $layout, $data, $environment );
		}

		return $output;
	}

	/**
	 * Is there such a template?
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Template name.
	 *
	 * @return bool
	 */
	public function exists( string $name ): bool {
		return is_file( $this->path( $name ) );
	}

	/**
	 * The file a template name resolves to.
	 *
	 * Dots and slashes both separate directories, so `admin.files` and
	 * `admin/files` are the same template.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Template name.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException On a name that climbs out of the root.
	 */
	public function path( string $name ): string {
		// Before the dots become slashes, or `..` turns into `//` and the
		// check below can never fire. A template name is written in code,
		// but it costs nothing to refuse the one shape that would turn a bad
		// refactor into a file read.
		if ( str_contains( $name, '..' ) ) {
			throw new \InvalidArgumentException( 'A template name may not contain "..": ' . $name );
		}

		$relative = str_replace( '.', '/', trim( $name, '/' ) );

		return rtrim( $this->root, '/' ) . '/' . $relative . $this->extension;
	}

	/**
	 * Run a template and return its output.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $name        Template name.
	 * @param array       $data        Variables for its scope.
	 * @param Environment $environment This render's sections and layout.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When the template does not exist.
	 */
	private function evaluate( string $name, array $data, Environment $environment ): string {
		$compiled = $this->compiled( $name );
		$depth    = ob_get_level();

		ob_start();

		try {
			( static function ( string $__compiled, array $__data, Environment $__env ): void {
				// EXTR_SKIP so a data key named __env cannot replace the one
				// every compiled template depends on.
				extract( $__data, EXTR_SKIP );

				require $__compiled;
			} )( $compiled, $data, $environment );
		} catch ( \Throwable $error ) {
			// A template that threw mid-section leaves output buffers open.
			// Without this every later render appends to them.
			$environment->unwind( $depth );

			throw $error;
		}

		return (string) ob_get_clean();
	}

	/**
	 * The compiled file for a template, compiling it if it is stale.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Template name.
	 *
	 * @return string Path to the compiled PHP.
	 *
	 * @throws \RuntimeException When the template is missing or the cache
	 *                           cannot be written.
	 */
	private function compiled( string $name ): string {
		$source = $this->path( $name );

		if ( ! is_file( $source ) ) {
			throw new \RuntimeException( 'No such template: ' . $name . ' (looked in ' . $source . ')' );
		}

		$target = rtrim( $this->cache, '/' ) . '/' . hash( 'xxh128', $source ) . '.php';

		if ( ! $this->always && is_file( $target ) && filemtime( $target ) >= filemtime( $source ) ) {
			return $target;
		}

		$this->write( $target, $this->compiler->compile( (string) file_get_contents( $source ), $source ) );

		return $target;
	}

	/**
	 * Write a compiled template.
	 *
	 * Through a temporary file and a rename, because rename is atomic on the
	 * same filesystem. Writing in place means a concurrent request can
	 * `require` a file that is half-written, which fails as a parse error in
	 * a page nobody changed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $target   Destination path.
	 * @param string $contents Compiled PHP.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the cache directory cannot be written.
	 */
	private function write( string $target, string $contents ): void {
		$directory = dirname( $target );

		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0o775, true ) && ! is_dir( $directory ) ) {
			throw new \RuntimeException( 'Cannot create the template cache directory: ' . $directory );
		}

		$temporary = $target . '.' . getmypid() . '.tmp';

		if ( false === @file_put_contents( $temporary, $contents ) || ! @rename( $temporary, $target ) ) {
			@unlink( $temporary );

			throw new \RuntimeException( 'Cannot write the compiled template: ' . $target );
		}
	}
}
