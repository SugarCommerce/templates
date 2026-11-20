# Templates

Front-end templates a theme can override by copying them, the way WooCommerce
and Easy Digital Downloads do it, with a Site Health check that lists the
copies that have fallen behind.

## What it does

A plugin that prints markup on the front end wants that markup in template
files, so a theme can change it without editing the plugin. That means a
lookup (child theme, then parent, then the plugin), arguments passed in
safely, hooks either side, a way for add-ons to ship templates of their own,
and, once themes start copying, a way to tell which copies are now older
than the plugin's.

This is that lookup in the shape theme developers already know from
WooCommerce: `{theme}/{folder}/{name}.php`, `@version` headers, a debug
switch that ignores the theme, and template parts with variants.

## Features

* Load a template by name, letting the child theme, then the parent, override it
* Get a template's output as a string, for a shortcode or a block
* Load a variant (`card-wide`) when there is one, and the general template when not
* Ask whether a template exists, to draw optional markup only when somebody supplied it
* Let an add-on ship templates of its own that themes override too
* Refuse names that climb out of the templates folder (`../`, absolute paths)
* Stop a template that loads itself with a notice, rather than a hang
* Hand data to a script as JSON that cannot close the `<script>` it is in
* Switch the theme's copies off with one constant, to see whether the theme is the cause
* List the theme's copies that are older than the plugin's, in Site Health

## Installation

```bash
composer require sugarcommerce/templates
```

## Quick start

```php
use SugarCommerce\Templates\Loader;

$templates = new Loader(
	'myshop',                                   // hook prefix
	plugin_dir_path( __FILE__ ) . 'templates/', // the plugin's templates
	'myshop/',                                  // the folder in a theme
	MYSHOP_VERSION,                             // quoted in developer notices
	defined( 'MYSHOP_TEMPLATE_DEBUG_MODE' ) && MYSHOP_TEMPLATE_DEBUG_MODE
);

// templates/cart/cart.php, or {theme}/myshop/cart/cart.php when the theme has a copy.
$templates->get( 'cart/cart', [ 'items' => $cart->items(), 'total' => $cart->total() ] );
```

Inside the template each argument is a variable (`$items`, `$total`), and the
whole array is `$args`, as WordPress's `load_template()` gives it.

## Loading

```php
$templates->get( 'cart/cart', $args );                   // print it
$html = $templates->html( 'receipt/receipt', $args );    // return it
$templates->part( 'account/downloads', 'empty', $args ); // downloads-empty, else downloads
$file = $templates->locate( [ 'product/single-book', 'product/single' ] ); // first that exists

if ( $templates->exists( 'cart/banner' ) ) {             // only when a theme supplied one
	$templates->get( 'cart/banner', $args );
}
```

A list of names is a list of preferences: the first name that exists anywhere
(theme or plugin) wins over every later one.

A missing template, a name that leaves the folder, or an argument named
`args`, `this`, a superglobal or anything beginning `__` is a
`_doing_it_wrong()` notice, never a fatal; the argument stays in `$args`.
`exists()` says nothing about a template that is simply not there.

`html()` closes every output buffer it and the template opened, even when the
template throws. A template that loads itself, or two that load each other,
stop at `Loader::MAX_DEPTH` (64) templates deep with a notice.

## Theme overrides

A theme copies `templates/cart/cart.php` to `{theme}/myshop/cart/cart.php`
and edits the copy; a child theme's copy beats its parent's. Put the path in
each template's docblock with a version, which you raise whenever the markup
changes in a way a copy should follow:

```php
<?php
/**
 * The cart.
 *
 * Override by copying to yourtheme/myshop/cart/cart.php.
 *
 * @version 1.2.0
 */
```

Define the debug constant you passed to the loader as true and every theme
copy is ignored, which answers "is it the plugin or the theme?" in one step.

Templates are plain PHP on purpose. A theme developer copies one and edits it
with nothing new to learn, and there is no compiled cache: a template
language compiled to PHP means writing code to a folder the web server can
write to and running it, which is what WordPress security scanners look for.

