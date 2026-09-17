<?php
/**
 * Template source to PHP source.
 *
 * @package   SugarCommerce\Templates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Templates;

/**
 * Class Compiler
 *
 * Turns a template into plain PHP, which is then included like any other file.
 * There is no runtime interpreter: the cost of the syntax is paid once, at
 * compile time, and what actually executes is the same PHP you would have
 * written by hand.
 *
 * The passes run in a fixed order, and the order is the whole design:
 *
 *   1. Raw blocks — `@verbatim` and `@php` — are lifted out and replaced with
 *      placeholders, so nothing below touches their contents.
 *   2. Comments are removed.
 *   3. Raw echoes `{!! !!}` before escaped echoes `{{ }}`, or the inner braces
 *      of the raw form get matched as an escaped one.
 *   4. Directives.
 *   5. The raw blocks go back.
 *
 * @since 1.0.0
 */
final class Compiler {

	/**
	 * How deep we are in nested `@forelse` loops.
	 *
	 * Each needs its own "did the loop body run" flag, or an inner loop
	 * clobbers the outer one's and the wrong `@empty` branch fires.
	 */
	private int $forelse = 0;

	/**
	 * Raw blocks lifted out before compiling.
	 *
	 * @var array<int, string>
	 */
	private array $raw = array();

	/**
	 * Compile a template.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Template source.
	 * @param string $origin Path the source came from, for the header comment
	 *                       that makes a stack trace legible.
	 *
	 * @return string PHP source.
	 */
	public function compile( string $source, string $origin = '' ): string {
		$this->forelse = 0;
		$this->raw     = array();

		$source = $this->lift_raw( $source );
		$source = $this->comments( $source );
		$source = $this->echoes( $source );
		$source = $this->directives( $source );
		$source = $this->restore_raw( $source );

		$header = '' === $origin ? '' : '<?php /* Compiled from ' . $origin . ' — do not edit. */ ?>' . "\n";

		return $header . $source;
	}

	/**
	 * Lift `@verbatim` and `@php` blocks out of harm's way.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Template source.
	 *
	 * @return string
	 */
	private function lift_raw( string $source ): string {
		$source = (string) preg_replace_callback(
			'/@verbatim\s*(.*?)\s*@endverbatim/s',
			fn( array $m ): string => $this->stash( $m[1] ),
			$source
		);

		return (string) preg_replace_callback(
			'/@php\s*(.*?)\s*@endphp/s',
			fn( array $m ): string => $this->stash( '<?php ' . $m[1] . ' ?>' ),
			$source
		);
	}

	/**
	 * Park a chunk of output and return its placeholder.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content The chunk.
	 *
	 * @return string
	 */
	private function stash( string $content ): string {
		$this->raw[] = $content;

		return '@__raw_' . ( count( $this->raw ) - 1 ) . '__@';
	}

	/**
	 * Put the lifted blocks back.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Compiled source.
	 *
	 * @return string
	 */
	private function restore_raw( string $source ): string {
		return (string) preg_replace_callback(
			'/@__raw_(\d+)__@/',
			fn( array $m ): string => $this->raw[ (int) $m[1] ] ?? '',
			$source
		);
	}

	/**
	 * Strip `{{-- --}}` comments.
	 *
	 * They leave nothing behind, unlike an HTML comment, which is shipped to
	 * the browser and read by anyone who looks.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Template source.
	 *
	 * @return string
	 */
	private function comments( string $source ): string {
		return (string) preg_replace( '/\{\{--.*?--\}\}/s', '', $source );
	}

	/**
	 * Compile the echo forms.
	 *
	 * `{{ }}` escapes. That is the entire reason this engine exists: in a
	 * plain PHP template every single output has to remember its escaping
	 * call, and one miss is a cross-site scripting hole. Here the default is
	 * safe and opting out is visible.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Template source.
	 *
	 * @return string
	 */
	private function echoes( string $source ): string {
		// Raw first: its inner braces would otherwise match the escaped form.
		$source = (string) preg_replace(
			'/\{!!\s*(.+?)\s*!!\}/s',
			'<?= $1 ?>',
			$source
		);

		return (string) preg_replace(
			'/\{\{\s*(.+?)\s*\}\}/s',
			'<?= \\SugarCommerce\\Templates\\Engine::e( $1 ) ?>',
			$source
		);
	}

	/**
	 * Compile the directives.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Template source.
	 *
	 * @return string
	 */
	private function directives( string $source ): string {
		// `@@if` is how a template writes a literal `@if`. Parked before the
		// pass below so it cannot be read as a directive.
		$source = (string) preg_replace_callback(
			'/@@(\w+)/',
			fn( array $m ): string => $this->stash( '@' . $m[1] ),
			$source
		);

		return (string) preg_replace_callback(
			// @name, optionally followed by a balanced parenthesised
			// expression — balanced because @if ( count( $x ) > 0 ) has
			// parentheses of its own.
			//
			// Deliberately not anchored to a word boundary. `@endsection`
			// directly after text is legitimate, and requiring a boundary
			// silently failed to close the section. What keeps an email
			// address from compiling is the allow-list in directive(),
			// which hands anything it does not recognise straight back.
			'/@(\w+)(\s*\((?:[^()]++|(?2))*\))?/',
			fn( array $m ): string => $this->directive(
				$m[1],
				isset( $m[2] ) ? trim( substr( trim( $m[2] ), 1, -1 ) ) : null,
				$m[0]
			),
			$source
		);
	}

