<?php
/**
 * Finds and loads a plugin's front-end templates, letting a theme override them.
 *
 * @package   SugarCommerce\Templates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Templates;

/**
 * WooCommerce's template loader, in the shape of a class a plugin configures.
 *
 * A template is a plain PHP file in the plugin's own templates folder. A
 * theme overrides one by copying it to the same place under its own
 * folder for the plugin (`{theme}/{template_path}{name}.php`), the child
 * theme first and then its parent, as WordPress's own locate_template()
 * looks. An add-on uses the same loader for its own templates by passing
 * its own folder as the default path and a folder of its own inside the
 * theme's as the template path -- and themes override those too.
 *
 * Nothing in here names a plugin. The hook prefix, the folders, the
 * version a notice quotes and whether the theme is looked in at all are
 * given to the constructor, so every plugin that bundles this has a loader
 * of its own, with hooks of its own.
 *
 * Hooks, with `{prefix}` the prefix given:
 *
 *   - `{prefix}_template_path` filters the folder in the theme;
 *   - `{prefix}_locate_template` filters the file found for a name;
 *   - `{prefix}_get_template` swaps the file about to be loaded -- one
 *     that does not exist is a notice and nothing is loaded;
 *   - `{prefix}_template_args` filters what a template is given;
 *   - `{prefix}_before_template_part` / `{prefix}_after_template_part`
 *     run either side of it;
 *   - `{prefix}_template_scan_paths` filters the folders scan_paths()
 *     gives the outdated-copy report, so an add-on's are checked too.
 *
 * Deliberately no cache of the paths found. WooCommerce keeps one because
 * a shop page draws hundreds of template parts; a lookup here is at most
 * three file checks that PHP's own stat cache answers after the first, and
 * a cache kept past the request would need paths made portable between
 * servers and forgetting when a theme gains a copy -- the step every
 * WooCommerce support thread about "my override is ignored" turns out to
 * be.
 *
 * And deliberately plain PHP, not a template language compiled to PHP. A
 * theme developer copies a template and edits it, which needs nothing they
 * do not already know, and a compiled language would mean writing PHP to a
 * folder the web server can write to and then running it -- what every
 * WordPress security scanner is there to find.
 *
 * @since 1.0.0
 */
final class Loader {

