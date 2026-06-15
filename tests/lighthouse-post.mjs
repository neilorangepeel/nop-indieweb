// Lighthouse checker for the auth-gated /post page (mobile, simulated slow 4G).
//
// Run periodically:   npm run test:lighthouse
//
// Deps:  lighthouse + puppeteer-core are devDependencies (npm ci installs them;
//   they're dev-only — the production server git-pulls with no npm step, so
//   nothing here ships). Also needs system Google Chrome and the Studio site
//   running (`studio site start --skip-browser`).
//
// Overrides (Studio's port is assigned dynamically — pass the current one):
//   NOP_ORIGIN   default http://localhost:8881   e.g. NOP_ORIGIN=http://localhost:8901 npm run test:lighthouse
//   NOP_CHROME   default the macOS Google Chrome path

let puppeteer, lighthouse;
try {
	puppeteer  = ( await import( 'puppeteer-core' ) ).default;
	lighthouse = ( await import( 'lighthouse' ) ).default;
} catch ( e ) {
	console.error( 'Missing deps for the Lighthouse checker. Run once:\n  npm i -D lighthouse puppeteer-core' );
	process.exit( 1 );
}

const CHROME = process.env.NOP_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const ORIGIN = process.env.NOP_ORIGIN || 'http://localhost:8881';
const LOGIN  = ORIGIN + '/studio-auto-login?redirect_to=%2Fwp-admin%2F';
const TARGET = ORIGIN + '/post';

const browser = await puppeteer.launch( {
	executablePath: CHROME,
	headless: 'new',
	args: [ '--remote-debugging-port=0', '--no-sandbox' ],
} );
const port = Number( new URL( browser.wsEndpoint() ).port );

// Authenticate in the shared default context so the cookie carries to /post.
const page = await browser.newPage();
await page.goto( LOGIN, { waitUntil: 'networkidle2' } );

// Default lighthouse mobile config = Moto-G-class CPU + simulated slow 4G —
// exactly the "poor network" target.
const runner = await lighthouse( TARGET, {
	port,
	output: [ 'json' ],
	logLevel: 'error',
	onlyCategories: [ 'performance', 'accessibility', 'best-practices', 'seo' ],
} );

const lhr = runner.lhr;
const pct = ( c ) => Math.round( ( lhr.categories[ c ]?.score ?? 0 ) * 100 );
const ms  = ( id ) => lhr.audits[ id ]?.displayValue || 'n/a';

console.log( '\n=== Lighthouse: /post (mobile, simulated slow 4G) ===' );
console.log( 'Performance   ', pct( 'performance' ) );
console.log( 'Accessibility ', pct( 'accessibility' ) );
console.log( 'Best practices', pct( 'best-practices' ) );
console.log( 'SEO           ', pct( 'seo' ) );
console.log( '\n--- Core metrics ---' );
[ 'first-contentful-paint', 'largest-contentful-paint', 'total-blocking-time',
  'cumulative-layout-shift', 'speed-index', 'interactive' ].forEach(
	( id ) => console.log( id.padEnd( 26 ), ms( id ) ) );

const fails = Object.values( lhr.audits ).filter(
	( a ) => a.score !== null && a.score < 1 &&
		( a.details?.type === 'table' || a.scoreDisplayMode === 'binary' ) );
console.log( '\n--- Accessibility / best-practice failures ---' );
fails.filter( ( a ) => [ 'accessibility', 'best-practices' ].some(
	( c ) => lhr.categories[ c ].auditRefs.some( ( r ) => r.id === a.id ) ) )
	.forEach( ( a ) => console.log( '•', a.id, '—', a.title ) );

console.log( '\n--- Performance opportunities / diagnostics (score < 1) ---' );
lhr.categories.performance.auditRefs
	.map( ( r ) => lhr.audits[ r.id ] )
	.filter( ( a ) => a && a.score !== null && a.score < 0.9 && a.scoreDisplayMode !== 'informative' )
	.forEach( ( a ) => console.log( '•', a.id, '—', a.title, a.displayValue ? '(' + a.displayValue + ')' : '' ) );

console.log( '\n--- Render-blocking + unused bytes ---' );
[ 'render-blocking-resources', 'unused-css-rules', 'unused-javascript',
  'unminified-css', 'unminified-javascript', 'font-display', 'uses-text-compression',
  'total-byte-weight' ].forEach( ( id ) => {
	const a = lhr.audits[ id ];
	if ( ! a ) return;
	const items = a.details?.items || [];
	console.log( '\n#', id, a.displayValue || '' );
	items.slice( 0, 6 ).forEach( ( it ) => console.log( '   ',
		( it.url || it.label || '' ).replace( ORIGIN, '' ),
		it.wastedMs ? Math.round( it.wastedMs ) + 'ms' : '',
		it.wastedBytes ? Math.round( it.wastedBytes / 1024 ) + 'KiB' : '',
		it.totalBytes ? '/' + Math.round( it.totalBytes / 1024 ) + 'KiB' : '' ) );
} );

await browser.close();