	/**
	 * Compile one directive.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $name       Directive name, without the @.
	 * @param string|null $expression Contents of its parentheses, or null
	 *                                when it had none.
	 * @param string      $matched    Exactly what was matched, handed back
	 *                                untouched when this is not a directive
	 *                                of ours — an email address is not one,
	 *                                and neither is a CSS `@media`, whose
	 *                                spacing a rebuild would quietly change.
	 *
	 * @return string
	 */
	private function directive( string $name, ?string $expression, string $matched = '' ): string {

		return match ( $name ) {
			'if'          => '<?php if ( ' . $expression . ' ) : ?>',
			'elseif'      => '<?php elseif ( ' . $expression . ' ) : ?>',
			'else'        => '<?php else : ?>',
			'endif'       => '<?php endif; ?>',
			'unless'      => '<?php if ( ! ( ' . $expression . ' ) ) : ?>',
			'endunless'   => '<?php endif; ?>',
			'isset'       => '<?php if ( isset( ' . $expression . ' ) ) : ?>',
			'endisset'    => '<?php endif; ?>',
			'foreach'     => '<?php foreach ( ' . $expression . ' ) : ?>',
			'endforeach'  => '<?php endforeach; ?>',
			'for'         => '<?php for ( ' . $expression . ' ) : ?>',
			'endfor'      => '<?php endfor; ?>',
			'while'       => '<?php while ( ' . $expression . ' ) : ?>',
			'endwhile'    => '<?php endwhile; ?>',
			'forelse'     => $this->forelse_open( (string) $expression ),
			'endforelse'  => '<?php endif; ?>',
			'continue'    => null === $expression ? '<?php continue; ?>' : '<?php if ( ' . $expression . ' ) { continue; } ?>',
			'break'       => null === $expression ? '<?php break; ?>' : '<?php if ( ' . $expression . ' ) { break; } ?>',
			'include'     => $this->include( (string) $expression ),
			'extends'     => '<?php $__env->extends( ' . $expression . ' ); ?>',
			'section'     => $this->section( (string) $expression ),
			'endsection'  => '<?php $__env->stop(); ?>',
			'yield'       => '<?php echo $__env->yield( ' . $expression . ' ); ?>',
			'empty'       => $this->empty( $expression ),
			'endempty'    => '<?php endif; ?>',
			default       => $matched,
		};
	}

	/**
	 * Compile `@include`.
	 *
	 * The partial sees the including template's variables, so it does not
	 * have to be handed every value it already had — which is most of what
	 * makes a partial worth extracting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $expression The name, and optionally extra variables.
	 *
	 * @return string
	 */
	private function include( string $expression ): string {
		[ $name, $extra ] = self::split( $expression );

		return '<?php echo $__env->include( ' . $name . ', get_defined_vars()'
			. ( null === $extra ? '' : ', ' . $extra ) . ' ); ?>';
	}

	/**
	 * Split an argument list at its first top-level comma.
	 *
	 * Not explode(): the second argument is usually an array literal with
	 * commas of its own, and quoted strings may contain anything at all.
	 *
	 * @since 1.0.0
	 *
	 * @param string $expression The argument list.
	 *
	 * @return array{0: string, 1: string|null} First argument, then the rest.
	 */
	private static function split( string $expression ): array {
		$depth = 0;
		$quote = '';
		$length = strlen( $expression );

		for ( $i = 0; $i < $length; $i++ ) {
			$character = $expression[ $i ];

			if ( '' !== $quote ) {
				// A backslash escapes the next character, including a quote.
				if ( '\\' === $character ) {
					++$i;
				} elseif ( $character === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( "'" === $character || '"' === $character ) {
				$quote = $character;
				continue;
			}

			if ( str_contains( '([{', $character ) ) {
				++$depth;
			} elseif ( str_contains( ')]}', $character ) ) {
				--$depth;
			} elseif ( ',' === $character && 0 === $depth ) {
				return array( trim( substr( $expression, 0, $i ) ), trim( substr( $expression, $i + 1 ) ) );
			}
		}

		return array( trim( $expression ), null );
	}

	/**
	 * Open a `@forelse` loop.
	 *
	 * @since 1.0.0
	 *
	 * @param string $expression The `$items as $item` part.
	 *
	 * @return string
	 */
	private function forelse_open( string $expression ): string {
		++$this->forelse;

		$flag = '$__empty_' . $this->forelse;

		return '<?php ' . $flag . ' = true; foreach ( ' . $expression . ' ) : ' . $flag . ' = false; ?>';
	}

	/**
	 * Compile `@empty`, which means two different things.
	 *
	 * With an expression it is a plain emptiness test. Bare, it is the
	 * "nothing to loop over" arm of the enclosing `@forelse`.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $expression Contents of its parentheses, or null.
	 *
	 * @return string
	 */
	private function empty( ?string $expression ): string {
		if ( null !== $expression ) {
			return '<?php if ( empty( ' . $expression . ' ) ) : ?>';
		}

		$flag = '$__empty_' . max( 1, $this->forelse );

		--$this->forelse;

		return '<?php endforeach; if ( ' . $flag . ' ) : ?>';
	}

	/**
	 * Compile `@section`, in both of its forms.
	 *
	 * @since 1.0.0
	 *
	 * @param string $expression One argument to open a block, two to set a
	 *                           short value inline.
	 *
	 * @return string
	 */
	private function section( string $expression ): string {
		return str_contains( $expression, ',' )
			? '<?php $__env->set( ' . $expression . ' ); ?>'
			: '<?php $__env->start( ' . $expression . ' ); ?>';
	}
}