	/**
	 * Keys a template's arguments may not have, because a variable of that
	 * name means something already: `$args` is the whole array, `$this`
	 * cannot be assigned, and the superglobals are the request. Names that
	 * begin with `__` are the loader's own as well (see variables()).
	 *
	 * @since 1.0.0
	 */
	private const array RESERVED = [ 'this', 'args', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', '_REQUEST', '_SESSION' ];

	/**
	 * How many templates deep one may load another.
	 *
	 * A template that loads itself, or two that load each other, is
	 * otherwise a hang that ends in a fatal error naming nothing useful.
	 * Far deeper than any real page nests -- a comment thread at
	 * WordPress's deepest setting is ten.
	 *
	 * @since 1.0.0
	 */
	public const int MAX_DEPTH = 64;

	/**
	 * The flags json() encodes with.
	 *
	 * `<`, `>`, `&` and both quotes as `\u003C`-style escapes, so a string
	 * holding `</script>` cannot close the block it is printed into, and the
	 * result can sit in a quoted attribute as it is.
	 *
	 * @since 1.0.0
	 */
	private const int JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

	/**
	 * How many templates are being loaded, one inside another, right now.
	 *
	 * @since 1.0.0
	 */
	private int $depth = 0;

	/**
	 * Set up a loader.
	 *
	 * @param string $prefix        Prefix for the hooks, such as `myplugin`.
	 * @param string $default_path  The plugin's templates folder.
	 * @param string $template_path The folder in a theme, such as `myplugin/`.
	 * @param string $version       The plugin's version, quoted in notices.
	 * @param bool   $debug         True to ignore copies in the theme, for
	 *                              telling whether a theme is the cause.
	 *
	 * @since 1.0.0
	 */
	public function __construct(
		private readonly string $prefix,
		private readonly string $default_path,
		private readonly string $template_path,
		private readonly string $version,
		private readonly bool $debug = false
	) {}

	/**
	 * The folder in a theme its copies go in, such as `myplugin/`.
	 *
	 * @return string With a trailing slash.
	 * @since 1.0.0
	 */
	public function template_path(): string {
		/**
		 * Filter the folder in a theme that overrides the plugin's templates.
		 *
		 * @param string $template_path Relative to the theme, such as `myplugin/`.
		 *
		 * @since 1.0.0
		 */
		$template_path = (string) apply_filters( $this->prefix . '_template_path', $this->template_path );

		return trailingslashit( $template_path );
	}

	/**
	 * The plugin's own templates folder.
	 *
	 * @return string With a trailing slash.
	 * @since 1.0.0
	 */
	public function default_path(): string {
		return trailingslashit( $this->default_path );
	}

	/**
	 * This loader's folders, in the shape Overrides::outdated() takes.
	 *
	 * @return array{default_path: string, template_path: string}
	 * @since 1.0.0
	 */
	public function sources(): array {
		return [
			'default_path'  => $this->default_path(),
			'template_path' => $this->template_path(),
		];
	}

	/**
	 * The template folders whose theme copies the outdated-copy report checks.
	 *
	 * This loader's own, under the name given, and any an add-on adds
	 * through `{prefix}_template_scan_paths` so that a theme's old copy of
	 * one of its templates is reported too.
	 *
	 * @param string $name What the report calls this loader's templates, such as the plugin's name.
	 *
	 * @return array<string, array{default_path: string, template_path: string}>
	 * @since 1.0.0
	 */
	public function scan_paths( string $name ): array {
		$paths = [ $name => $this->sources() ];

		/**
		 * Filter the template folders whose theme copies are checked for being out of date.
		 *
		 * An add-on with templates of its own adds its folder:
		 *
		 *     $paths['Affiliates'] = [
		 *         'default_path'  => __DIR__ . '/templates/',
		 *         'template_path' => 'myplugin/affiliates/',
		 *     ];
		 *
		 * @param array<string, array{default_path: string, template_path: string}> $paths
		 *        By the name the report gives them.
		 *
		 * @since 1.0.0
		 */
		return (array) apply_filters( $this->prefix . '_template_scan_paths', $paths );
	}

	/**
	 * Whether copies in the theme are being ignored.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function debugging(): bool {
		return $this->debug;
	}

	/**
	 * The file a template name resolves to.
	 *
	 * Each name in turn, the theme before the plugin, so an array is a
	 * list of preferences: the first name either has wins over every later
	 * one, wherever that is.
	 *
	 * @param string|array<int, string> $names         Template names, such as
	 *                                                 `cart/cart`, without `.php`.
	 * @param string                    $template_path The folder in the theme;
	 *                                                 '' for the plugin's.
	 * @param string                    $default_path  Where the shipped copies
	 *                                                 are; '' for the plugin's.
	 *
	 * @return string The file, or '' when there is none.
	 * @since 1.0.0
	 */
	public function locate( $names, string $template_path = '', string $default_path = '' ): string {
		$template_path = '' !== $template_path ? trailingslashit( $template_path ) : $this->template_path();
		$default_path  = '' !== $default_path ? trailingslashit( $default_path ) : $this->default_path();
		$located       = '';

		foreach ( (array) $names as $name ) {
			$file = $this->file( (string) $name );

			if ( '' === $file ) {
				continue;
			}

			// WordPress's own lookup: the child theme, then its parent.
			$located = $this->debug ? '' : locate_template( $template_path . $file );

			if ( '' === $located && is_file( $default_path . $file ) ) {
				$located = $default_path . $file;
			}

			if ( '' !== $located ) {
				break;
			}
		}

		/**
		 * Filter the file a template name resolved to.
		 *
		 * @param string                    $located       The file, or ''.
		 * @param string|array<int, string> $names         The name or names asked for.
		 * @param string                    $template_path The folder in the theme.
		 * @param string                    $default_path  The shipped templates' folder.
		 *
		 * @since 1.0.0
		 */
		$located = (string) apply_filters( $this->prefix . '_locate_template', $located, $names, $template_path, $default_path );

		return $located;
	}

	/**
	 * Whether a template name resolves to a file, in the theme or the plugin.
	 *
	 * For markup that is only drawn when somebody has supplied it -- a
	 * theme's own `cart/banner` -- without a notice when nobody has.
	 *
	 * @param string|array<int, string> $names         Template names, as locate() takes them.
	 * @param string                    $template_path The folder in the theme; '' for the plugin's.
	 * @param string                    $default_path  The shipped templates' folder; '' for the plugin's.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function exists( $names, string $template_path = '', string $default_path = '' ): bool {
		return '' !== $this->locate( $names, $template_path, $default_path );
	}

	/**
	 * Load a template, with its arguments as variables.
	 *
	 * Each key of `$args` is a variable in the template, and the whole
	 * array is `$args` as well, as WordPress's load_template() gives it. A
	 * key that is not a valid variable name, or is one of the names that
	 * mean something already, is left in `$args` only.
	 *
	 * @param string               $name          Template name, such as `cart/cart`.
	 * @param array<string, mixed> $args          What the template is given.
	 * @param string               $template_path The folder in the theme; '' for the plugin's.
	 * @param string               $default_path  The shipped templates' folder; '' for the plugin's.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function get( string $name, array $args = [], string $template_path = '', string $default_path = '' ): void {
		$located = $this->locate( $name, $template_path, $default_path );

		/**
		 * Filter the file about to be loaded for a template.
		 *
		 * For a plugin that draws one of these from a file of its own. A
		 * file that does not exist is a notice, and nothing is loaded.
		 *
		 * @param string               $located       The file found, or ''.
		 * @param string               $name          The template name.
		 * @param array<string, mixed> $args          What it is to be given.
		 * @param string               $template_path The folder in the theme, as asked.
		 * @param string               $default_path  The shipped templates' folder, as asked.
		 *
		 * @since 1.0.0
		 */
		$filtered = (string) apply_filters( $this->prefix . '_get_template', $located, $name, $args, $template_path, $default_path );

		if ( $filtered !== $located ) {
			if ( ! is_file( $filtered ) ) {
				$this->wrong( __METHOD__, sprintf( 'The template file %1$s given for %2$s does not exist.', $filtered, $name ) );

				return;
			}

			$located = $filtered;
		}

		if ( '' === $located ) {
			$this->wrong( __METHOD__, sprintf( 'There is no template named %s.', $name ) );

			return;
		}

		/**
		 * Filter what a template is given.
		 *
		 * @param array<string, mixed> $args    Its arguments.
		 * @param string               $name    The template name.
		 * @param string               $located The file about to be loaded.
		 *
		 * @since 1.0.0
		 */
		$args = (array) apply_filters( $this->prefix . '_template_args', $args, $name, $located );

		$variables = $this->variables( $args );

		/**
		 * Fires before a template is loaded.
		 *
		 * @param string               $name          The template name.
		 * @param string               $template_path The folder in the theme, as asked.
		 * @param string               $located       The file being loaded.
		 * @param array<string, mixed> $args          What it is given.
		 *
		 * @since 1.0.0
		 */
		if ( $this->depth >= self::MAX_DEPTH ) {
			$this->wrong( __METHOD__, sprintf( 'Templates are nested more than %1$d deep at %2$s; a template probably loads itself.', self::MAX_DEPTH, $name ) );

			return;
		}

		do_action( $this->prefix . '_before_template_part', $name, $template_path, $located, $args );

		++$this->depth;

		try {
			self::load( $located, $args, $variables );
		} finally {
			--$this->depth;
		}

		/**
		 * Fires after a template is loaded.
		 *
		 * @param string               $name          The template name.
		 * @param string               $template_path The folder in the theme, as asked.
		 * @param string               $located       The file that was loaded.
		 * @param array<string, mixed> $args          What it was given.
		 *
		 * @since 1.0.0
		 */
		do_action( $this->prefix . '_after_template_part', $name, $template_path, $located, $args );
	}

