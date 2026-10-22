<?php
/**
 * Compiling and rendering.
 *
 * @package   SugarCommerce\Templates
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Templates\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Templates\Engine;

final class EngineTest extends TestCase {

	private string $root;
	private string $cache;

	protected function setUp(): void {
		$this->root  = sys_get_temp_dir() . '/templates-' . bin2hex( random_bytes( 6 ) );
		$this->cache = $this->root . '/cache';

		mkdir( $this->root, 0o775, true );
	}

	protected function tearDown(): void {
		$this->remove( $this->root );
	}

	/**
	 * Delete a directory tree.
	 */
	private function remove( string $path ): void {
		if ( ! is_dir( $path ) ) {
			@unlink( $path );

			return;
		}

		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove( $path . '/' . $entry );
			}
		}

		@rmdir( $path );
	}

	/**
	 * Write a template and return the engine.
	 */
	private function write( string $name, string $source ): Engine {
		$path = $this->root . '/' . str_replace( '.', '/', $name ) . '.blade.php';

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0o775, true );
		}

		file_put_contents( $path, $source );

		return new Engine( $this->root, $this->cache );
	}

	/**
	 * Render one template, collapsing whitespace so cases read cleanly.
	 */
	private function render( string $name, array $data = array() ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', ( new Engine( $this->root, $this->cache ) )->render( $name, $data ) ) );
	}

	/* ─── Escaping ──────────────────────────────────────────────────── */

	public function test_double_braces_escape(): void {
		$this->write( 'page', '<p>{{ $name }}</p>' );

		$this->assertSame( '<p>&lt;script&gt;</p>', $this->render( 'page', array( 'name' => '<script>' ) ) );
	}

	public function test_escaping_covers_quotes(): void {
		// An unescaped quote breaks out of an attribute, which is the common
		// way an escaper that only handles < and > still lets XSS through.
		$this->write( 'page', '<a title="{{ $t }}">x</a>' );

		$this->assertStringNotContainsString( '"onload', $this->render( 'page', array( 't' => '"onload=alert(1)' ) ) );
	}

	public function test_raw_echo_opts_out(): void {
		$this->write( 'page', '<p>{!! $html !!}</p>' );

		$this->assertSame( '<p><b>bold</b></p>', $this->render( 'page', array( 'html' => '<b>bold</b>' ) ) );
	}

	public function test_raw_and_escaped_can_share_a_template(): void {
		$this->write( 'page', '{!! $a !!}|{{ $b }}' );

		$this->assertSame( '<b>x</b>|&lt;b&gt;y&lt;/b&gt;', $this->render( 'page', array( 'a' => '<b>x</b>', 'b' => '<b>y</b>' ) ) );
	}

	public function test_expressions_are_allowed_inside_braces(): void {
		$this->write( 'page', '{{ strtoupper( $name ) }} {{ $n + 1 }}' );

		$this->assertSame( 'ADA 3', $this->render( 'page', array( 'name' => 'Ada', 'n' => 2 ) ) );
	}

	public function test_null_prints_nothing(): void {
		$this->write( 'page', '[{{ $missing }}]' );

		$this->assertSame( '[]', $this->render( 'page', array( 'missing' => null ) ) );
	}

	public function test_printing_an_array_is_an_error_not_the_word_array(): void {
		$this->write( 'page', '{{ $rows }}' );

		$this->expectException( \InvalidArgumentException::class );

		$this->render( 'page', array( 'rows' => array( 1, 2 ) ) );
	}

	public function test_a_stringable_object_prints(): void {
		$this->write( 'page', '{{ $thing }}' );

		$thing = new class() implements \Stringable {
			public function __toString(): string {
				return 'ok';
			}
		};

		$this->assertSame( 'ok', $this->render( 'page', array( 'thing' => $thing ) ) );
	}

	/* ─── Comments ──────────────────────────────────────────────────── */

	public function test_comments_leave_nothing_behind(): void {
		// Unlike an HTML comment, which ships to the browser.
		$this->write( 'page', 'a{{-- a note for us --}}b' );

		$this->assertSame( 'ab', $this->render( 'page' ) );
	}

	public function test_a_multi_line_comment_is_removed(): void {
		$this->write( 'page', "a{{--\nnote\n--}}b" );

		$this->assertSame( 'ab', $this->render( 'page' ) );
	}

	/* ─── Conditionals ──────────────────────────────────────────────── */

	public function test_if_else(): void {
		$this->write( 'page', '@if ( $on ) yes @else no @endif' );

		$this->assertSame( 'yes', $this->render( 'page', array( 'on' => true ) ) );
		$this->assertSame( 'no', $this->render( 'page', array( 'on' => false ) ) );
	}

	public function test_elseif(): void {
		$this->write( 'page', '@if ( 1 === $n ) one @elseif ( 2 === $n ) two @else many @endif' );

		$this->assertSame( 'two', $this->render( 'page', array( 'n' => 2 ) ) );
		$this->assertSame( 'many', $this->render( 'page', array( 'n' => 9 ) ) );
	}

	public function test_a_condition_may_contain_its_own_parentheses(): void {
		$this->write( 'page', '@if ( count( $rows ) > 1 ) many @endif' );

		$this->assertSame( 'many', $this->render( 'page', array( 'rows' => array( 1, 2 ) ) ) );
	}

	public function test_unless(): void {
		$this->write( 'page', '@unless ( $on ) off @endunless' );

		$this->assertSame( 'off', $this->render( 'page', array( 'on' => false ) ) );
		$this->assertSame( '', $this->render( 'page', array( 'on' => true ) ) );
	}

	public function test_isset(): void {
		$this->write( 'page', '@isset ( $thing ) here @endisset' );

		$this->assertSame( 'here', $this->render( 'page', array( 'thing' => 1 ) ) );
		$this->assertSame( '', $this->render( 'page', array( 'thing' => null ) ) );
	}

	public function test_empty_with_an_expression(): void {
		$this->write( 'page', '@empty ( $rows ) none @endempty' );

		$this->assertSame( 'none', $this->render( 'page', array( 'rows' => array() ) ) );
		$this->assertSame( '', $this->render( 'page', array( 'rows' => array( 1 ) ) ) );
	}

	/* ─── Loops ─────────────────────────────────────────────────────── */

	public function test_foreach(): void {
		$this->write( 'page', '@foreach ( $rows as $r ){{ $r }}@endforeach' );

		$this->assertSame( 'abc', $this->render( 'page', array( 'rows' => array( 'a', 'b', 'c' ) ) ) );
	}

	public function test_foreach_with_keys(): void {
		$this->write( 'page', '@foreach ( $rows as $k => $v ){{ $k }}={{ $v }} @endforeach' );

		$this->assertSame( 'a=1 b=2', $this->render( 'page', array( 'rows' => array( 'a' => 1, 'b' => 2 ) ) ) );
	}

	public function test_forelse_falls_through_when_there_is_nothing(): void {
		$this->write( 'page', '@forelse ( $rows as $r ){{ $r }}@empty nothing @endforelse' );

		$this->assertSame( 'ab', $this->render( 'page', array( 'rows' => array( 'a', 'b' ) ) ) );
		$this->assertSame( 'nothing', $this->render( 'page', array( 'rows' => array() ) ) );
	}

	public function test_nested_forelse_keep_their_own_flags(): void {
		// One shared flag would make the inner loop decide the outer arm.
		$this->write(
			'page',
			'@forelse ( $outer as $o )[@forelse ( $o as $i ){{ $i }}@empty-@endforelse]@empty none @endforelse'
		);

		$this->assertSame( '[ab][-]', $this->render( 'page', array( 'outer' => array( array( 'a', 'b' ), array() ) ) ) );
		$this->assertSame( 'none', $this->render( 'page', array( 'outer' => array() ) ) );
	}

	public function test_for_and_while(): void {
		$this->write( 'page', '@for ( $i = 0; $i < 3; $i++ ){{ $i }}@endfor' );
		$this->assertSame( '012', $this->render( 'page' ) );

		$this->write( 'other', '@while ( $n > 0 ){{ $n }}@php $n--; @endphp @endwhile' );
		$this->assertSame( '3 2 1', $this->render( 'other', array( 'n' => 3 ) ) );
	}

	public function test_continue_and_break(): void {
		$this->write( 'page', '@foreach ( $rows as $r )@continue ( 2 === $r ){{ $r }}@endforeach' );
		$this->assertSame( '134', $this->render( 'page', array( 'rows' => array( 1, 2, 3, 4 ) ) ) );

		$this->write( 'other', '@foreach ( $rows as $r )@break ( 3 === $r ){{ $r }}@endforeach' );
		$this->assertSame( '12', $this->render( 'other', array( 'rows' => array( 1, 2, 3, 4 ) ) ) );
	}

	/* ─── Raw blocks ────────────────────────────────────────────────── */

	public function test_verbatim_is_left_alone(): void {
		// For markup handed to a client-side framework that uses the same
		// braces, which would otherwise be compiled away.
		$this->write( 'page', '@verbatim{{ notMine }}@endverbatim' );

		$this->assertSame( '{{ notMine }}', $this->render( 'page' ) );
	}

	public function test_php_blocks_run(): void {
		$this->write( 'page', '@php $x = 2 * 21; @endphp{{ $x }}' );

		$this->assertSame( '42', $this->render( 'page' ) );
	}

	public function test_an_email_address_is_not_a_directive(): void {
		$this->write( 'page', '<a href="mailto:hi@example.com">hi@example.com</a>' );

		$this->assertStringContainsString( 'hi@example.com', $this->render( 'page' ) );
	}

	public function test_a_css_at_rule_survives(): void {
		$this->write( 'page', '<style>@media (min-width:40rem){.x{color:red}}</style>' );

		$this->assertStringContainsString( '@media', $this->render( 'page' ) );
	}

	/* ─── Layouts and sections ──────────────────────────────────────── */

	public function test_extends_and_yield(): void {
		$this->write( 'layouts.main', '<html>@yield( "body" )</html>' );
		$this->write( 'page', '@extends( "layouts.main" )@section( "body" )hello@endsection' );

		$this->assertSame( '<html>hello</html>', $this->render( 'page' ) );
	}

	public function test_output_outside_a_section_is_discarded_when_extending(): void {
		$this->write( 'layouts.main', '<html>@yield( "body" )</html>' );
		$this->write( 'page', '@extends( "layouts.main" )stray@section( "body" )hello@endsection' );

		$this->assertStringNotContainsString( 'stray', $this->render( 'page' ) );
	}

	public function test_yield_falls_back_to_a_default(): void {
		$this->write( 'layouts.main', '<title>@yield( "title", "Untitled" )</title>' );
		$this->write( 'page', '@extends( "layouts.main" )' );

		$this->assertSame( '<title>Untitled</title>', $this->render( 'page' ) );
	}

	public function test_a_section_can_be_set_inline(): void {
		$this->write( 'layouts.main', '<title>@yield( "title" )</title>' );
		$this->write( 'page', '@extends( "layouts.main" )@section( "title", "Orders" )' );

		$this->assertSame( '<title>Orders</title>', $this->render( 'page' ) );
	}

	public function test_layouts_nest(): void {
		$this->write( 'layouts.base', '<html>@yield( "shell" )</html>' );
		$this->write( 'layouts.admin', '@extends( "layouts.base" )@section( "shell" )<nav></nav>@yield( "body" )@endsection' );
		$this->write( 'page', '@extends( "layouts.admin" )@section( "body" )hi@endsection' );

		$this->assertSame( '<html><nav></nav>hi</html>', $this->render( 'page' ) );
	}

	public function test_a_layout_loop_is_an_error_rather_than_a_hang(): void {
		$this->write( 'loop', '@extends( "loop" )' );

		$this->expectException( \RuntimeException::class );

		$this->render( 'loop' );
	}

	public function test_a_template_that_throws_does_not_leak_its_buffers(): void {
		$this->write( 'bad', '@section( "x" )@php throw new \RuntimeException( "boom" ); @endphp@endsection' );
		$this->write( 'good', 'fine' );

		$level = ob_get_level();

		try {
			$this->render( 'bad' );
		} catch ( \RuntimeException ) {
			// Expected.
		}

		$this->assertSame( $level, ob_get_level() );
		$this->assertSame( 'fine', $this->render( 'good' ) );
	}

	/* ─── Includes ──────────────────────────────────────────────────── */

	public function test_include_sees_the_including_scope(): void {
		$this->write( 'partials.row', '<td>{{ $name }}</td>' );
		$this->write( 'page', '@include( "partials.row" )' );

		$this->assertSame( '<td>Ada</td>', $this->render( 'page', array( 'name' => 'Ada' ) ) );
	}

	public function test_include_takes_extra_variables(): void {
		$this->write( 'partials.row', '<td>{{ $label }}</td>' );
		$this->write( 'page', '@include( "partials.row", array( "label" => "Total" ) )' );

		$this->assertSame( '<td>Total</td>', $this->render( 'page' ) );
	}

	public function test_extra_variables_win_over_the_scope(): void {
		$this->write( 'partials.row', '<td>{{ $name }}</td>' );
		$this->write( 'page', '@include( "partials.row", array( "name" => "override" ) )' );

		$this->assertSame( '<td>override</td>', $this->render( 'page', array( 'name' => 'Ada' ) ) );
	}

	public function test_an_include_inside_a_loop_sees_the_loop_variable(): void {
		$this->write( 'partials.row', '[{{ $r }}]' );
		$this->write( 'page', '@foreach ( $rows as $r )@include( "partials.row" )@endforeach' );

		$this->assertSame( '[a][b]', $this->render( 'page', array( 'rows' => array( 'a', 'b' ) ) ) );
	}

	public function test_an_include_does_not_inherit_double_underscore_variables(): void {
		// The engine keeps its own state in $__-prefixed variables, so those
		// names are reserved and must not travel into a partial.
		$this->write( 'partials.row', '@isset ( $__secret ) leaked @endisset ok' );
		$this->write( 'page', '@include( "partials.row" )' );

		$this->assertSame( 'ok', $this->render( 'page', array( '__secret' => 'shh' ) ) );
	}

	public function test_a_literal_at_directive_can_be_written(): void {
		$this->write( 'page', '@@if is how you write a literal one' );

		$this->assertSame( '@if is how you write a literal one', $this->render( 'page' ) );
	}

	public function test_a_directive_directly_after_text_still_compiles(): void {
		// Requiring a word boundary before @ silently failed to close this,
		// which lost the section and leaked an output buffer.
		$this->write( 'layouts.main', '<html>@yield( "body" )</html>' );
		$this->write( 'page', '@extends( "layouts.main" )@section( "body" )hello@endsection' );

		$this->assertSame( '<html>hello</html>', $this->render( 'page' ) );
	}

	/* ─── Resolution, caching and failure ───────────────────────────── */

	public function test_dots_and_slashes_name_the_same_template(): void {
		$this->write( 'admin.files', 'x' );
		$engine = new Engine( $this->root, $this->cache );

		$this->assertTrue( $engine->exists( 'admin.files' ) );
		$this->assertTrue( $engine->exists( 'admin/files' ) );
		$this->assertFalse( $engine->exists( 'admin.nope' ) );
	}

	public function test_a_missing_template_names_itself(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/admin\.nope/' );

		( new Engine( $this->root, $this->cache ) )->render( 'admin.nope' );
	}

	public function test_a_name_cannot_climb_out_of_the_root(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Engine( $this->root, $this->cache ) )->path( '../../etc/passwd' );
	}

	public function test_the_cache_directory_is_created(): void {
		$this->write( 'page', 'x' );
		$this->render( 'page' );

		$this->assertDirectoryExists( $this->cache );
		$this->assertNotEmpty( glob( $this->cache . '/*.php' ) );
	}

	public function test_an_edited_template_recompiles(): void {
		$this->write( 'page', 'before' );
		$this->assertSame( 'before', $this->render( 'page' ) );

		// Same second; the mtime has to actually move for staleness to show.
		touch( $this->root . '/page.blade.php', time() + 10 );
		file_put_contents( $this->root . '/page.blade.php', 'after' );
		touch( $this->root . '/page.blade.php', time() + 10 );

		$this->assertSame( 'after', $this->render( 'page' ) );
	}

	public function test_the_compiled_file_says_where_it_came_from(): void {
		$this->write( 'page', 'x' );
		$this->render( 'page' );

		$compiled = (string) file_get_contents( (string) ( glob( $this->cache . '/*.php' )[0] ?? '' ) );

		$this->assertStringContainsString( 'Compiled from', $compiled );
		$this->assertStringContainsString( 'do not edit', $compiled );
	}

	/* ─── Found in review ───────────────────────────────────────────── */

	/**
	 * The origin path is written into a PHP comment; a `*\/` in it would end
	 * the comment and run the rest of the name.
	 */
	public function test_a_file_name_cannot_close_the_header_comment(): void {
		$compiled = ( new \SugarCommerce\Templates\Compiler() )->compile( 'hi', '/t/x*/ echo "pwned"; /*.blade.php' );

		ob_start();
		eval( '?>' . $compiled );
		$output = (string) ob_get_clean();

		$this->assertSame( 'hi', $output );
	}

	/**
	 * A backslash in a <script> string ends it early; @json() cannot be
	 * broken out of, nor can a value close the block.
	 */
	public function test_json_is_safe_inside_a_script_block(): void {
		$this->write( 'page', '<script>var a = @json( $a );</script>' );

		$html = $this->render( 'page', array( 'a' => "</script><script>alert(1)//\\'\"&" ) );

		$this->assertStringNotContainsString( '</script><script>', $html );
		$this->assertSame( 1, substr_count( $html, '</script>' ) );
		$this->assertStringNotContainsString( "'", substr( $html, 8, -9 ) );
		$this->assertSame( "</script><script>alert(1)//\\'\"&", json_decode( substr( $html, 16, -10 ) ) );
	}

	public function test_a_bare_json_is_left_alone(): void {
		$this->write( 'page', 'follow @json on social' );

		$this->assertSame( 'follow @json on social', $this->render( 'page' ) );
	}

	/**
	 * Without ENT_SUBSTITUTE a single bad byte emptied the whole value.
	 */
	public function test_a_malformed_byte_does_not_empty_the_value(): void {
		$this->assertSame( "caf\u{FFFD} &lt;b&gt;", Engine::e( "caf\xE9 <b>" ) );
	}

	public function test_a_resource_cannot_be_printed(): void {
		$this->expectException( \InvalidArgumentException::class );

		Engine::e( STDIN );
	}

	public function test_a_template_name_with_a_backslash_or_a_control_character_is_refused(): void {
		$engine = new Engine( $this->root, $this->cache );

		foreach ( array( "a\\b", "page\0", "page\n" ) as $name ) {
			try {
				$engine->path( $name );
				$this->fail( 'Accepted ' . json_encode( $name ) );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}
}
