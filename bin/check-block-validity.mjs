#!/usr/bin/env node
/**
 * Reads block validity the only way it can be read: by running Gutenberg.
 *
 * A block's save() is JavaScript. Whether a hand-authored template agrees with
 * what the editor would have written therefore cannot be answered from PHP, or
 * from the front end — a static block renders its stored innerHTML either way,
 * so a page can look perfect while the Site Editor shows "Block contains
 * unexpected or invalid content" and offers to rewrite your file.
 *
 * This loads the Site Editor headlessly, where every block type registers, then
 * runs wp.blocks.parse() over every registered template and template part and
 * reports any block whose isValid is false. It covers the theme's and the
 * plugin's alike, because it asks WordPress what is registered rather than
 * reading a directory.
 *
 * Companion to check-block-markup.py, which catches a different failure: that
 * one finds unclosed containers and unbalanced block comments without needing
 * WordPress at all, and is the right first check. This one finds the cases
 * where the markup is well-formed but disagrees with save() — a class removed
 * without its attribute, an attribute removed without its class.
 *
 * Usage:
 *   node bin/check-block-validity.mjs [site-url]
 *
 * The URL defaults to $NOP_SITE_URL, then to http://localhost:8881. Studio
 * assigns ports dynamically, so get the current one from `studio status`
 * rather than assuming. Chrome is located via $CHROME_PATH if set.
 *
 * Exits non-zero if anything is invalid, so it can gate a commit.
 */
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire( import.meta.url );
const here = path.dirname( fileURLToPath( import.meta.url ) );

let puppeteer;
try {
	puppeteer = require( path.join( here, '..', 'node_modules', 'puppeteer-core' ) );
} catch {
	console.error( 'puppeteer-core not found — run `npm install` in the plugin first.' );
	process.exit( 2 );
}

const SITE = process.argv[ 2 ] || process.env.NOP_SITE_URL || 'http://localhost:8881';
const CHROME = process.env.CHROME_PATH
	|| '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

const browser = await puppeteer.launch( {
	executablePath: CHROME,
	headless: true,
	args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
} );

try {
	const page = await browser.newPage();
	page.setDefaultNavigationTimeout( 90000 );

	// Studio's auto-login sets the admin cookie, so no credentials live here.
	await page.goto(
		`${ SITE }/studio-auto-login?redirect_to=%2Fwp-admin%2F`,
		{ waitUntil: 'networkidle2' }
	);

	await page.goto( `${ SITE }/wp-admin/site-editor.php`, { waitUntil: 'networkidle2' } );

	// Wait for the editor to have registered its blocks, not merely to have
	// loaded — parsing before registration marks everything invalid.
	await page.waitForFunction(
		() => window.wp?.blocks?.parse && window.wp?.apiFetch
			&& window.wp.blocks.getBlockTypes().length > 20,
		{ timeout: 90000 }
	);

	const registered = await page.evaluate( () => window.wp.blocks.getBlockTypes().length );

	const report = await page.evaluate( async () => {
		const out = { templates: [], parts: [], errors: [] };

		const walk = ( blocks, acc, trail ) => {
			for ( const b of blocks ) {
				const here = [ ...trail, b.name || '(unknown)' ];
				if ( b.isValid === false ) {
					acc.push( { name: b.name, path: here.join( ' > ' ) } );
				}
				if ( b.innerBlocks?.length ) walk( b.innerBlocks, acc, here );
			}
			return acc;
		};

		const sources = [
			[ 'templates', '/wp/v2/templates?per_page=100' ],
			[ 'parts', '/wp/v2/template-parts?per_page=100' ],
		];

		for ( const [ key, path ] of sources ) {
			try {
				const items = await window.wp.apiFetch( { path } );
				for ( const t of items ) {
					const raw = t.content?.raw ?? '';
					if ( ! raw.trim() ) continue;
					out[ key ].push( { id: t.id, invalid: walk( window.wp.blocks.parse( raw ), [], [] ) } );
				}
			} catch ( e ) {
				out.errors.push( `${ key }: ${ e.message }` );
			}
		}
		return out;
	} );

	const line = ( label, rows ) => {
		const bad = rows.filter( ( r ) => r.invalid.length );
		console.log( `\n${ label }: ${ rows.length } checked, ${ bad.length } with invalid blocks` );
		for ( const r of bad ) {
			console.log( `  ${ r.id }` );
			for ( const i of r.invalid ) console.log( `      invalid: ${ i.path }` );
		}
	};

	console.log( `site: ${ SITE }` );
	console.log( `block types registered in the editor: ${ registered }` );
	line( 'templates', report.templates );
	line( 'template parts', report.parts );
	if ( report.errors.length ) console.log( '\nerrors:', report.errors );

	const total = [ ...report.templates, ...report.parts ]
		.reduce( ( n, r ) => n + r.invalid.length, 0 );
	console.log( `\nTOTAL invalid blocks: ${ total }` );
	process.exitCode = total ? 1 : 0;
} finally {
	await browser.close();
}
