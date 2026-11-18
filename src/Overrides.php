<?php
/**
 * Which of a theme's template copies are older than the plugin's.
 *
 * @package   SugarCommerce\Templates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Templates;

/**
 * WooCommerce's outdated-override report, for any set of template folders.
 *
 * Every template carries `@version` in its docblock, raised when its
 * markup changes in a way a copy should follow -- a new element the
 * script looks for, a renamed class. A theme's copy keeps the number it
 * was copied at, so a copy whose number is lower than the plugin's is one
 * that has missed a change, and is the first thing to look at when a page
 * stops working after an update. A copy with no number at all counts as
 * out of date: nobody can say what it was copied from.
 *
 * Like the loader, it names no plugin: it is given the folders to scan.
 *
 * @since 1.0.0
 */
final class Overrides {

	/**
	 * The theme copies of a set of templates that are out of date.
	 *
	 * @param array<string, array{default_path: string, template_path: string}> $sources
	 *        By a name for whose templates they are: where the shipped copies
	 *        are and the folder a theme puts its own in.
	 *
	 * @return array<int, array{source: string, name: string, file: string, version: string, theme_version: string}>
	 *         `file` is the theme's copy, `version` the plugin's and
	 *         `theme_version` the copy's ('' when it has none).
	 * @since 1.0.0
	 */
	public static function outdated( array $sources ): array {
		$outdated = [];

		foreach ( $sources as $source => $paths ) {
			$default_path  = trailingslashit( (string) ( $paths['default_path'] ?? '' ) );
			$template_path = trailingslashit( (string) ( $paths['template_path'] ?? '' ) );

			foreach ( self::files( $default_path ) as $name ) {
				// Where the loader would find it: the child theme, then its
				// parent. A copy in the parent that the child replaces is
				// never loaded, so it is not the one that matters.
				$copy = locate_template( $template_path . $name );

				if ( '' === $copy ) {
					continue;
				}

				$version       = self::version( $default_path . $name );
				$theme_version = self::version( $copy );

				if ( '' !== $theme_version && version_compare( $theme_version, $version, '>=' ) ) {
					continue;
				}

				$outdated[] = [
					'source'        => (string) $source,
					'name'          => $name,
					'file'          => $copy,
					'version'       => $version,
					'theme_version' => $theme_version,
				];
			}
		}

		return $outdated;
	}

	/**
	 * Every template in a folder, as names relative to it.
	 *
	 * @param string $directory The folder.
	 *
	 * @return array<int, string> Such as `cart/cart.php`, sorted.
	 * @since 1.0.0
	 */
	public static function files( string $directory ): array {
		if ( '' === $directory || ! is_dir( $directory ) ) {
			return [];
		}

		$directory = trailingslashit( wp_normalize_path( $directory ) );
		$files     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
		$names     = [];

		foreach ( $files as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$names[] = substr( wp_normalize_path( $file->getPathname() ), strlen( $directory ) );
			}
		}

		// Sorted, so the report reads the same on every machine: a
		// directory listing is in whatever order the filesystem keeps.
		sort( $names );

		return $names;
	}

	/**
	 * The `@version` a template's docblock gives.
	 *
	 * Read by hand rather than with get_file_data(), which only finds a
	 * header followed by a colon (`Version: 1.0`); a docblock tag has none.
	 * Only the start of the file is read, where the docblock is.
	 *
	 * @param string $file The template.
	 *
	 * @return string The version, or ''.
	 * @since 1.0.0
	 */
	public static function version( string $file ): string {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local template file, not a URL.
		$head = (string) file_get_contents( $file, false, null, 0, 8192 );

		// A file saved on an old Mac ends its lines with a lone CR.
		$head = str_replace( "\r", "\n", $head );

		if ( 1 !== preg_match( '/^[ \t\/*#@]*@version[ \t]+(\S+)/mi', $head, $match ) ) {
			return '';
		}

		return trim( $match[1] );
	}
}