	/**
	 * A template's output, as a string.
	 *
	 * For a shortcode or a block, which return markup rather than print it.
	 *
	 * @param string               $name          Template name, such as `cart/cart`.
	 * @param array<string, mixed> $args          What the template is given.
	 * @param string               $template_path The folder in the theme; '' for the plugin's.
	 * @param string               $default_path  The shipped templates' folder; '' for the plugin's.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function html( string $name, array $args = [], string $template_path = '', string $default_path = '' ): string {
		$level = ob_get_level();

		ob_start();

		try {
			$this->get( $name, $args, $template_path, $default_path );
		} catch ( \Throwable $error ) {
			// Every buffer opened since, ours and any the template started
			// and never closed, so a failure in one does not leave the rest
			// of the page captured in a buffer nothing will ever print.
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}

			throw $error;
		}

		// A template that opened a buffer of its own and returned without
		// closing it: what it printed into that is part of its output too.
		$html = '';

		while ( ob_get_level() > $level ) {
			$html = (string) ob_get_clean() . $html;
		}

		return $html;
	}

	/**
	 * A value as JSON that is safe inside a `<script>` block or a quoted attribute.
	 *
	 * For a template handing data to its script. esc_html() and esc_attr()
	 * make a value safe as text and as an attribute in quotes, and nowhere
	 * else: in a script a backslash ends a string early. This escapes `<`,
	 * `>`, `&` and both quotes, so a value holding `</script>` cannot close
	 * the block, and `data-config="<?php echo Loader::json( $config ); ?>"`
	 * needs nothing more.
	 *
	 * @param mixed $value Anything wp_json_encode() takes.
	 *
	 * @return string The JSON, or `null` (with a notice) for a value that has none.
	 * @since 1.0.0
	 */
	public static function json( mixed $value ): string {
		$json = wp_json_encode( $value, self::JSON_FLAGS );

		if ( ! is_string( $json ) ) {
			_doing_it_wrong( __METHOD__, esc_html( 'The value has no JSON form; null was printed instead.' ), '' );

			return 'null';
		}

		return $json;
	}

