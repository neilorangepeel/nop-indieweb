/**
 * /post smoke test — drives the real composer in a headless browser to catch
 * "did I break the app" regressions (boot, kind switch, share-prefill, live
 * post, offline queue → reconnect → replay).
 *
 * SAFE BY DESIGN: the Micropub + media + nonce endpoints are MOCKED via route
 * interception, so it never hits the real endpoint and NOTHING is published or
 * syndicated to any social channel. No posts are created.
 *
 * Requires the Studio site running + logged in. Run:
 *   NOP_POST_BASE=http://localhost:8881 node tests/post-smoke.cjs
 * (defaults to http://localhost:8881). Exits non-zero on any failed check.
 */
'use strict';

const { chromium } = require( '../node_modules/playwright-core' );
const BASE = process.env.NOP_POST_BASE || 'http://localhost:8881';

const results = [];
function check( name, pass ) { results.push( { name, pass } ); console.log( ( pass ? '  ✓ ' : '  ✗ ' ) + name ); }

async function main() {
	const browser = await chromium.launch();
	const ctx = await browser.newContext( {
		viewport: { width: 390, height: 844 },
		permissions: [ 'geolocation' ],
		geolocation: { latitude: 54.5973, longitude: -5.9301 },
	} );
	const page = await ctx.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( String( e ) ) );

	// ── Mock the write endpoints so nothing is ever really posted/syndicated ──
	await page.route( '**/wp-json/wp/v2/media', ( route ) =>
		route.fulfill( { status: 201, contentType: 'application/json', body: JSON.stringify( { source_url: BASE + '/mock-photo.jpg' } ) } )
	);
	await page.route( '**/wp-json/nop-indieweb/v1/micropub', ( route ) =>
		route.fulfill( { status: 201, headers: { Location: BASE + '/mock-permalink', 'X-Edit-URL': BASE + '/mock-edit' }, contentType: 'application/json', body: '{}' } )
	);
	await page.route( '**/post?nonce=1', ( route ) =>
		route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { nonce: 'smoke' } ) } )
	);

	const idbCount = () => page.evaluate( () => new Promise( ( res ) => {
		// No explicit version — open whatever the app created (a lower version
		// than the live schema would throw VersionError and read as -1).
		const r = indexedDB.open( 'nop_post_queue' );
		r.onsuccess = () => { const db = r.result; if ( ! db.objectStoreNames.contains( 'posts' ) ) return res( 0 ); const c = db.transaction( 'posts', 'readonly' ).objectStore( 'posts' ).count(); c.onsuccess = () => res( c.result ); };
		r.onerror = () => res( -1 );
	} ) );

	await page.goto( BASE + '/studio-auto-login?redirect_to=%2Fwp-admin%2F', { waitUntil: 'domcontentloaded' } );
	await page.waitForTimeout( 600 );
	// Clean slate: drop any old SW/cache so we exercise the freshly built bundle.
	await page.evaluate( async () => {
		if ( navigator.serviceWorker ) { for ( const r of await navigator.serviceWorker.getRegistrations() ) await r.unregister(); }
		if ( window.caches ) { for ( const k of await caches.keys() ) await caches.delete( k ); }
	} );

	// 1. Boots and reads window.NOP; ticker renders.
	await page.goto( BASE + '/post', { waitUntil: 'load' } );
	await page.waitForTimeout( 1800 );
	check( 'boots (window.NOP present)', await page.evaluate( () => typeof window.NOP === 'object' && !! window.NOP.micropubUrl ) );
	check( 'ticker renders', await page.evaluate( () => !! ( document.querySelector( '.ticker__seq' ) || {} ).textContent ) );

	// 2. Kind switch re-inks.
	await page.click( '.type-btn[data-type="photo"]' ).catch( () => {} );
	await page.waitForTimeout( 400 );
	check( 'kind switch (photo)', ( await page.evaluate( () => document.getElementById( 'app' ).dataset.type ) ) === 'photo' );

	// 3. Share-prefill (?reply=).
	await page.goto( BASE + '/post?reply=https://example.org/x', { waitUntil: 'load' } );
	await page.waitForTimeout( 700 );
	check( 'share-prefill reply', await page.evaluate( () => document.getElementById( 'app' ).dataset.type === 'reply' && document.getElementById( 'typeUrl' ).value === 'https://example.org/x' ) );

	// 4. Live post (mocked) → success view. Select Note first so it's deterministic
	// regardless of any restored draft / most-recently-used kind.
	await page.goto( BASE + '/post', { waitUntil: 'load' } );
	await page.waitForTimeout( 800 );
	await page.click( '.type-btn[data-type="note"]' );
	await page.evaluate( () => { const t = document.getElementById( 'content' ); t.value = 'smoke ' + Math.random(); t.dispatchEvent( new Event( 'input', { bubbles: true } ) ); } );
	await page.click( '#postBtn' );
	// The send-undo grace window holds the real send for 5s — wait it out plus
	// the mocked round-trip before expecting the success view.
	await page.waitForTimeout( 9000 );
	check( 'live post → success', await page.evaluate( () => ! document.getElementById( 'view-success' ).hidden ) );

	// 5. Offline queue, then reconnect → replay drains.
	await page.goto( BASE + '/post', { waitUntil: 'load' } );
	await page.waitForTimeout( 800 );
	await page.click( '.type-btn[data-type="note"]' );
	await ctx.setOffline( true );
	await page.evaluate( () => { const t = document.getElementById( 'content' ); t.value = 'offline ' + Math.random(); t.dispatchEvent( new Event( 'input', { bubbles: true } ) ); } );
	await page.click( '#postBtn' );
	await page.waitForTimeout( 800 );
	check( 'offline → queued (1)', ( await idbCount() ) === 1 );
	await ctx.setOffline( false );
	await page.evaluate( () => window.dispatchEvent( new Event( 'online' ) ) );
	await page.waitForTimeout( 3000 );
	check( 'reconnect → queue drained (0)', ( await idbCount() ) === 0 );

	check( 'no page errors', errors.length === 0 );
	if ( errors.length ) console.log( '  errors:', JSON.stringify( errors ) );

	await browser.close();

	const failed = results.filter( ( r ) => ! r.pass ).length;
	console.log( '\n' + ( results.length - failed ) + '/' + results.length + ' checks passed' );
	process.exit( failed ? 1 : 0 );
}

main().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
