/**
 * /post composer — app shell.
 * The NOP object (server data) + @font-face stay inline in class-posting-page.php;
 * this bundles the styles (and, next, the app script).
 */
import './style.scss';
import { ordinal, tkDur, parseShareParams } from './lib';

( function () {
	'use strict';

	var NOP = window.NOP || {};


	// Register the service worker — installs the app shell so /post opens offline.
	if ( 'serviceWorker' in navigator ) {
		navigator.serviceWorker.register( NOP.swUrl, { scope: NOP.swScope } ).catch( function () {} );
	}

	var DRAFT_KEY    = 'nop_post_draft';
	var CHAR_LIMITS  = { bluesky: 300, mastodon: 500, pixelfed: 500 };
	var NOTE_PROMPTS = [ "What's happening?", "Seen anything good?", "A thought…", "What's on your mind?", "Share something…" ];
	var notePrompt   = NOTE_PROMPTS[ Math.floor( Math.random() * NOTE_PROMPTS.length ) ];
	var restoring    = false;

	// Trailing debounce — coalesces high-frequency work (draft writes on keystroke)
	// to one call after the user pauses, keeping the typing path off localStorage.
	function debounce( fn, ms ) {
		var t;
		return function () { clearTimeout( t ); t = setTimeout( fn, ms ); };
	}

	var app = document.getElementById( 'app' );

	// ── Clock + time-of-day device ──────────────────────────────────────────────

	var DAYS   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
	var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

	var deviceTimeEl = document.getElementById( 'deviceTime' );
	// The masthead serial — shown in the ticker; bumped by one each time a post is
	// sent so "post another" shows the next likely id.
	var nextSerial   = NOP.nextId;

	// iOS 26 ignores theme-color and tints the status bar by SAMPLING the page (the
	// html accent on the tab, the .device-chrome band on the standalone app). It
	// samples at render time only, so a JS-driven --ink change (the OKLCH re-ink)
	// doesn't re-trigger it — on the standalone app's band, briefly dropping the band
	// from the render forces iOS to re-sample the new accent. Gated to standalone +
	// dark + real device (light re-samples; the desktop mock band must not flash).
	var deviceChrome = document.querySelector( '.device-chrome' );
	var isMockFrame  = window.matchMedia( '(min-width: 600px) and (min-height: 600px)' );
	var prefersDark  = window.matchMedia( '(prefers-color-scheme: dark)' );
	function nudgeStatusBar() {
		// Standalone only: the band exists there (it's hidden in a Safari tab, where
		// theme-color drives the bar and updates per kind on its own).
		if ( ! deviceChrome || ! window.navigator.standalone || isMockFrame.matches || ! prefersDark.matches ) { return; }
		deviceChrome.style.display = 'none';
		requestAnimationFrame( function () { deviceChrome.style.display = 'flex'; } );
	}

	// ── Re-ink: a colour-picker-style hue rotation through OKLCH ──────────────────
	// Drive --ink in JS so the kind switch sweeps through the hue wheel (teal →
	// green → orange) rather than a flat RGB crossfade. Every element reads
	// var(--ink), so they all rotate together — one uniform sweep.
	var inkNow    = document.getElementById( 'inkNow' );
	var KIND_VAR  = { note: '--teal', photo: '--blue', reply: '--orange', like: '--red', bookmark: '--green', repost: '--violet', rsvp: '--magenta' };
	var INK       = {};
	function buildInkMap() {
		var probe = document.createElement( 'span' );
		probe.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden';
		app.appendChild( probe );
		Object.keys( KIND_VAR ).forEach( function ( k ) {
			probe.style.color = 'var(' + KIND_VAR[ k ] + ')';
			INK[ k ] = getComputedStyle( probe ).color;
		} );
		app.removeChild( probe );
	}
	var OKLCH_OK = !! ( window.CSS && CSS.supports && CSS.supports( 'color', 'color-mix(in oklch, red, blue)' ) );
	var inkRAF;
	// The body's field tints from --ink too, but body sits outside .app, so mirror
	// the ink onto :root — app keeps its own (its CSS rule beats the inherited value),
	// while body finally has a hue to read. Driven here so both sweep in lockstep.
	var root = document.documentElement;
	function animateInk( from, to ) {
		cancelAnimationFrame( inkRAF );
		if ( ! to ) { return; }
		// Initial load (no-anim), unsupported engine, or no change → settle to the
		// scheme-aware CSS value instantly.
		if ( ! OKLCH_OK || app.classList.contains( 'no-anim' ) || ! from || from === to ) {
			app.style.removeProperty( '--ink' );
			root.style.setProperty( '--ink', to );
			return;
		}
		var start = 0;
		function step( now ) {
			if ( ! start ) { start = now; }
			var t = Math.min( ( now - start ) / 200, 1 );                       // matches the 200ms badge transitions
			var e = t < 0.5 ? 2 * t * t : 1 - Math.pow( -2 * t + 2, 2 ) / 2;     // easeInOut
			var mix = 'color-mix(in oklch, ' + from + ', ' + to + ' ' + ( e * 100 ).toFixed( 2 ) + '%)';
			app.style.setProperty( '--ink', mix );
			root.style.setProperty( '--ink', mix );
			if ( t < 1 ) { inkRAF = requestAnimationFrame( step ); }
			else { app.style.removeProperty( '--ink' ); root.style.setProperty( '--ink', to ); }  // app → CSS value; root holds the steady ink
		}
		inkRAF = requestAnimationFrame( step );
	}
	var lastTime = '';

	function greetingFor( hour ) {
		if ( hour < 5 )  return NOP.greetings.night;
		if ( hour < 12 ) return NOP.greetings.morning;
		if ( hour < 18 ) return NOP.greetings.afternoon;
		if ( hour < 22 ) return NOP.greetings.evening;
		return NOP.greetings.night;
	}

	function updateClock() {
		var now  = new Date();
		var time = String( now.getHours() ).padStart( 2, '0' ) + ':' + String( now.getMinutes() ).padStart( 2, '0' );
		var date = DAYS[ now.getDay() ] + ' ' + now.getDate() + ' ' + MONTHS[ now.getMonth() ];
		if ( time !== lastTime ) {
			if ( deviceTimeEl ) { deviceTimeEl.textContent = time; }
			tkTime = time;   // separate ticker items: date · time
			tkDate = date;
			setTk( 'tk-time', tkTime );
			setTk( 'tk-date', tkDate );
			setTk( 'tk-golden', tkGolden() );                          // countdown ticks down
			if ( lastPostTs ) { setTk( 'tk-last', 'Last posted ' + tkAgo( lastPostTs ) ); }
			lastTime = time;
		}
	}
	updateClock();
	setInterval( updateClock, 1000 );

	// ── Metadata ticker ───────────────────────────────────────────────────────
	// One crawling line of the current moment (serial · date · place · temp · sky)
	// beside the logo. The track holds two identical sequences and animates -50%
	// for a seamless loop. Items with no datum yet are simply omitted; the time
	// updates in place each minute (setTk) so the crawl never restarts, while a
	// resolved /now rebuilds once (renderTicker).
	var tickerTrack = document.getElementById( 'tickerTrack' );
	var TK_SPEED    = 24;                          // px/sec crawl (slow, ambient)
	var TK_ID_PRE   = 'Post No. ';            // spelled out — Brandon has no № glyph
	var tkTime = '', tkDate = '', tkPlace = '', tkTemp = '', tkSky = '';
	var tkSunset   = 0;                            // Unix sunset (from /now) → golden hour
	var postsToday = NOP.postsToday || 0;          // server count; bumped on each post
	var lastPostTs = NOP.lastPostTs || 0;          // Unix of the last published post
	var queueCount = 0;                            // offline posts waiting to send

	// Golden hour ≈ the last hour before sunset: counts down to it, reads "now"
	// during, then falls back to the sunset time once it's passed.
	function tkGolden() {
		if ( ! tkSunset ) { return ''; }
		var now = Math.floor( Date.now() / 1000 ), start = tkSunset - 3600;
		if ( now < start )    { return 'Golden hour in ' + tkDur( start - now ); }
		if ( now < tkSunset ) { return 'Golden hour now'; }
		return 'Sunset ' + tkClock( tkSunset );
	}
	function tkClock( ts ) {
		var d = new Date( ts * 1000 );
		return String( d.getHours() ).padStart( 2, '0' ) + ':' + String( d.getMinutes() ).padStart( 2, '0' );
	}
	function tkAgo( ts ) {
		var s = Math.floor( Date.now() / 1000 ) - ts;
		if ( s < 90 ) { return 'just now'; }
		var m = Math.round( s / 60 ); if ( m < 60 ) { return m + 'm ago'; }
		var h = Math.round( m / 60 ); if ( h < 24 ) { return h + 'h ago'; }
		var d = Math.round( h / 24 ); if ( d < 7 )  { return d + 'd ago'; }
		return Math.round( d / 7 ) + 'w ago';
	}
	function tkCadence() { return ordinal( postsToday + 1 ) + ' today'; }

	// Order reads out of the logo as: serial → session status → the moment →
	// where you are + the sky (golden hour closing the weather run). Values only —
	// each datum's own form (a clock, a temperature, a place) tells you what it is.
	function tkItems() {
		var out = [ { c: 'tk-id', h: TK_ID_PRE + nextSerial } ];
		if ( queueCount > 0 ) { out.push( { c: 'tk-queue', h: queueCount + ' to send' } ); }
		out.push( { c: 'tk-cadence', h: tkCadence() } );
		if ( lastPostTs ) { out.push( { c: 'tk-last',   h: 'Last posted ' + tkAgo( lastPostTs ) } ); }
		if ( tkDate )     { out.push( { c: 'tk-date',   h: tkDate } ); }
		if ( tkTime )     { out.push( { c: 'tk-time',   h: tkTime } ); }
		if ( tkPlace )    { out.push( { c: 'tk-place',  h: tkPlace } ); }
		if ( tkTemp )     { out.push( { c: 'tk-temp',   h: tkTemp } ); }
		if ( tkSky )      { out.push( { c: 'tk-sky',    h: tkSky } ); }
		if ( tkSunset )   { out.push( { c: 'tk-golden', h: tkGolden() } ); }
		return out;
	}
	function tkSeqHTML() {
		return tkItems().map( function ( it ) {
			return '<span class="ticker__item ' + it.c + '">'
				+ '<span class="ticker__val">' + it.h + '</span></span>'
				+ '<span class="ticker__sep" aria-hidden="true">·</span>';
		} ).join( '' );
	}
	function renderTicker() {
		if ( ! tickerTrack ) { return; }
		var seq = tkSeqHTML();
		tickerTrack.innerHTML = '<span class="ticker__seq">' + seq + '</span>'
			+ '<span class="ticker__seq">' + seq + '</span>';
		tkSeqW = tickerTrack.firstChild.getBoundingClientRect().width;   // one loop = one sequence
		startTickerMotion();
	}
	// In-place text swap for a recurring item (time, id) — avoids rebuilding the
	// track, which would restart the crawl.
	function setTk( cls, text ) {
		if ( ! tickerTrack ) { return; }
		var els = tickerTrack.getElementsByClassName( cls ), i, val;
		for ( i = 0; i < els.length; i++ ) {
			val = els[ i ].getElementsByClassName( 'ticker__val' )[ 0 ];
			if ( val ) { val.textContent = text; }
		}
	}

	// ── Ticker motion ───────────────────────────────────────────────────────────
	// The crawl is JS-driven (rAF), not the CSS animation, so a finger can scrub it:
	// drag moves the track 1:1, a flick spins it up, then friction eases the speed
	// back to the ambient crawl (TK_SPEED) — it never stops, it settles. Pure
	// progressive enhancement: without JS the CSS @keyframes still crawls, and under
	// prefers-reduced-motion we leave that CSS alone and don't attach any of this.
	var tkSeqW    = 0;                 // px width of one sequence — the wrap point
	var tkOffset  = 0;                 // px scrolled left out of view; wraps at tkSeqW
	var tkVel     = TK_SPEED;          // current px/sec; eases toward TK_SPEED when idle
	var TK_TAU    = 0.45;              // s — friction time constant (≈1.5s to settle)
	var TK_VMAX   = 1800;              // px/sec flick clamp
	var tkRAF     = 0, tkLastFrame = 0, tkDragging = false;
	var tkDragX   = 0, tkDragOff = 0, tkMoveX = 0, tkMoveT = 0;
	var tkReduce  = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function tkFrame( t ) {
		var dt = tkLastFrame ? ( t - tkLastFrame ) / 1000 : 0;
		tkLastFrame = t;
		if ( dt > 0.05 ) { dt = 0.05; }                                 // clamp tab-switch gaps
		if ( ! tkDragging ) {
			tkVel = TK_SPEED + ( tkVel - TK_SPEED ) * Math.exp( -dt / TK_TAU );   // ease to ambient
			tkOffset += tkVel * dt;
		}
		if ( tkSeqW > 0 ) {
			tkOffset %= tkSeqW;
			if ( tkOffset < 0 ) { tkOffset += tkSeqW; }
		}
		tickerTrack.style.transform = 'translateX(' + ( -tkOffset ).toFixed( 2 ) + 'px)';
		tkRAF = requestAnimationFrame( tkFrame );
	}
	function startTickerMotion() {
		if ( tkReduce || ! tickerTrack ) { return; }                   // CSS handles reduced motion
		tickerTrack.style.animation = 'none';                          // hand the crawl to rAF
		if ( ! tkRAF ) { tkRAF = requestAnimationFrame( tkFrame ); }
	}
	function tkDown( x ) {
		tkDragging = true;
		tkDragX = x; tkDragOff = tkOffset;
		tkMoveX = x; tkMoveT = Date.now();
		tkVel = 0;
	}
	function tkMove( x ) {
		if ( ! tkDragging ) { return; }
		tkOffset = tkDragOff - ( x - tkDragX );                        // finger right → reveal earlier items
		var now = Date.now(), dtt = ( now - tkMoveT ) / 1000;
		if ( dtt > 0 ) {                                                // flick speed = opposite of the finger
			tkVel = Math.max( -TK_VMAX, Math.min( TK_VMAX, -( x - tkMoveX ) / dtt ) );
		}
		tkMoveX = x; tkMoveT = now;
	}
	function tkUp() {
		tkDragging = false;        // the loop now decays tkVel (the flick) back to TK_SPEED
		tkLastFrame = 0;           // skip a dt spike on the first post-release frame
	}
	var tickerEl = tickerTrack && tickerTrack.parentNode;
	if ( tickerEl && ! tkReduce ) {
		tickerEl.addEventListener( 'pointerdown', function ( e ) {
			if ( tickerEl.setPointerCapture ) { try { tickerEl.setPointerCapture( e.pointerId ); } catch ( err ) {} }
			tkDown( e.clientX );
		} );
		tickerEl.addEventListener( 'pointermove',   function ( e ) { tkMove( e.clientX ); } );
		tickerEl.addEventListener( 'pointerup',     tkUp );
		tickerEl.addEventListener( 'pointercancel', tkUp );
	}
	lastTime = '';        // re-seed: populate tkTime/tkDate before the first build
	updateClock();
	renderTicker();
	// Brandon loads with font-display:swap, so the first measure can predate it.
	// tkSeqW is the wrap point now (not just a speed), so a stale width shows as a
	// seam gap — re-measure once the real font is in. (offset is preserved.)
	if ( document.fonts && document.fonts.ready ) {
		document.fonts.ready.then( renderTicker ).catch( function () {} );
	}

	// ── Current-moment data (place · temp · sky) ─────────────────────────────────
	// Device GPS → the /now endpoint (the server reverse-geocodes + fetches current
	// weather with the plugin's existing keys, so nothing leaks client-side). Both
	// the coordinates and the resolved payload are cached in localStorage: coords for
	// 6h so iOS isn't re-prompted every visit, the payload for 30 min so the ticker
	// paints instantly from cache then refreshes. Every failure path is silent — the
	// item is simply omitted (permission denied / offline / no API keys configured).
	var GEO_KEY = 'nop_post_geo', NOW_KEY = 'nop_post_now';
	var GEO_TTL = 6 * 60 * 60 * 1000, NOW_TTL = 30 * 60 * 1000;

	// Pirate Weather icon keyword → riso glyph (sun/moon reuse the flight-path art).
	var WX_SUN  = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="5"/><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1.5" x2="12" y2="4.5"/><line x1="12" y1="19.5" x2="12" y2="22.5"/><line x1="1.5" y1="12" x2="4.5" y2="12"/><line x1="19.5" y1="12" x2="22.5" y2="12"/><line x1="4.4" y1="4.4" x2="6.5" y2="6.5"/><line x1="17.5" y1="17.5" x2="19.6" y2="19.6"/><line x1="4.4" y1="19.6" x2="6.5" y2="17.5"/><line x1="17.5" y1="6.5" x2="19.6" y2="4.4"/></g></svg>';
	var WX_MOON = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>';
	var WX_CLOUD = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6.5 18a4.5 4.5 0 0 1-.5-8.97 6 6 0 0 1 11.64-1.2A4 4 0 0 1 17.5 18h-11Z"/></svg>';
	var WX_RAIN  = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6.5 15a4.5 4.5 0 0 1-.5-8.97 6 6 0 0 1 11.64-1.2A4 4 0 0 1 17.5 15h-11Z"/><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="18.5" x2="7" y2="21.5"/><line x1="12" y1="18.5" x2="11" y2="21.5"/><line x1="16" y1="18.5" x2="15" y2="21.5"/></g></svg>';
	var WX_SNOW  = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6.5 15a4.5 4.5 0 0 1-.5-8.97 6 6 0 0 1 11.64-1.2A4 4 0 0 1 17.5 15h-11Z"/><circle cx="8" cy="20" r="1"/><circle cx="12" cy="21.3" r="1"/><circle cx="16" cy="20" r="1"/></svg>';
	var WX_FOG   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="8" x2="20" y2="8"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="6" y1="16" x2="18" y2="16"/></svg>';
	var WX_WIND  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 9h10.5a2.5 2.5 0 1 0-2.5-2.5"/><path d="M3 14h14a2.5 2.5 0 1 1-2.5 2.5"/></svg>';
	var WX_PCD   = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="8" cy="7.5" r="3"/><g stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><line x1="8" y1="1.6" x2="8" y2="2.9"/><line x1="2.1" y1="7.5" x2="3.4" y2="7.5"/><line x1="3.4" y1="2.9" x2="4.4" y2="3.9"/><line x1="12.6" y1="2.9" x2="11.6" y2="3.9"/></g><path d="M9 19a4 4 0 0 1-.4-7.98 5.3 5.3 0 0 1 10.3-1A3.6 3.6 0 0 1 18.6 19H9Z"/></svg>';
	var WX_PCN   = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13.5 3.4A4.4 4.4 0 1 0 9 10.3 3.7 3.7 0 0 1 13.5 3.4Z"/><path d="M9 19a4 4 0 0 1-.4-7.98 5.3 5.3 0 0 1 10.3-1A3.6 3.6 0 0 1 18.6 19H9Z"/></svg>';
	var WX_ICON = {
		'clear-day': WX_SUN, 'clear-night': WX_MOON, 'cloudy': WX_CLOUD,
		'partly-cloudy-day': WX_PCD, 'partly-cloudy-night': WX_PCN,
		'rain': WX_RAIN, 'snow': WX_SNOW, 'sleet': WX_RAIN, 'wind': WX_WIND, 'fog': WX_FOG
	};

	function readJSON( k ) { try { return JSON.parse( localStorage.getItem( k ) ); } catch ( e ) { return null; } }
	function writeJSON( k, v ) { try { localStorage.setItem( k, JSON.stringify( v ) ); } catch ( e ) {} }

	function renderNow( d ) {
		if ( ! d ) { return; }
		tkPlace = d.place ? ( d.country ? d.place + ', ' + d.country : d.place ) : '';
		// Show both units — Belfast thinks in °C, but the °F is a free, useful glance.
		// Derive °F from the rounded °C so the two never disagree at a rounding edge
		// (a raw 11.7° would otherwise print "12°C / 53°F", which reads as a bug).
		tkTemp = '';
		if ( d.temp_c != null && d.temp_c !== '' ) {
			var c = Math.round( d.temp_c );
			tkTemp = c + '°C / ' + Math.round( c * 9 / 5 + 32 ) + '°F';
		}
		tkSky = '';
		if ( d.summary || d.icon ) {
			var icon = WX_ICON[ d.icon ] || '';
			tkSky = ( icon ? '<span class="ticker__icon" aria-hidden="true">' + icon + '</span>' : '' ) + ( d.summary || '' );
		}
		tkSunset = d.sunset ? Number( d.sunset ) : 0;   // golden hour derives from this
		renderTicker();   // rebuild once now that place/temp/sky/sunset have resolved
	}

	function fetchNow( lat, lon ) {
		var sep = NOP.nowUrl.indexOf( '?' ) >= 0 ? '&' : '?';
		fetch( NOP.nowUrl + sep + 'lat=' + encodeURIComponent( lat ) + '&lon=' + encodeURIComponent( lon ), {
			headers: { 'X-WP-Nonce': NOP.nonce }
		} )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( d ) { if ( d ) { renderNow( d ); writeJSON( NOW_KEY, { data: d, ts: Date.now() } ); } } )
			.catch( function () {} );
	}

	function withCoords( cb ) {
		var g = readJSON( GEO_KEY );
		if ( g && g.lat != null && ( Date.now() - g.ts ) < GEO_TTL ) { cb( g.lat, g.lon ); return; }
		if ( ! navigator.geolocation ) { return; }
		navigator.geolocation.getCurrentPosition(
			function ( pos ) {
				var lat = pos.coords.latitude, lon = pos.coords.longitude;
				writeJSON( GEO_KEY, { lat: lat, lon: lon, ts: Date.now() } );
				cb( lat, lon );
			},
			function () {},
			{ enableHighAccuracy: false, timeout: 8000, maximumAge: GEO_TTL }
		);
	}

	function loadNow() {
		var cached = readJSON( NOW_KEY );
		if ( cached && cached.data ) { renderNow( cached.data ); }        // paint instantly
		if ( cached && ( Date.now() - cached.ts ) < NOW_TTL ) { return; }  // still fresh
		withCoords( fetchNow );
	}
	loadNow();
	// Retry when the tab becomes visible again. iOS Safari's site-settings panel
	// (aA → Location → Allow) does NOT re-fire a load-time geolocation request, so
	// without this the grid stays empty until a manual reload after granting. Also
	// covers pull-to-refresh and bfcache restores. Cheap on repeat — loadNow() early
	// -returns while the cached payload is fresh, and a denied permission no longer
	// prompts, so this can't spam requests.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'visible' ) { loadNow(); }
	} );
	window.addEventListener( 'pageshow', loadNow );

	// ── Type configuration ────────────────────────────────────────────────────

	var TYPE_CONFIG = {
		note:     { urlProp: null,           hasContent: true,  hasTags: true,  hasPhoto: true, contentPlaceholder: 'Write a note…' },
		photo:    { urlProp: null,           hasContent: true,  hasTags: true,  hasPhoto: true, contentPlaceholder: 'Write a caption…' },
		reply:    { urlProp: 'in-reply-to',  hasContent: true,  hasTags: false, urlLabel: 'Reply to URL', contentPlaceholder: 'Your reply…' },
		like:     { urlProp: 'like-of',      hasContent: false, hasTags: false, urlLabel: 'Like URL', urlHint: "Paste the URL you're liking" },
		bookmark: { urlProp: 'bookmark-of',  hasContent: true,  hasTags: false, urlLabel: 'Bookmark URL', contentPlaceholder: 'Notes…' },
		repost:   { urlProp: 'repost-of',    hasContent: false, hasTags: false, urlLabel: 'Repost URL', urlHint: "Paste the URL you're reposting" },
		rsvp:     { urlProp: 'in-reply-to',  hasContent: true,  hasTags: false, urlLabel: 'Event URL', contentPlaceholder: 'Add a note (optional)…', hasRsvp: true },
	};

	var currentType   = 'note';
	var selectedFiles = [];
	var photoAlts     = [];
	var currentTags   = [];
	var currentRsvp   = 'yes';

	// ── DOM refs ──────────────────────────────────────────────────────────────

	var postBtn      = document.getElementById( 'postBtn' );
	var fieldUrl     = document.getElementById( 'fieldUrl' );
	var fieldRsvp    = document.getElementById( 'fieldRsvp' );
	var fieldPhoto   = document.getElementById( 'fieldPhoto' );
	var fieldContent = document.getElementById( 'fieldContent' );
	var fieldTags    = document.getElementById( 'fieldTags' );
	var urlInput     = document.getElementById( 'typeUrl' );
	var urlLabel     = document.getElementById( 'urlLabel' );
	var contentInput  = document.getElementById( 'content' );
	var composePrompt = document.getElementById( 'composePrompt' );
	var picker       = document.getElementById( 'photoPicker' );

	// Big rotating prompt overlay — set its text, and fade it once typing starts.
	function setPrompt( text ) { composePrompt.textContent = text; syncPrompt(); }
	function syncPrompt() { composePrompt.classList.toggle( 'is-hidden', contentInput.value.length > 0 ); }
	// The note placeholder, led by the time-of-day greeting — the old standalone
	// greeting line now lives here, in front of the rotating prompt.
	function notePlaceholder() {
		var greet = greetingFor( new Date().getHours() );
		return ( NOP.userName ? greet + ', ' + NOP.userName : greet ) + ' — ' + notePrompt;
	}
	function autoGrowContent() {
		contentInput.style.height = 'auto';
		contentInput.style.height = contentInput.scrollHeight + 'px';
		updateScrollFades();
	}
	var composeScroll = document.querySelector( '.compose-scroll' );
	var fadeTop       = document.querySelector( '.scroll-fade-top' );
	var fadeBottom    = document.querySelector( '.scroll-fade-bottom' );
	// Reveal by opacity over the static mask. Safe from the old haloing because the
	// shadow dot is the same size as the base dot (--ht-dot = --grain-dot): fading it
	// in just darkens each dot in place — no ring, and the mask never moves.
	function updateScrollFades() {
		if ( ! composeScroll ) return;
		var RAMP  = 56;
		var top   = composeScroll.scrollTop;
		var below = composeScroll.scrollHeight - composeScroll.clientHeight - top;
		if ( fadeTop )    fadeTop.style.opacity    = Math.min( Math.max( top, 0 ) / RAMP, 1 );
		if ( fadeBottom ) fadeBottom.style.opacity = Math.min( Math.max( below, 0 ) / RAMP, 1 );
	}
	// ONE master dot grid: every dot layer is anchored to the VIEWPORT origin (0,0),
	// so the surround, the phone interior and the swell-shadows all share the same
	// fixed grid and register exactly — the dots never move, scrolling just slides
	// content over them and the masks/opacity reveal the shadow dots in place.
	// background-position is per-box, so cancel each box's viewport offset mod pitch.
	function gridPitch() { return parseFloat( getComputedStyle( root ).getPropertyValue( '--grain-pitch' ) ) || 4; }
	function lockXY( left, top, pitch ) {
		function m( v ) { return ( ( v % pitch ) + pitch ) % pitch; }
		return ( -m( left ) ).toFixed( 2 ) + 'px ' + ( -m( top ) ).toFixed( 2 ) + 'px';
	}
	function lockEl( el, pitch ) {
		if ( ! el ) { return; }
		var r = el.getBoundingClientRect();
		el.style.backgroundPosition = lockXY( r.left, r.top, pitch );
	}
	// The kind-row edge fades scroll vertically with the strip, so re-anchor them on
	// every compose scroll to keep their dots pinned to the fixed grid.
	function lockTypeFades() {
		var pitch = gridPitch();
		lockEl( typeFadeLeft, pitch );
		lockEl( typeFadeRight, pitch );
	}
	function alignHalftone() {
		if ( ! composeScroll ) { return; }
		var pitch = gridPitch();
		// Phone interior grain — mobile: .app carries it; desktop: the .app::before
		// card, fed a CSS var (a pseudo-element has no JS box of its own).
		lockEl( app, pitch );
		var ar = app.getBoundingClientRect();
		var cs = getComputedStyle( app );
		var bl = parseFloat( cs.borderLeftWidth ) || 0;
		var bt = parseFloat( cs.borderTopWidth )  || 0;
		var st = parseFloat( cs.getPropertyValue( '--safe-top' ) ) || 0;
		app.style.setProperty( '--cardpos', lockXY( ar.left + bl, ar.top + bt + st, pitch ) );
		// Vertical scroll-fades — lock to the composeScroll edges (stable while stuck).
		var sr = composeScroll.getBoundingClientRect();
		var h  = ( fadeBottom && fadeBottom.offsetHeight ) || 120;
		if ( fadeTop )    { fadeTop.style.backgroundPosition    = lockXY( sr.left, sr.top, pitch ); }
		if ( fadeBottom ) { fadeBottom.style.backgroundPosition = lockXY( sr.left, sr.bottom - h, pitch ); }
		lockTypeFades();
	}
	composeScroll.addEventListener( 'scroll', function () { updateScrollFades(); lockTypeFades(); }, { passive: true } );

	// Horizontal scroll-fades for the kind row — same ramp-by-distance as the
	// vertical ones, so the left/right halftone edges fade in by how far there is
	// left to scroll. The right one is visible on load (Repost/RSVP sit off-screen).
	var typeBar       = document.getElementById( 'typeBar' );
	var typeFadeLeft  = document.querySelector( '.type-fade-left' );
	var typeFadeRight = document.querySelector( '.type-fade-right' );
	function updateTypeFades() {
		if ( ! typeBar ) { return; }
		// Ramp the reveal over ~one badge of scroll (matching the longer gradient's
		// reach) so the fade unfurls with the drag rather than snapping to full after
		// a few pixels. Opacity is read straight off scrollLeft, so it tracks the
		// finger (and momentum) 1:1.
		var RAMP  = 96;
		var left  = typeBar.scrollLeft;
		var right = typeBar.scrollWidth - typeBar.clientWidth - left;
		if ( typeFadeLeft )  { typeFadeLeft.style.opacity  = Math.min( Math.max( left, 0 ) / RAMP, 1 ); }
		if ( typeFadeRight ) { typeFadeRight.style.opacity = Math.min( Math.max( right, 0 ) / RAMP, 1 ); }
	}
	typeBar.addEventListener( 'scroll', updateTypeFades, { passive: true } );

	var resizeRAF;
	window.addEventListener( 'resize', function () {
		cancelAnimationFrame( resizeRAF );
		resizeRAF = requestAnimationFrame( function () { updateScrollFades(); alignHalftone(); updateTypeFades(); } );
	} );

	var photoInput   = document.getElementById( 'photoInput' );
	var thumbs       = document.getElementById( 'thumbnails' );
	var altTexts     = document.getElementById( 'altTexts' );
	var specimen      = document.getElementById( 'urlSpecimen' );
	var specimenGlyph = document.getElementById( 'specimenGlyph' );
	var specimenHint  = document.getElementById( 'specimenHint' );
	var specimenHost  = document.getElementById( 'specimenHost' );
	var specimenPath  = document.getElementById( 'specimenPath' );

	// URL specimen — on URL-only kinds the empty space below the field shows the
	// kind glyph as a watermark; once the URL parses it becomes a type specimen
	// of the target's hostname, confirming what you're about to act on.
	function updateSpecimen() {
		var cfg  = TYPE_CONFIG[ currentType ];
		var show = !! cfg.urlProp && ! cfg.hasContent;
		specimen.hidden = ! show;
		if ( ! show ) return;

		var parsed = null;
		var raw    = urlInput.value.trim();
		if ( raw ) {
			try { parsed = new URL( raw ); } catch ( e ) {}
		}
		var filled = !! ( parsed && parsed.hostname );

		specimenGlyph.hidden = filled;
		specimenHint.hidden  = filled;
		specimenHost.hidden  = ! filled;
		if ( filled ) {
			specimenHost.textContent = parsed.hostname.replace( /^www\./, '' );
			var path = parsed.pathname + parsed.search;
			specimenPath.textContent = path;
			specimenPath.hidden = path === '/';
		} else {
			specimenPath.hidden = true;
			specimenHint.textContent = cfg.urlHint || 'Paste a URL';
			specimenGlyph.innerHTML = '';
			var icon = document.querySelector( '.type-btn[data-type="' + currentType + '"] .type-btn__icon svg' );
			if ( icon ) {
				var big = icon.cloneNode( true );
				big.setAttribute( 'width', '96' );
				big.setAttribute( 'height', '96' );
				specimenGlyph.appendChild( big );
			}
		}
	}

	// ── Syndicators ───────────────────────────────────────────────────────────
	// Targets are inlined server-side (NOP.syndicateTo) — no fetch needed.

	// Re-rendered whenever the kind changes: photo-only targets (Pixelfed) only
	// appear on photo posts. Preserves the visitor's tick state across switches.
	function renderSyndicators() {
		var box  = document.getElementById( 'syndicators' );
		var prev = {};
		box.querySelectorAll( 'input[type=checkbox]' ).forEach( function (cb) { prev[ cb.value ] = cb.checked; } );

		var synTo = ( NOP.syndicateTo || [] ).filter( function (s) {
			return ! s.photoOnly || currentType === 'photo';
		} );
		if ( ! synTo.length ) {
			box.innerHTML = '';
			document.getElementById( 'syndicateDetails' ).hidden = true;
			return;
		}
		box.innerHTML = synTo.map( function (s) {
			var limit   = CHAR_LIMITS[ s.uid ];
			var checked = ( s.uid in prev ) ? prev[ s.uid ] : true;
			return '<label class="syndicator-item">'
				+ '<input type="checkbox" class="sr-only" value="' + escAttr( s.uid ) + '"' + ( checked ? ' checked' : '' ) + '>'
				+ '<span class="syndicator-box" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 256 256" fill="currentColor"><path d="M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z"/></svg></span>'
				+ ' ' + escHtml( s.name )
				+ ( limit ? '<span class="syndicator-item__limit">' + limit + '</span>' : '' )
				+ '</label>';
		} ).join( '' );
		document.getElementById( 'syndicateDetails' ).hidden = false;
		updateCounter();
	}
	renderSyndicators();

	// ── Tags ─────────────────────────────────────────────────────────────────

	var tagInput  = document.getElementById( 'tagInput' );
	var tagsField = document.getElementById( 'tagsField' );

	tagsField.addEventListener( 'click', function () { tagInput.focus(); } );

	tagInput.addEventListener( 'keydown', function (e) {
		if ( e.key === 'Enter' || e.key === ',' ) {
			e.preventDefault();
			addTag( tagInput.value );
		} else if ( e.key === 'Backspace' && tagInput.value === '' && currentTags.length ) {
			currentTags.pop();
			renderTags();
			saveDraft();
		}
	} );

	tagInput.addEventListener( 'blur', function () {
		addTag( tagInput.value );
	} );

	function addTag( raw ) {
		var tag = raw.trim().replace( /^,+|,+$/g, '' ).trim();
		if ( tag && ! currentTags.includes( tag ) ) {
			currentTags.push( tag );
			renderTags();
			saveDraft();
		}
		tagInput.value = '';
	}

	function renderTags() {
		document.getElementById( 'tagChips' ).innerHTML = currentTags.map( function (tag, i) {
			return '<span class="tag-chip">'
				+ escHtml( tag )
				+ '<button class="tag-chip__remove" type="button" data-index="' + i + '" aria-label="Remove ' + escAttr( tag ) + '">×</button>'
				+ '</span>';
		} ).join( '' );
	}

	tagsField.addEventListener( 'click', function (e) {
		var btn = e.target.closest( '.tag-chip__remove' );
		if ( btn ) {
			currentTags.splice( parseInt( btn.dataset.index, 10 ), 1 );
			renderTags();
			saveDraft();
		}
	} );

	// ── Type switching ────────────────────────────────────────────────────────

	document.getElementById( 'typeBar' ).addEventListener( 'click', function (e) {
		var btn = e.target.closest( '.type-btn' );
		if ( ! btn ) { return; }
		// A short tick on an actual kind change — best-effort (iOS Safari ignores it),
		// the same nicety the Post button already gives on send.
		if ( btn.dataset.type !== currentType && navigator.vibrate ) { navigator.vibrate( 8 ); }
		switchType( btn.dataset.type );
	} );

	document.getElementById( 'rsvpToggle' ).addEventListener( 'click', function (e) {
		var btn = e.target.closest( '.rsvp-btn' );
		if ( ! btn || btn.dataset.rsvp === currentRsvp ) { return; }
		currentRsvp = btn.dataset.rsvp;
		if ( navigator.vibrate ) { navigator.vibrate( 8 ); }
		document.querySelectorAll( '.rsvp-btn' ).forEach( function (b) {
			var on = b.dataset.rsvp === currentRsvp;
			b.classList.toggle( 'is-active', on );
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		saveDraft();
	} );

	function switchType( type ) {
		if ( ! TYPE_CONFIG[ type ] ) return;
		currentType = type;
		var cfg = TYPE_CONFIG[ type ];

		var prevInk = inkNow ? getComputedStyle( inkNow ).color : '';
		app.dataset.type = type;
		animateInk( prevInk, INK[ type ] );

		var activeBtn = null;
		document.querySelectorAll( '.type-btn' ).forEach( function (b) {
			var active = b.dataset.type === type;
			b.classList.toggle( 'is-active', active );
			b.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			if ( active ) { activeBtn = b; }
		} );
		// Only nudge the strip when the active badge is actually clipped at a scroll
		// edge, and only by the minimum to clear it. scrollIntoView('nearest') with
		// the 120px scroll-padding treated every near-edge (but fully visible) badge
		// as off-screen and yanked the strip sideways on each pick — this keeps a
		// visible badge put, so selecting no longer shifts the row.
		if ( activeBtn ) {
			var grid = activeBtn.parentElement;
			var br = activeBtn.getBoundingClientRect();
			var gr = grid.getBoundingClientRect();
			var edge = 8;  // just clear the container edge; fades stay a soft hint
			if ( br.left < gr.left + edge ) {
				grid.scrollBy( { left: br.left - gr.left - edge, behavior: 'smooth' } );
			} else if ( br.right > gr.right - edge ) {
				grid.scrollBy( { left: br.right - gr.right + edge, behavior: 'smooth' } );
			}
		}

		fieldUrl.hidden     = ! cfg.urlProp;
		fieldRsvp.hidden    = ! cfg.hasRsvp;
		fieldPhoto.hidden   = ! cfg.hasPhoto;
		fieldContent.hidden = ! cfg.hasContent;
		fieldTags.hidden    = ! cfg.hasTags;

		if ( cfg.urlProp ) urlLabel.textContent = cfg.urlLabel || 'URL';
		if ( cfg.hasContent ) {
			setPrompt( ( type === 'note' ) ? notePlaceholder() : ( cfg.contentPlaceholder || 'Write…' ) );
		}

		updateSpecimen();
		renderSyndicators();
		updateCounter();
		saveDraft();
		updatePostBtn();
		autoGrowContent();
		setTimeout( nudgeStatusBar, 450 );
	}

	// ── Share-to-app ──────────────────────────────────────────────────────────
	// Prefill from URL params, so a share (Android share_target → ?title/text/url)
	// or an iOS Shortcut (?reply=/?bookmark=/?text= …) opens the right kind, filled.
	function applyShareParams() {
		var s = parseShareParams( location.search );
		var kind = s.kind, url = s.url, text = s.text;
		if ( ! kind ) { return false; }

		clearDraft();
		restoring = true;
		switchType( kind );
		var cfg = TYPE_CONFIG[ kind ];
		urlInput.value     = cfg.urlProp   ? url  : '';
		contentInput.value = cfg.hasContent ? text : '';
		restoring = false;

		updateSpecimen(); updatePostBtn(); updateCounter(); autoGrowContent(); syncPrompt();
		saveDraft();
		// Strip the params so a reload/relaunch doesn't re-prefill the same share.
		if ( window.history && history.replaceState ) { history.replaceState( {}, '', location.pathname ); }
		return true;
	}

	// ── Post button state ─────────────────────────────────────────────────────

	function updatePostBtn() {
		var cfg     = TYPE_CONFIG[ currentType ];
		var enabled = false;
		if ( currentType === 'photo' ) {
			enabled = selectedFiles.length > 0;
		} else if ( cfg.urlProp ) {
			enabled = urlInput.value.trim().length > 0;
		} else {
			// A note posts on text alone, or on an attached image alone.
			enabled = contentInput.value.trim().length > 0 || ( cfg.hasPhoto && selectedFiles.length > 0 );
		}
		postBtn.disabled = ! enabled;
	}

	urlInput.addEventListener( 'input', function () { updateSpecimen(); updatePostBtn(); saveDraftSoon(); } );
	contentInput.addEventListener( 'input', function () { updatePostBtn(); updateCounter(); saveDraftSoon(); syncPrompt(); autoGrowContent(); } );
	document.getElementById( 'syndicators' ).addEventListener( 'change', updateCounter );

	// ── Photo picker ──────────────────────────────────────────────────────────

	picker.addEventListener( 'click', function () { photoInput.click(); } );
	picker.addEventListener( 'dragover', function (e) { e.preventDefault(); picker.classList.add( 'drag-over' ); } );
	picker.addEventListener( 'dragleave', function () { picker.classList.remove( 'drag-over' ); } );
	picker.addEventListener( 'drop', function (e) {
		e.preventDefault();
		picker.classList.remove( 'drag-over' );
		handleFiles( Array.from( e.dataTransfer.files ).filter( function (f) { return f.type.startsWith( 'image/' ); } ) );
	} );
	photoInput.addEventListener( 'change', function () { handleFiles( Array.from( photoInput.files ) ); } );

	function handleFiles( files ) {
		selectedFiles     = files.slice( 0, 10 );
		photoAlts         = [];
		thumbs.innerHTML  = '';
		altTexts.innerHTML = '';
		selectedFiles.forEach( function (file, i) {
			var cell       = document.createElement( 'figure' );
			cell.className = 'thumb';
			var img = document.createElement( 'img' );
			img.src = URL.createObjectURL( file );
			img.alt = '';
			cell.appendChild( img );
			thumbs.appendChild( cell );

			var row   = document.createElement( 'div' );
			row.className = 'alt-text-row';
			var lbl   = document.createElement( 'span' );
			lbl.className = 'alt-text-label';
			lbl.textContent = selectedFiles.length > 1
				? 'Photo ' + ( i + 1 ) + ' — alt text'
				: 'Alt text';
			var alt           = document.createElement( 'input' );
			alt.type          = 'text';
			alt.className     = 'thumb__alt';
			alt.placeholder   = 'Describe the photo…';
			alt.autocomplete  = 'off';
			alt.dataset.index = i;
			alt.setAttribute( 'aria-label', 'Alt text for photo ' + ( i + 1 ) );
			row.appendChild( lbl );
			row.appendChild( alt );
			altTexts.appendChild( row );
		} );
		picker.querySelector( 'p' ).textContent = selectedFiles.length
			? selectedFiles.length + ' photo' + ( selectedFiles.length > 1 ? 's' : '' ) + ' selected'
			: 'Add photos';
		updatePostBtn();
	}

	altTexts.addEventListener( 'input', function (e) {
		if ( e.target.classList.contains( 'thumb__alt' ) ) {
			photoAlts[ parseInt( e.target.dataset.index, 10 ) ] = e.target.value;
		}
	} );

	// ── Post ──────────────────────────────────────────────────────────────────

	// The write nonce — refreshed from the server before a queue replay (the page
	// nonce can expire before connectivity returns). Live posts use it as-is.
	var nonce = NOP.nonce;

	postBtn.addEventListener( 'click', async function () {
		var post = formToPost();

		// Offline up front → straight to the queue (no point attempting the network).
		if ( ! navigator.onLine && window.indexedDB ) { await queueAndAck( post ); return; }

		try {
			showView( 'progress' );
			var result = await sendPost( post, setProgress );
			recordKindUse( post.type );   // float this kind to the front next time
			setProgress( 'Syndicating…', 0.97 );
			await delay( 600 );
			if ( post.type === 'photo' && post.content ) {
				await navigator.clipboard.writeText( post.content ).catch( function () {} );
			}
			showSuccess( result.permalink, result.editUrl, result.photoUrls );
		} catch ( err ) {
			// A dropped connection mid-send → queue it rather than lose the post.
			if ( window.indexedDB && ( ! navigator.onLine || err instanceof TypeError ) ) {
				await queueAndAck( post );
			} else {
				showView( 'compose' );
				showToast( 'Something went wrong: ' + err.message, 'error' );
			}
		}
	} );

	// Snapshot the form into a plain, storable post — photo Files ride along as
	// blobs (structured-clone keeps them through IndexedDB).
	function formToPost() {
		var files = [];
		if ( TYPE_CONFIG[ currentType ].hasPhoto ) {
			for ( var i = 0; i < selectedFiles.length; i++ ) {
				files.push( { blob: selectedFiles[ i ], name: selectedFiles[ i ].name || 'photo.jpg', type: selectedFiles[ i ].type || 'image/jpeg', alt: ( photoAlts[ i ] || '' ) } );
			}
		}
		return {
			id:          'p' + Date.now() + '-' + Math.random().toString( 36 ).slice( 2, 7 ),
			type:        currentType,
			content:     contentInput.value.trim(),
			url:         urlInput.value.trim(),
			rsvp:        currentRsvp,
			tags:        currentTags.slice(),
			syndicateTo: Array.prototype.map.call( document.querySelectorAll( '#syndicators input[type="checkbox"]:checked' ), function ( cb ) { return cb.value; } ),
			files:       files,
		};
	}

	// The single place a post is actually sent — shared by the live path and the
	// queue replay. Uploads photos, then creates the post via Micropub.
	async function sendPost( post, onProgress ) {
		var photoUrls = [];
		if ( post.files && post.files.length ) {
			for ( var i = 0; i < post.files.length; i++ ) {
				if ( onProgress ) { onProgress( 'Uploading ' + ( i + 1 ) + ' of ' + post.files.length + '…', ( i / post.files.length ) * 0.75 ); }
				var up = await uploadPhoto( post.files[ i ].blob, post.files[ i ].name, post.files[ i ].type );
				photoUrls.push( up.source_url );
			}
		}
		if ( onProgress ) { onProgress( 'Posting…', 0.88 ); }
		var response = await fetch( NOP.micropubUrl, {
			method:  'POST',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			body:    JSON.stringify( buildPayload( post, photoUrls ) ),
		} );
		if ( response.status !== 201 ) {
			var errBody = await response.json().catch( function () { return {}; } );
			throw new Error( errBody.message || 'Posting failed (' + response.status + ')' );
		}
		return {
			permalink: response.headers.get( 'Location' ) || '',
			editUrl:   response.headers.get( 'X-Edit-URL' ) || '',
			photoUrls: photoUrls,
		};
	}

	function buildPayload( post, photoUrls ) {
		var cfg   = TYPE_CONFIG[ post.type ];
		var props = {};

		props[ 'post-kind' ] = [ post.type ];

		if ( post.content && cfg.hasContent ) { props.content = [ post.content ]; }

		// RSVP rides on in-reply-to (the event URL); the rsvp property is what makes
		// the server resolve it as an RSVP rather than a plain reply.
		if ( cfg.urlProp && post.url ) { props[ cfg.urlProp ] = [ post.url ]; }
		if ( cfg.hasRsvp ) { props.rsvp = [ post.rsvp ]; }

		// Alt text rides along as the server's array photo shape ({primary, alt});
		// sideload_photos copies it onto the attachment, where both the rendered
		// post and the Mastodon/Bluesky syndicators read it.
		if ( photoUrls && photoUrls.length ) {
			props.photo = photoUrls.map( function ( url, i ) {
				var alt = ( ( post.files[ i ] && post.files[ i ].alt ) || '' ).trim();
				return alt ? { primary: url, alt: alt } : url;
			} );
		}
		if ( cfg.hasTags && post.tags.length ) { props.category = post.tags.slice(); }

		// Always sent, even when empty — an explicitly empty selection means
		// "this site only"; omitting the property would fall back to the
		// server's default of syndicating to every enabled platform.
		props[ 'syndicate-to' ] = post.syndicateTo.slice();

		return { type: [ 'h-entry' ], properties: props };
	}

	async function uploadPhoto( blob, name, type ) {
		var res = await fetch( NOP.mediaUrl, {
			method:  'POST',
			headers: {
				'X-WP-Nonce':          nonce,
				'Content-Disposition': 'attachment; filename="' + ( name || 'photo.jpg' ) + '"',
				'Content-Type':        type || 'image/jpeg',
			},
			body: blob,
		} );
		if ( ! res.ok ) {
			var err = await res.json().catch( function () { return {}; } );
			throw new Error( err.message || 'Upload failed (' + res.status + ')' );
		}
		return res.json();
	}

	// ── Offline queue (IndexedDB) + replay ───────────────────────────────────────
	// When offline, a post is stored whole (incl. photo blobs) and replayed when the
	// app is next open and back online. iOS Safari has no Background Sync, so replay
	// is page-driven: on the `online` event and on launch.
	var DB_NAME = 'nop_post_queue', STORE = 'posts', replaying = false;

	function qOpen() {
		return new Promise( function ( resolve, reject ) {
			var req = indexedDB.open( DB_NAME, 1 );
			req.onupgradeneeded = function () { req.result.createObjectStore( STORE, { keyPath: 'id' } ); };
			req.onsuccess = function () { resolve( req.result ); };
			req.onerror   = function () { reject( req.error ); };
		} );
	}
	function qAdd( post ) {
		return qOpen().then( function ( db ) { return new Promise( function ( res, rej ) {
			var tx = db.transaction( STORE, 'readwrite' ); tx.objectStore( STORE ).put( post );
			tx.oncomplete = function () { res(); }; tx.onerror = function () { rej( tx.error ); };
		} ); } );
	}
	function qAll() {
		return qOpen().then( function ( db ) { return new Promise( function ( res, rej ) {
			var out = [], cur = db.transaction( STORE, 'readonly' ).objectStore( STORE ).openCursor();
			cur.onsuccess = function ( e ) { var c = e.target.result; if ( c ) { out.push( c.value ); c.continue(); } else { res( out ); } };
			cur.onerror = function () { rej( cur.error ); };
		} ); } );
	}
	function qDelete( id ) {
		return qOpen().then( function ( db ) { return new Promise( function ( res ) {
			var tx = db.transaction( STORE, 'readwrite' ); tx.objectStore( STORE ).delete( id );
			tx.oncomplete = function () { res(); }; tx.onerror = function () { res(); };
		} ); } );
	}
	// Count without loading the stored blobs into memory.
	function qCount() {
		return qOpen().then( function ( db ) { return new Promise( function ( res ) {
			var r = db.transaction( STORE, 'readonly' ).objectStore( STORE ).count();
			r.onsuccess = function () { res( r.result ); }; r.onerror = function () { res( 0 ); };
		} ); } );
	}

	// Reflect the pending count in the ticker's "N to send" item. Rebuild only when
	// the item appears/disappears (0↔N); otherwise just swap the number in place.
	function setQueueCount( n ) {
		var toggled = ( n > 0 ) !== ( queueCount > 0 );
		queueCount = n;
		if ( toggled ) { renderTicker(); }
		else if ( n > 0 ) { setTk( 'tk-queue', n + ' to send' ); }
		// Mirror the count on the home-screen app icon (iOS 16.4+, Android, desktop).
		if ( 'setAppBadge' in navigator ) {
			if ( n > 0 ) { navigator.setAppBadge( n ).catch( function () {} ); }
			else if ( navigator.clearAppBadge ) { navigator.clearAppBadge().catch( function () {} ); }
		}
	}
	function refreshQueueCount() {
		if ( ! window.indexedDB ) { return Promise.resolve(); }
		return qCount().then( setQueueCount ).catch( function () {} );
	}

	function refreshNonce() {
		return fetch( NOP.nonceUrl, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.ok ? r.json() : null; } )
			.then( function ( d ) { if ( d && d.nonce ) { nonce = d.nonce; } } )
			.catch( function () {} );
	}

	async function queueAndAck( post ) {
		try { await qAdd( post ); }
		catch ( e ) { showView( 'compose' ); showToast( "Couldn't save offline: " + e.message, 'error' ); return; }
		setQueueCount( queueCount + 1 );        // show "N to send" in the ticker
		recordKindUse( post.type );
		showToast( 'Saved — will post when you’re back online.', 'info' );
		resetForm();
	}

	async function replayQueue() {
		if ( replaying || ! window.indexedDB || ! navigator.onLine ) { return; }
		replaying = true;
		try {
			var items = await qAll();
			if ( ! items.length ) { return; }
			await refreshNonce();                       // the page nonce may have expired
			for ( var i = 0; i < items.length; i++ ) {
				try {
					var result = await sendPost( items[ i ], null );
					await qDelete( items[ i ].id );       // delete right after the 201 — minimise the double-post window
					queueCount   = Math.max( 0, queueCount - 1 );
					postsToday  += 1;
					lastPostTs   = Math.floor( Date.now() / 1000 );
					renderTicker();
					showToast( 'Queued post published.', 'info' );
				} catch ( e ) {
					if ( ! navigator.onLine || e instanceof TypeError ) { break; }  // still offline — keep the queue intact
					showToast( 'A queued post failed: ' + e.message, 'error' );       // server error — stop, leave it for inspection
					break;
				}
			}
		} catch ( e ) {} finally { replaying = false; refreshQueueCount(); }
	}

	// Ask the browser not to evict the queue under storage pressure (best-effort).
	if ( navigator.storage && navigator.storage.persist ) { navigator.storage.persist().catch( function () {} ); }

	window.addEventListener( 'online', replayQueue );
	replayQueue();           // flush anything stored from a previous, offline session
	refreshQueueCount();     // and surface the count if we're offline with posts waiting

	// ── Success ───────────────────────────────────────────────────────────────

	function showSuccess( permalink, editUrl, photoUrls ) {
		showView( 'success' );
		clearDraft();
		if ( navigator.vibrate ) navigator.vibrate( 10 );

		var streakEl = document.getElementById( 'successStreak' );
		var count    = bumpStreak();
		if ( count > 0 ) {
			streakEl.innerHTML = '<span class="success-streak__num">' + ordinal( count ) + '</span>'
				+ '<span class="success-streak__label">post today</span>';
			streakEl.hidden = false;
		} else {
			streakEl.hidden = true;
		}

		// Keep the ticker's cadence + last-posted live as you post through the session.
		postsToday  += 1;
		lastPostTs   = Math.floor( Date.now() / 1000 );
		renderTicker();

		document.getElementById( 'successPhotos' ).innerHTML = photoUrls.map( function (url) {
			return '<img src="' + escAttr( url ) + '" alt="">';
		} ).join( '' );

		var link = document.getElementById( 'successLink' );
		link.href = permalink; link.textContent = permalink;

		var editBtn = document.getElementById( 'editBtn' );
		editBtn.href = editUrl; editBtn.hidden = ! editUrl;

		var shareBtn = document.getElementById( 'shareBtn' );
		shareBtn.hidden = ! ( currentType === 'photo' && selectedFiles.length );
		shareBtn.onclick = async function () {
			if ( navigator.canShare && navigator.canShare( { files: selectedFiles } ) ) {
				try { await navigator.share( { files: selectedFiles } ); }
				catch ( e ) { if ( e.name !== 'AbortError' ) showToast( 'Share from your Photos app instead.', 'error' ); }
			} else {
				showToast( "Web sharing isn't supported on this browser.", 'info' );
			}
		};

		document.getElementById( 'anotherBtn' ).onclick = resetForm;
	}

	function resetForm() {
		selectedFiles = []; photoAlts = []; currentTags = [];
		contentInput.value = ''; urlInput.value = '';
		thumbs.innerHTML = ''; altTexts.innerHTML = ''; photoInput.value = '';
		picker.querySelector( 'p' ).textContent = 'Add photos';
		renderTags();
		clearDraft();
		notePrompt = NOTE_PROMPTS[ Math.floor( Math.random() * NOTE_PROMPTS.length ) ];
		if ( nextSerial ) { setTk( 'tk-id', TK_ID_PRE + ( ++nextSerial ) ); }
		switchType( 'note' );
		updateCounter();
		showView( 'compose' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	function showView( name ) {
		document.getElementById( 'view-compose'  ).hidden = name !== 'compose';
		document.getElementById( 'view-progress' ).hidden = name !== 'progress';
		document.getElementById( 'view-success'  ).hidden = name !== 'success';
	}

	function setProgress( message, fraction ) {
		document.getElementById( 'progressStatus' ).textContent = message;
		var fill = document.getElementById( 'progressFill' );
		fill.style.width = Math.round( fraction * 100 ) + '%';
		fill.parentElement.setAttribute( 'aria-valuenow', Math.round( fraction * 100 ) );
	}

	function delay( ms ) { return new Promise( function (resolve) { setTimeout( resolve, ms ); } ); }

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}
	function escAttr( str ) { return String( str ).replace( /"/g, '&quot;' ); }

	// ── Draft persistence ──────────────────────────────────────────────────────

	function saveDraft() {
		if ( restoring ) return;
		try {
			localStorage.setItem( DRAFT_KEY, JSON.stringify( {
				type:    currentType,
				content: contentInput.value,
				url:     urlInput.value,
				tags:    currentTags,
				rsvp:    currentRsvp,
			} ) );
		} catch ( e ) {}
	}
	// Keystroke path uses the debounced save (discrete actions still call saveDraft).
	var saveDraftSoon = debounce( saveDraft, 400 );

	function loadDraft() {
		var raw;
		try { raw = localStorage.getItem( DRAFT_KEY ); } catch ( e ) { return; }
		if ( ! raw ) return;
		var d;
		try { d = JSON.parse( raw ); } catch ( e ) { return; }
		if ( ! d ) return;
		restoring = true;
		if ( d.type && TYPE_CONFIG[ d.type ] ) switchType( d.type );
		if ( typeof d.content === 'string' ) contentInput.value = d.content;
		if ( typeof d.url === 'string' ) urlInput.value = d.url;
		if ( Array.isArray( d.tags ) ) { currentTags = d.tags.slice(); renderTags(); }
		if ( d.rsvp ) {
			currentRsvp = d.rsvp;
			document.querySelectorAll( '.rsvp-btn' ).forEach( function (b) {
				var on = b.dataset.rsvp === currentRsvp;
				b.classList.toggle( 'is-active', on );
				b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
		}
		restoring = false;
		updateSpecimen();
		updateCounter();
		updatePostBtn();
		saveDraft();
	}

	function clearDraft() { try { localStorage.removeItem( DRAFT_KEY ); } catch ( e ) {} }

	// ── Character counter ──────────────────────────────────────────────────────

	function currentLimit() {
		var lim = 0;
		Array.prototype.forEach.call(
			document.querySelectorAll( '#syndicators input[type="checkbox"]:checked' ),
			function ( cb ) {
				var l = CHAR_LIMITS[ cb.value ];
				if ( l ) lim = lim ? Math.min( lim, l ) : l;
			}
		);
		return lim;
	}

	// The textarea's running word count — shown the moment you start typing and
	// hidden again when the field is empty (or the kind has no content). Refreshed
	// alongside the char counter, the other compose-meta readout.
	function updateWordCount() {
		// Looked up per-call: the first updateCounter() runs inside
		// renderSyndicators() at module init, before any cached ref would resolve.
		var wordCountEl = document.getElementById( 'wordCount' );
		if ( ! TYPE_CONFIG[ currentType ].hasContent ) { wordCountEl.hidden = true; return; }
		var text  = contentInput.value.trim();
		var words = text ? text.split( /\s+/ ).length : 0;
		if ( ! words ) { wordCountEl.hidden = true; return; }
		wordCountEl.hidden  = false;
		wordCountEl.textContent = words + ' ' + ( words === 1 ? 'word' : 'words' );
	}

	function updateCounter() {
		updateWordCount();
		var el  = document.getElementById( 'charCount' );
		var len = contentInput.value.length;
		var lim = currentLimit();
		// Only surface the counter when a syndication limit applies and you're
		// within 50 of it (or over) — otherwise it's noise, so stay hidden.
		if ( ! TYPE_CONFIG[ currentType ].hasContent || ! lim || ( lim - len ) > 50 ) {
			el.hidden = true;
			return;
		}
		el.hidden = false;
		el.textContent = String( lim - len );
		el.classList.toggle( 'is-over', len > lim );
	}

	// ── Streak ─────────────────────────────────────────────────────────────────

	function bumpStreak() {
		var key = 'nop_post_count_' + new Date().toISOString().slice( 0, 10 );
		try {
			var n = parseInt( localStorage.getItem( key ) || '0', 10 ) + 1;
			localStorage.setItem( key, String( n ) );
			return n;
		} catch ( e ) { return 0; }
	}


	// ── Toast ──────────────────────────────────────────────────────────────────

	var toastTimer;
	function showToast( message, kind ) {
		var el = document.getElementById( 'toast' );
		el.textContent = message;
		el.className   = 'toast' + ( kind === 'error' ? ' toast--error' : '' );
		el.hidden      = false;
		void el.offsetWidth;
		el.classList.add( 'is-visible' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			el.classList.remove( 'is-visible' );
			setTimeout( function () { el.hidden = true; }, 250 );
		}, 3500 );
	}

	// ── Kind order — most-recently-used first (localStorage) ─────────────────────
	var KIND_MRU_KEY = 'nop_kind_mru';
	function readKindMru() {
		try { var a = JSON.parse( localStorage.getItem( KIND_MRU_KEY ) || '[]' ); return Array.isArray( a ) ? a : []; }
		catch ( e ) { return []; }
	}
	function recordKindUse( kind ) {
		try {
			var mru = readKindMru().filter( function ( k ) { return k !== kind; } );
			mru.unshift( kind );
			localStorage.setItem( KIND_MRU_KEY, JSON.stringify( mru ) );
		} catch ( e ) {}
	}
	function applyKindOrder() {
		var grid = document.getElementById( 'typeBar' );
		var mru  = readKindMru();
		if ( ! grid || ! mru.length ) { return; }
		Array.prototype.slice.call( grid.querySelectorAll( '.type-btn' ) ).sort( function ( a, b ) {
			var ia = mru.indexOf( a.dataset.type ); if ( ia < 0 ) { ia = 99; }
			var ib = mru.indexOf( b.dataset.type ); if ( ib < 0 ) { ib = 99; }
			return ia - ib;
		} ).forEach( function ( btn ) { grid.appendChild( btn ); } );
	}
	function mruDefaultKind() {
		var mru = readKindMru();
		return ( mru[0] && TYPE_CONFIG[ mru[0] ] ) ? mru[0] : 'note';
	}

	// ── Init ───────────────────────────────────────────────────────────────────

	buildInkMap();                            // resolve each kind's ink for OKLCH tweening
	if ( window.matchMedia ) {
		try { window.matchMedia( '(prefers-color-scheme: dark)' ).addEventListener( 'change', buildInkMap ); } catch ( e ) {}
	}
	applyKindOrder();                         // tiles in most-recently-used order
	app.classList.add( 'no-anim' );           // suppress the re-ink flash for the initial kind
	setPrompt( notePlaceholder() );
	var hadDraft = false;
	try { hadDraft = !! localStorage.getItem( DRAFT_KEY ); } catch ( e ) {}
	loadDraft();
	if ( ! hadDraft ) { switchType( mruDefaultKind() ); }   // no draft → open on the last-used kind
	applyShareParams();                                     // a share/Shortcut overrides the above
	updateCounter();
	syncPrompt();
	autoGrowContent();
	app.offsetHeight;                         // flush, then re-enable transitions
	app.classList.remove( 'no-anim' );
	alignHalftone();
	requestAnimationFrame( alignHalftone );   // re-align once layout (safe-area) settles
	if ( document.fonts && document.fonts.ready ) { document.fonts.ready.then( alignHalftone ); }  // and after the web font reflows the masthead
	updateTypeFades();
	requestAnimationFrame( updateTypeFades );  // after the MRU reorder + layout settles

} )();
