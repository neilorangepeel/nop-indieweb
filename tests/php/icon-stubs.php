<?php
/**
 * PHPStan stub for WordPress's Icon API (wp-includes/icons.php).
 *
 * The bundled WordPress stubs predate the Icon API (wp_register_icon_collection() is @since 7.1.0), which the plugin
 * relies on because 7.2 is its minimum WordPress. PHPStan reads this file (scanFiles) so the symbols resolve; it is
 * never loaded or executed.
 *
 * @see includes/registrars/class-block-registrar.php
 */

/**
 * @param string               $slug Collection slug.
 * @param array<string,mixed>  $args Label and description.
 * @return bool Whether the collection was registered.
 */
function wp_register_icon_collection( $slug, $args ) {}

/**
 * @param string               $icon_name Namespaced icon name, e.g. "phosphor/heart".
 * @param array<string,mixed>  $args      Label and file_path (or content).
 * @return bool Whether the icon was registered.
 */
function wp_register_icon( $icon_name, $args ) {}
