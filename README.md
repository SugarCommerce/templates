# templates

A small Blade-like template engine for plain PHP.

```bash
composer require sugarcommerce/templates
```

Zero dependencies. A template compiles to a PHP file, which is then `require`d
— there is no runtime interpreter, so what actually executes is the same PHP
you would have written by hand.

## Why

In a plain PHP template every single output has to remember its escaping call:

```php
<td><?= htmlspecialchars( $order['email'], ENT_QUOTES, 'UTF-8' ) ?></td>
```

Miss one and you have cross-site scripting. Across two thousand lines of markup
that is not a question of discipline, it is a question of when.

Here the default is safe and opting out is visible:

```blade
<td>{{ $order['email'] }}</td>     {{-- escaped --}}
<td>{!! $badge !!}</td>            {{-- raw, and you can see that it is --}}
```

## Use

```php
use SugarCommerce\Templates\Engine;

$engine = new Engine(
    root: __DIR__ . '/templates',
    cache: __DIR__ . '/var/cache/templates',
);

echo $engine->render( 'admin.orders', array( 'page' => $page ) );
```

`admin.orders` and `admin/orders` name the same file. Compiled output is cached
and recompiled when the source changes; pass `always: true` in development,
where a template's `@include`s can change without its own mtime moving.

## Syntax

```blade
{{ $value }}              escaped output
{!! $value !!}            raw output
{{-- a note --}}          removed entirely, unlike an HTML comment
@@if                      a literal @if

@if ( $a )  @elseif ( $b )  @else  @endif
@unless ( $a )  @endunless
@isset ( $a )  @endisset
@empty ( $rows )  @endempty

@foreach ( $rows as $row )  @endforeach
@forelse ( $rows as $row )  @empty  @endforelse
@for ( $i = 0; $i < 3; $i++ )  @endfor
@while ( $more )  @endwhile
@continue        @continue ( $row['hidden'] )
@break           @break ( $i > 10 )

@extends( 'layouts.admin' )
@section( 'body' )  @endsection
@section( 'title', 'Orders' )
@yield( 'body' )   @yield( 'title', 'Untitled' )

@include( 'partials.row' )
@include( 'partials.row', array( 'label' => 'Total' ) )

@php  $total = array_sum( $amounts );  @endphp
@verbatim  {{ leftAlone }}  @endverbatim
```

An `@` that is not one of these is left exactly as written, so `hi@example.com`
and `@media (min-width: 40rem)` pass through untouched — including their
spacing.

## Layouts

```blade
{{-- templates/layouts/admin.blade.php --}}
<html>
  <title>@yield( 'title', 'Admin' )</title>
  <body>@yield( 'body' )</body>
</html>
```

```blade
{{-- templates/admin/orders.blade.php --}}
@extends( 'layouts.admin' )

@section( 'title', 'Orders' )

@section( 'body' )
  <table>
    @forelse ( $page->rows as $order )
      <tr><td>{{ $order['email'] }}</td></tr>
    @empty
      <tr><td>No orders yet.</td></tr>
    @endforelse
  </table>
@endsection
```

Output outside a section is discarded when a template extends something — what
it contributes is its sections, and the layout decides where those land.
Layouts nest: a layout may extend another.

## Includes

A partial sees the including template's variables, which is most of what makes
one worth extracting — it does not have to be handed every value it already
had. Extra variables win over the enclosing scope:

```blade
@foreach ( $rows as $row )
  @include( 'partials.row' )                             {{-- sees $row --}}
  @include( 'partials.row', array( 'row' => $other ) )   {{-- overridden --}}
@endforeach
```

Variables whose names begin with `__` are reserved by the engine and never
travel into a partial.

## What this is not

A template compiles to PHP and is then executed. **A template is code.** Never
compile one somebody else wrote, and never let an operator edit one through a
form — that is a remote-code-execution hole with extra steps. Content that
needs editing at runtime belongs in a string with placeholders; see
[email-templates](https://github.com/sugarcommerce/email-templates), which
reads its bodies rather than including them, for that shape.

There is no `$loop` variable, no components, no view composers and no service
container. The whole engine is three classes.

## Printing the wrong thing

`{{ $rows }}` where `$rows` is an array throws, naming the type. Printing the
word `Array` into a page is a bug that survives review; an exception does not.
`null` and `false` print nothing.

## Testing

```bash
composer test
```

## License

GPL-2.0-or-later
