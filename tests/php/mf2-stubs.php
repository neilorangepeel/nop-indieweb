<?php
/**
 * PHPStan stub for the php-mf2 library (Mf2\parse).
 *
 * The microformats2 parser is an OPTIONAL runtime dependency — it ships with
 * other IndieWeb plugins on the production server, never with this plugin
 * (vendor/ is dev-only and absent in production). Every call site guards it
 * with `function_exists( 'Mf2\\parse' )` before use, so the missing function
 * is safe at runtime. PHPStan, however, can't see that guard's payoff and
 * reports `Function Mf2\parse not found` — this stub declares the signature so
 * static analysis resolves the symbol. PHPStan only reads it (scanFiles); it
 * is never loaded or executed.
 *
 * @see includes/preview/class-link-parser.php
 * @see includes/rsvp/class-event-parser.php
 */

namespace Mf2;

/**
 * @param string|\DOMDocument $input
 * @return array{items?: array<int, array<string, mixed>>, rels?: array<string, mixed>, 'rel-urls'?: array<string, mixed>}
 */
function parse( $input, ?string $url = null, bool $convertClassic = true ): array {
	return array();
}
