<?php
/**
 * What Site Health says about a theme's copies of a plugin's templates.
 *
 * @package   SugarCommerce\Templates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Templates;

use InvalidArgumentException;

/**
 * One Site Health check: are the theme's template copies up to date?
 *
 * A theme's copy of a template keeps the markup it was copied with. When
 * the plugin's copy changes -- an element the script now looks for, a new
 * message -- the theme's goes on drawing the old one, and the symptom is
 * a button that does nothing, nowhere near the cause. Each template's
 * `@version` says when its markup last changed; this lists every copy in
 * the theme whose number is lower, as WooCommerce's status report does.
 *
 * One instance per plugin, each with a test of its own:
 *
 *     SiteHealth::register( [
 *         'id'             => 'myplugin_templates',
 *         'label'          => 'My Plugin',
 *         'sources'        => $loader,
 *         'debug_constant' => 'MYPLUGIN_TEMPLATE_DEBUG_MODE',
 *         'folder'         => 'wp-content/plugins/my-plugin/templates/',
 *     ] );
 *
 * The wording is the library's, with the plugin's name in it; a plugin
 * that wants its own words (or its own translations) passes `strings`.
 *
 * @since 1.0.0
 */
final class SiteHealth {

	/**
	 * The test's id, its name in WordPress's list and in the result.
	 *
	 * @since 1.0.0
	 */
	private string $id;

	/**
	 * The plugin's name, for the badge and the default wording.
	 *
	 * @since 1.0.0
	 */
	private string $label;

	/**
	 * Where the folders to check come from.
	 *
	 * @var Loader|callable|array<string, array{default_path: string, template_path: string}>
	 * @since 1.0.0
	 */
	private mixed $sources;

	/**
	 * The constant that makes the loader ignore the theme, or ''.
	 *
	 * @since 1.0.0
	 */
	private string $debug_constant;

	/**
	 * Where the plugin's own templates are, as a reader would find them, or ''.
	 *
	 * @since 1.0.0
	 */
	private string $folder;

	/**
	 * The badge's colour.
	 *
	 * @since 1.0.0
	 */
	private string $color;

	/**
	 * Wording given in place of the library's.
	 *
	 * @var array<string, string|callable>
	 * @since 1.0.0
	 */
	private array $strings;

	/**
	 * Set up the check and add it to Site Health.
	 *
	 * @param array{
	 *     id: string,
	 *     label: string,
	 *     sources: Loader|callable|array,
	 *     debug_constant?: string,
	 *     folder?: string,
	 *     badge_color?: string,
	 *     strings?: array<string, string|callable>,
	 * } $args See the constructor.
	 *
	 * @return self
	 * @throws InvalidArgumentException When the id, the label or the sources are missing.
	 * @since 1.0.0
	 */
	public static function register( array $args ): self {
		$check = new self( $args );

		add_filter( 'site_status_tests', [ $check, 'tests' ] );

		return $check;
	}

	/**
	 * Set up the check, without adding it anywhere.
	 *
	 * @param array{
	 *     id: string,
	 *     label: string,
	 *     sources: Loader|callable|array,
	 *     debug_constant?: string,
	 *     folder?: string,
	 *     badge_color?: string,
	 *     strings?: array<string, string|callable>,
	 * } $args {
	 *     @type string                 $id             The test's id, such as `myplugin_templates`.
	 *     @type string                 $label          The plugin's name: the badge, and the
	 *                                                  name in the default wording.
	 *     @type Loader|callable|array  $sources        The folders to check: a Loader (its
	 *                                                  scan_paths(), filter included), a
	 *                                                  callable returning them, or the array
	 *                                                  Overrides::outdated() takes.
	 *     @type string                 $debug_constant The constant that makes the loader ignore
	 *                                                  the theme, named in the advice. Optional.
	 *     @type string                 $folder         Where the plugin's own templates are, such
	 *                                                  as `wp-content/plugins/my-plugin/templates/`,
	 *                                                  named in the advice. Optional.
	 *     @type string                 $badge_color    The badge's colour. Default `blue`.
	 *     @type array                  $strings        Wording in place of the library's, by key:
	 *                                                  `good_label`, `good_description`,
	 *                                                  `outdated_label` (a callable given the
	 *                                                  count), `outdated_description`, `row`
	 *                                                  (`%1$s` the copy's version, `%2$s` the
	 *                                                  plugin's), `none` and `debug` (`%s` the
	 *                                                  constant). Plain text; it is escaped.
	 * }
	 *
	 * @throws InvalidArgumentException When the id, the label or the sources are missing.
	 * @since 1.0.0
	 */
	public function __construct( array $args ) {
		$this->id             = (string) ( $args['id'] ?? '' );
		$this->label          = (string) ( $args['label'] ?? '' );
		$this->sources        = $args['sources'] ?? null;
		$this->debug_constant = (string) ( $args['debug_constant'] ?? '' );
		$this->folder         = (string) ( $args['folder'] ?? '' );
		$this->color          = (string) ( $args['badge_color'] ?? 'blue' );
		$this->strings        = (array) ( $args['strings'] ?? [] );

		if ( '' === $this->id || '' === $this->label || null === $this->sources ) {
			throw new InvalidArgumentException( 'A template Site Health check needs an id, a label and its sources.' );
		}
	}