## Templates from an add-on

An add-on passes its own folders to the same loader, and themes override its
templates under a folder of their own:

```php
$templates->get( 'dashboard', $args, 'myshop/affiliates/', __DIR__ . '/templates/' );
```

## Escaping is for HTML

`esc_html()` and `esc_attr()` make a value safe as text between tags and as an
attribute value in quotes, and nowhere else. A template cannot tell where it
is printing, so:

- **In `<script>`**, use `Loader::json( $value )`. It escapes `<`, `>`, `&` and
  both quotes, so a value holding `</script>` cannot close the block, and a
  quote cannot end a string early.
- **In a data attribute**, the same: `data-config="<?php echo Loader::json( $config ); ?>"`
  is safe as it stands.
- **In `href` or `src`**, use `esc_url()`. `javascript:alert(1)` has nothing in
  it for `esc_attr()` to escape.
- **In `style`**, validate: a colour is `#rrggbb` or it is not used.
  `red;background:url(…)` survives every escaper and is still CSS.
- **Always quote attributes.** Unquoted, a space starts a new one.

```php
<script>
	window.myshopCart = <?php echo Loader::json( $cart ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON for a script. ?>;
</script>
```

A value with no JSON form (`INF`, a resource) prints `null` with a notice.

## Hooks

Every hook is named from the prefix, so two plugins bundling this library
never hear each other's:

| Hook | Kind | For |
|------|------|-----|
| `{prefix}_template_path` | filter | the folder in the theme |
| `{prefix}_locate_template` | filter | the file a name resolved to |
| `{prefix}_get_template` | filter | swap the file about to load (one that does not exist is refused) |
| `{prefix}_template_args` | filter | what a template is given |
| `{prefix}_before_template_part` / `{prefix}_after_template_part` | actions | either side of each template |
| `{prefix}_template_scan_paths` | filter | the folders the outdated-copy report checks |

## Outdated theme copies in Site Health

```php
use SugarCommerce\Templates\SiteHealth;

SiteHealth::register( [
	'id'             => 'myshop_templates',
	'label'          => 'My Shop',
	'sources'        => $templates,
	'debug_constant' => 'MYSHOP_TEMPLATE_DEBUG_MODE',
	'folder'         => 'wp-content/plugins/my-shop/templates/',
] );
```

Site Health then lists each copy in the theme whose `@version` is lower than
the plugin's (or that has none), by its path from the themes folder, and
explains how to bring it up to date. An add-on adds its folder through
`{prefix}_template_scan_paths`; `sources` may also be a callable or the
array itself. Pass `strings` to use your own wording or translations
(`good_label`, `good_description`, `outdated_label` as a callable given the
count, `outdated_description`, `row`, `none`, `debug`) and `badge_color` for
the badge.

The same list as data, for a screen of your own:

```php
use SugarCommerce\Templates\Overrides;

Overrides::outdated( $templates->scan_paths( 'My Shop' ) ); // [ source, name, file, version, theme_version ]
Overrides::files( $dir );                                   // every template in a folder, sorted
Overrides::version( $file );                                // a template's @version, or ''
```

## Security

* Path traversal: a template name that is empty, absolute, holds `..`, a
  backslash, a colon or a control character is refused, and a file swapped
  in through `{prefix}_get_template` must exist.
* Variable injection: an argument named `this`, `args`, a superglobal or
  anything starting `__` is never made a variable, so it cannot replace the
  file being loaded or a global.
* A template that loads itself stops at 64 levels instead of exhausting the
  stack.
* `Loader::json()` is safe inside `<script>` and in an attribute; the theme
  versions Site Health lists are escaped.

## Layout

* `src/Loader.php`: finding and loading a template, with theme overrides.
* `src/Overrides.php`: the theme's copies and their `@version`s.
* `src/SiteHealth.php`: the Site Health test for outdated copies.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