	/**
	 * Load `{slug}-{name}`, or `{slug}` when there is no such template.
	 *
	 * The way to offer a variant -- `account/downloads-empty` -- that a
	 * theme or a plugin can supply without every caller knowing whether it
	 * exists.
	 *
	 * @param string               $slug          The general template, such as `account/downloads`.
	 * @param string               $name          The variant, or ''.
	 * @param array<string, mixed> $args          What the template is given.
	 * @param string               $template_path The folder in the theme; '' for the plugin's.
	 * @param string               $default_path  The shipped templates' folder; '' for the plugin's.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function part( string $slug, string $name = '', array $args = [], string $template_path = '', string $default_path = '' ): void {
		$chosen = $slug;

		if ( '' !== $name && '' !== $this->locate( $slug . '-' . $name, $template_path, $default_path ) ) {
			$chosen = $slug . '-' . $name;
		}

		$this->get( $chosen, $args, $template_path, $default_path );
	}

	/**
	 * The file name for a template name, or '' for one that is refused.
	 *
	 * A name is written in code, but it becomes part of a path, so a name
	 * that climbs out of the folders (`../`), starts at the root, or carries
	 * a backslash or a control character is refused with a notice rather
	 * than looked for. WooCommerce does not check; a bad refactor should
	 * not be able to turn a template name into a file read.
	 *
	 * @param string $name Template name, with or without `.php`.
	 *
	 * @return string Relative path ending in `.php`, or ''.
	 * @since 1.0.0
	 */
	private function file( string $name ): string {
		$refused = '' === trim( $name )
			|| str_starts_with( $name, '/' )
			|| 1 === preg_match( '/[\x00-\x1F\x7F\\\\:]/', $name )
			|| in_array( '..', explode( '/', $name ), true );

		if ( $refused ) {
			$this->wrong( __METHOD__, sprintf( 'The template name %s is not a path inside the templates folder.', $name ) );

			return '';
		}

		return str_ends_with( $name, '.php' ) ? $name : $name . '.php';
	}

	/**
	 * The arguments that can be variables.
	 *
	 * @param array<string, mixed> $args A template's arguments.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function variables( array $args ): array {
		$variables = [];

		foreach ( $args as $key => $value ) {
			$key = (string) $key;

			// `__` is the loader's: load() keeps the file it runs in one. An
			// argument of that shape is skipped there anyway; saying so
			// here means nobody is left wondering where their value went.
			if ( in_array( $key, self::RESERVED, true ) || str_starts_with( $key, '__' ) ) {
				$this->wrong( __METHOD__, sprintf( 'A template argument cannot be named %s; it is only in $args.', $key ) );

				continue;
			}

			if ( 1 === preg_match( '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $key ) ) {
				$variables[ $key ] = $value;
			}
		}

		return $variables;
	}

	/**
	 * Run the template file.
	 *
	 * Static, and in a scope of its own, so a template sees its arguments
	 * and nothing of the loader: no `$this`, and no variable of ours that
	 * an argument could collide with.
	 *
	 * @param string               $__file      The template file.
	 * @param array<string, mixed> $args        All the arguments.
	 * @param array<string, mixed> $__variables The ones that become variables.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function load( string $__file, array $args, array $__variables ): void {
		// variables() has already refused `args` and every `__` name, so
		// nothing here can replace the file being loaded. EXTR_SKIP all
		// the same: if that check is ever loosened, an argument named
		// after one of the three above still leaves it alone.
		extract( $__variables, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Keys checked in variables().

		unset( $__variables );

		include $__file;
	}

	/**
	 * Tell a developer they called this wrongly, the WordPress way.
	 *
	 * Not translated: it is read by whoever wrote the call, in a log or
	 * the debug bar, and a library has no text domain of the plugin's.
	 *
	 * @param string $method  What was called.
	 * @param string $message What was wrong.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function wrong( string $method, string $message ): void {
		_doing_it_wrong( esc_html( $method ), esc_html( $message ), esc_html( $this->version ) );
	}
}