	/**
	 * Add the check to WordPress's list.
	 *
	 * @param array<string, array<string, mixed>> $tests The tests.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function tests( $tests ): array {
		$tests = (array) $tests;

		$tests['direct'][ $this->id ] = [
			'label' => $this->label,
			'test'  => [ $this, 'check' ],
		];

		return $tests;
	}

	/**
	 * Whether any copy in the theme is older than the plugin's.
	 *
	 * @return array<string, mixed> A Site Health result.
	 * @since 1.0.0
	 */
	public function check(): array {
		$outdated = Overrides::outdated( $this->paths() );

		if ( [] === $outdated ) {
			return $this->result(
				'good',
				$this->text( 'good_label' ),
				'<p>' . esc_html( $this->text( 'good_description' ) ) . '</p>'
			);
		}

		$rows = '';

		foreach ( $outdated as $copy ) {
			$rows .= '<li><code>' . esc_html( self::relative( $copy['file'] ) ) . '</code> '
				. esc_html(
					sprintf(
						$this->text( 'row' ),
						'' !== $copy['theme_version'] ? $copy['theme_version'] : $this->text( 'none' ),
						$copy['version']
					)
				)
				. '</li>';
		}

		$description = '<p>' . esc_html( $this->text( 'outdated_description' ) ) . '</p>'
			. '<ul>' . $rows . '</ul>';

		if ( '' !== $this->debug_constant ) {
			$description .= '<p>' . sprintf( esc_html( $this->text( 'debug' ) ), '<code>' . esc_html( $this->debug_constant ) . '</code>' ) . '</p>';
		}

		return $this->result( 'recommended', $this->outdated_label( count( $outdated ) ), $description );
	}

	/**
	 * The folders to check, from whichever form they were given in.
	 *
	 * @return array<string, array{default_path: string, template_path: string}>
	 * @since 1.0.0
	 */
	private function paths(): array {
		if ( $this->sources instanceof Loader ) {
			return $this->sources->scan_paths( $this->label );
		}

		if ( is_callable( $this->sources ) ) {
			return (array) call_user_func( $this->sources );
		}

		return (array) $this->sources;
	}

	/**
	 * The headline when copies are out of date.
	 *
	 * @param int $count How many.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function outdated_label( int $count ): string {
		if ( isset( $this->strings['outdated_label'] ) && is_callable( $this->strings['outdated_label'] ) ) {
			return (string) call_user_func( $this->strings['outdated_label'], $count );
		}

		return sprintf(
			/* translators: 1: how many templates; 2: the plugin's name. */
			_n( 'Your theme overrides %1$d %2$s template with an older copy', 'Your theme overrides %1$d %2$s templates with older copies', $count, 'sugarcommerce' ),
			$count,
			$this->label
		);
	}

	/**
	 * A piece of wording: the plugin's, or the library's with the plugin's name in it.
	 *
	 * @param string $key Which.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function text( string $key ): string {
		if ( isset( $this->strings[ $key ] ) && is_string( $this->strings[ $key ] ) ) {
			return $this->strings[ $key ];
		}

		return match ( $key ) {
			/* translators: %s: the plugin's name. */
			'good_label'           => sprintf( __( 'Your theme’s %s templates are up to date', 'sugarcommerce' ), $this->label ),
			/* translators: %s: the plugin's name. */
			'good_description'     => sprintf( __( 'Any %s template your theme overrides is the same version as the plugin’s.', 'sugarcommerce' ), $this->label ),
			'outdated_description' => $this->outdated_description(),
			/* translators: 1: the theme copy's version, or "(none)"; 2: the plugin's version of the template. */
			'row'                  => __( 'is version %1$s; the plugin’s is %2$s.', 'sugarcommerce' ),
			'none'                 => __( '(none)', 'sugarcommerce' ),
			/* translators: %s: the PHP constant. */
			'debug'                => __( 'To see whether a problem comes from these copies, define %s as true in wp-config.php: the plugin then ignores every copy in the theme until it is removed.', 'sugarcommerce' ),
			default                => '',
		};
	}

	/**
	 * The library's explanation of what an old copy means and what to do.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function outdated_description(): string {
		$what = sprintf(
			/* translators: %s: the plugin's name. */
			__( 'Your theme has its own copies of these %s templates, and they are older than the ones in the plugin. They still work, but they miss whatever has changed since they were copied, which can leave part of a page not working.', 'sugarcommerce' ),
			$this->label
		);

		$how = '' !== $this->folder
			? sprintf(
				/* translators: %s: the folder the plugin's templates are in. */
				__( 'Compare each copy with the plugin’s file in %s and bring the changes across, then raise the @version line to match.', 'sugarcommerce' ),
				$this->folder
			)
			: __( 'Compare each copy with the plugin’s own and bring the changes across, then raise the @version line to match.', 'sugarcommerce' );

		return $what . ' ' . $how;
	}

	/**
	 * A file's path from the themes folder, which is all anybody needs to find it.
	 *
	 * @param string $file The full path.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private static function relative( string $file ): string {
		$themes = trailingslashit( wp_normalize_path( get_theme_root() ) );
		$file   = wp_normalize_path( $file );

		return str_starts_with( $file, $themes ) ? substr( $file, strlen( $themes ) ) : $file;
	}

	/**
	 * A result in the shape Site Health wants.
	 *
	 * @param string $status      good or recommended.
	 * @param string $label       The headline.
	 * @param string $description The explanation, as markup.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function result( string $status, string $label, string $description ): array {
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => $this->label,
				'color' => $this->color,
			],
			'description' => $description,
			'actions'     => '',
			'test'        => $this->id,
		];
	}
}
