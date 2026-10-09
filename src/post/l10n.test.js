/**
 * Every __( '…' ) string in the composer must have a translation entry in
 * Posting_Page::js_strings() — otherwise it silently stays English on a
 * translated site. Reads both sources as text; no WordPress needed.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const js = fs.readFileSync( path.join( __dirname, 'index.js' ), 'utf8' );
const php = fs.readFileSync( path.join( __dirname, '../../includes/class-posting-page.php' ), 'utf8' );

const unquote = ( s ) => s.replace( /\\(.)/g, '$1' );

test( 'every composer string has a js_strings() entry', () => {
	const used = new Set();
	for ( const m of js.matchAll( /__\( (?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)") \)/g ) ) {
		used.add( unquote( m[ 1 ] ?? m[ 2 ] ) );
	}
	const body = php.slice( php.indexOf( 'function js_strings()' ) );
	const known = new Set();
	for ( const m of body.matchAll( /^\t+'((?:[^'\\]|\\.)*)' => __\(/gm ) ) {
		known.add( unquote( m[ 1 ] ) );
	}
	expect( used.size ).toBeGreaterThan( 100 );
	expect( [ ...used ].filter( ( s ) => ! known.has( s ) ) ).toEqual( [] );
} );
