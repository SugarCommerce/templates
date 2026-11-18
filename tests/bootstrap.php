<?php
/**
 * PHPUnit bootstrap.
 *
 * This is a library, not a plugin: there is no WordPress to load. The
 * functions the loader calls are stubbed here, as close to core's
 * behaviour as the tests depend on: hooks that really run their callbacks,
 * and a locate_template() that looks in the child theme and then the
 * parent, which each test points at folders of its own.
 *
 * @package SugarCommerce\Templates
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$GLOBALS['tl_hooks']  = [];
$GLOBALS['tl_wrong']  = [];
$GLOBALS['tl_themes'] = [
	'child'  => '',
	'parent' => '',
	'root'   => '',
];

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['tl_hooks'][ $hook ][] = [ $callback, (int) $accepted_args ];

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $callback ) {
		foreach ( $GLOBALS['tl_hooks'][ $hook ] ?? [] as $i => [ $registered ] ) {
			if ( $registered === $callback ) {
				unset( $GLOBALS['tl_hooks'][ $hook ][ $i ] );

				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['tl_hooks'][ $hook ] ?? [] as [ $callback, $accepted ] ) {
			$value = $callback( ...array_slice( array_merge( [ $value ], $args ), 0, $accepted ) );
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['tl_hooks'][ $hook ] ?? [] as [ $callback, $accepted ] ) {
			$callback( ...array_slice( $args, 0, $accepted ) );
		}
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );

		return (string) preg_replace( '|(?<=.)/+|', '/', $path );
	}
}

if ( ! function_exists( 'locate_template' ) ) {
	/**
	 * Core's lookup: the child theme's folder, then the parent's.
	 */
	function locate_template( $names ) {
		foreach ( (array) $names as $name ) {
			if ( ! $name ) {
				continue;
			}

			foreach ( [ 'child', 'parent' ] as $theme ) {
				$folder = $GLOBALS['tl_themes'][ $theme ];

				if ( '' !== $folder && file_exists( $folder . '/' . $name ) ) {
					return $folder . '/' . $name;
				}
			}
		}

		return '';
	}
}

if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root() {
		return $GLOBALS['tl_themes']['root'];
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $method, $message, $version ) {
		$GLOBALS['tl_wrong'][] = [ $method, $message, $version ];
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Core's, less its retry with the strings made valid UTF-8.
	 */
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}
