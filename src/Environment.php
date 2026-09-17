<?php
/**
 * The state one render carries.
 *
 * @package   SugarCommerce\Templates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Templates;

/**
 * Class Environment
 *
 * Reachable from a compiled template as `$__env`. It holds the sections a
 * template has filled in and the layout it asked to be wrapped in, which are
 * the two things a template cannot express as plain output.
 *
 * One per render rather than one per engine: two renders must not be able to
 * see each other's sections, and a template that throws must not leave a
 * half-open section behind for the next one.
 *
 * @since 1.0.0
 */
final class Environment {

	/**
	 * Captured sections, by name.
	 *
	 * @var array<string, string>
	 */
	private array $sections = array();

	/**
	 * Names of sections currently being captured, innermost last.
	 *
	 * @var array<int, string>
	 */
	private array $open = array();

	/**
	 * The layout this template asked for, if any.
	 */
	private ?string $layout = null;

	/**
	 * @param Engine $engine The engine, for resolving includes.
	 */
	public function __construct( private readonly Engine $engine ) {}

	/**
	 * Wrap this template in a layout.
	 *
	 * @since 1.0.0
	 *
	 * @param string $layout Template name.
	 *
	 * @return void
	 */
	public function extends( string $layout ): void {
		$this->layout = $layout;
	}

	/**
	 * The layout asked for, clearing it.
	 *
	 * Clearing is the point: the layout is about to be rendered, and if it
	 * extends something in turn that is the next answer. Leaving it set would
	 * re-enter the same layout for ever.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function take_layout(): ?string {
		$layout = $this->layout;

		$this->layout = null;

		return $layout;
	}

	/**
	 * Begin capturing a section.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Section name.
	 *
	 * @return void
	 */
	public function start( string $name ): void {
		$this->open[] = $name;

		ob_start();
	}

	/**
	 * Finish capturing the innermost section.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 *
	 * @throws \LogicException On @endsection with no open section.
	 */
	public function stop(): void {
		if ( ! $this->open ) {
			throw new \LogicException( '@endsection without a matching @section.' );
		}

		$name = array_pop( $this->open );

		// First writer wins, so a layout can define a fallback and a child
		// template's own @section still takes precedence over it.
		$captured = (string) ob_get_clean();

		$this->sections[ $name ] ??= $captured;
	}

	/**
	 * Set a section to a short value, without a block.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name  Section name.
	 * @param string $value Its content.
	 *
	 * @return void
	 */
	public function set( string $name, string $value ): void {
		$this->sections[ $name ] ??= $value;
	}

	/**
	 * The content of a section.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name    Section name.
	 * @param string $default Returned when nothing filled it in.
	 *
	 * @return string
	 */
	public function yield( string $name, string $default = '' ): string {
		return $this->sections[ $name ] ?? $default;
	}

	/**
	 * Render another template inline.
	 *
	 * It sees the including template's variables plus anything extra, which
	 * is what makes a partial worth extracting — it does not have to be
	 * handed every value it already had.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name  Template name.
	 * @param array  $scope The caller's variables.
	 * @param array  $extra Additional variables, winning over the scope.
	 *
	 * @return string
	 */
	public function include( string $name, array $scope = array(), array $extra = array() ): string {
		foreach ( array_keys( $scope ) as $key ) {
			if ( str_starts_with( (string) $key, '__' ) ) {
				unset( $scope[ $key ] );
			}
		}

		return $this->engine->render( $name, $extra + $scope );
	}

	/**
	 * Abandon any sections left open by a template that threw.
	 *
	 * @since 1.0.0
	 *
	 * @param int $depth Output buffering level to unwind to.
	 *
	 * @return void
	 */
	public function unwind( int $depth ): void {
		while ( ob_get_level() > $depth ) {
			ob_end_clean();
		}

		$this->open = array();
	}
}
