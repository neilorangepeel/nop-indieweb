/**
 * Reproduces the editor's enhanced-pagination compatibility check.
 *
 * Gutenberg walks the blocks inside a Query with enhancedPagination on, and if
 * any of them is a third-party block that has not declared
 * supports.interactivity.clientNavigation === true, it force-disables the
 * setting and shows "Query block: reload full page enabled". That save marks
 * the template dirty, which is what makes it recur.
 */
import { createRequire } from 'node:module';
const require = createRequire( import.meta.url );
const puppeteer = require( '/Users/neilhainworth/Studio/neilorangepeel/wp-content/plugins/nop-indieweb/node_modules/puppeteer-core' );

const SITE = 'http://localhost:8881';
const browser = await puppeteer.launch( {
	executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
	headless: true,
	args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
} );

try {
	const page = await browser.newPage();
	page.setDefaultNavigationTimeout( 90000 );
	await page.goto( `${ SITE }/studio-auto-login?redirect_to=%2Fwp-admin%2F`, { waitUntil: 'networkidle2' } );
	await page.goto( `${ SITE }/wp-admin/site-editor.php`, { waitUntil: 'networkidle2' } );
	await page.waitForFunction(
		() => window.wp?.blocks?.parse && window.wp.blocks.getBlockTypes().length > 20,
		{ timeout: 90000 }
	);

	const out = await page.evaluate( async () => {
		const { getBlockType, parse } = window.wp.blocks;

		// The editor's own rule, restated.
		const incompatible = ( name ) => {
			if ( name.startsWith( 'core/' ) ) return false;
			const t = getBlockType( name );
			return t?.supports?.interactivity?.clientNavigation !== true;
		};

		const walk = ( blocks, acc ) => {
			for ( const b of blocks ) {
				if ( incompatible( b.name ) ) acc.add( b.name );
				if ( b.innerBlocks?.length ) walk( b.innerBlocks, acc );
			}
			return acc;
		};

		const findQueries = ( blocks, acc ) => {
			for ( const b of blocks ) {
				if ( b.name === 'core/query' && b.attributes?.enhancedPagination ) acc.push( b );
				if ( b.innerBlocks?.length ) findQueries( b.innerBlocks, acc );
			}
			return acc;
		};

		const rows = [];
		for ( const path of [ '/wp/v2/templates?per_page=100', '/wp/v2/template-parts?per_page=100' ] ) {
			for ( const t of await window.wp.apiFetch( { path } ) ) {
				const raw = t.content?.raw ?? '';
				if ( ! raw.trim() ) continue;
				for ( const q of findQueries( parse( raw ), [] ) ) {
					const bad = [ ...walk( q.innerBlocks, new Set() ) ];
					if ( bad.length ) rows.push( { id: t.id, bad } );
				}
			}
		}
		return rows;
	} );

	if ( ! out.length ) {
		console.log( 'No Query block with enhanced pagination contains an incompatible block.' );
		console.log( 'The editor has nothing to disable — the notice should not recur.' );
	} else {
		console.log( 'Would still trigger "reload full page":' );
		for ( const r of out ) console.log( `  ${ r.id }: ${ r.bad.join( ', ' ) }` );
	}
	process.exitCode = out.length ? 1 : 0;
} finally {
	await browser.close();
}
