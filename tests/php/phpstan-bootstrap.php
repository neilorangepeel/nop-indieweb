<?php
/**
 * PHPStan bootstrap: define the plugin constants that nop-indieweb.php would set
 * at runtime, so static analysis of the included files doesn't flag them as
 * undefined. PHPStan never executes this — it only reads the definitions.
 */
declare( strict_types=1 );

define( 'NOP_INDIEWEB_VERSION', '0.0.0' );
define( 'NOP_INDIEWEB_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'NOP_INDIEWEB_URL', 'https://example.test/wp-content/plugins/nop-indieweb/' );

// Core's cookie constants, which wp_cookie_constants() defines at runtime and the WordPress stubs do not declare (the
// reply form hands them to its script, to forget a remembered commenter).
define( 'COOKIEHASH', '' );
define( 'COOKIEPATH', '/' );
