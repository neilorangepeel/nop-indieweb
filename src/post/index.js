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
	var prefersReduce = window.matchMedia( '(prefers-reduced-motion: reduce)' );
	function nudgeStatusBar() {
		// Standalone only: the band exists there (it's hidden in a Safari tab, where
		// theme-color drives the bar and updates per kind on its own).
		if ( ! deviceChrome || ! window.navigator.standalone || isMockFrame.matches || ! prefersDark.matches ) { return; }
		deviceChrome.style.display = 'none';
		requestAnimationFrame( function () { deviceChrome.style.display = 'flex'; } );
	}

	// ── Kind ink ──────────────────────────────────────────────────────────────
	// Each kind owns a hue. .app[data-type] sets --ink on .app via CSS (instant);
	// body + html sit outside .app, so mirror the kind's ink onto :root too — the
	// status-bar accent and the desktop field read it there.
	var KIND_VAR = { note: '--teal', photo: '--blue', reply: '--orange', like: '--red', bookmark: '--green', repost: '--violet', rsvp: '--magenta' };
	var root     = document.documentElement;
	function setInk( type ) {
		root.style.setProperty( '--ink', 'var(' + ( KIND_VAR[ type ] || '--red' ) + ')' );
	}
	var lastTime = '';

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
			if ( lastPostTs ) { setTk( 'tk-last', 'Last: ' + tkAgo( lastPostTs ) ); }
			refreshLight();                                            // golden/daylight/blue tick; rebuild if the set changed
			lastTime = time;
		}
	}
	// Self-rescheduling timer that aligns to minute boundaries — one wakeup/minute
	// vs the old setInterval(…,1000) that fired 60× per minute and gated 59 of them.
	// Backgrounded tabs may drift; visibilitychange below catches that up on return.
	var clockTimer = 0;
	function scheduleClock() {
		clearTimeout( clockTimer );
		var now = new Date();
		var ms  = 60000 - ( now.getSeconds() * 1000 + now.getMilliseconds() ) + 25;
		clockTimer = setTimeout( function () { updateClock(); scheduleClock(); }, ms );
	}
	updateClock();
	scheduleClock();

	// ── Metadata ticker ───────────────────────────────────────────────────────
	// One crawling line of the current moment (serial · date · place · temp · sky)
	// beside the logo. The track holds two identical sequences and animates -50%
	// for a seamless loop. Items with no datum yet are simply omitted; the time
	// updates in place each minute (setTk) so the crawl never restarts, while a
	// resolved /now rebuilds once (renderTicker).
	var tickerTrack = document.getElementById( 'tickerTrack' );
	var TK_SPEED    = 24;                          // px/sec crawl (slow, ambient)
	var TK_ID_PRE   = 'No. ';                 // spelled out — Brandon has no № glyph
	var tkTime = '', tkDate = '', tkPlace = '', tkTemp = '', tkSky = '';
	var tkSunset   = 0;                            // Unix sunset (from /now) → golden hour
	var tkSunrise  = 0;                            // Unix sunrise (from /now) → daylight / dawn
	var tkMoon     = '';                           // moon-phase name (from /now), shown at night
	var tkLightSig = '';                           // signature of the LIGHT group's item set
	var postsToday = NOP.postsToday || 0;          // server count; bumped on each post
	var lastPostTs = NOP.lastPostTs || 0;          // Unix of the last published post
	var queueCount = 0;                            // offline posts waiting to send

	// Golden hour ≈ the last hour before sunset: counts down to it, reads "now"
	// during, then falls back to the sunset time once it's passed. (Lowercase —
	// the LIGHT heading carries the caps; the values stay quiet and poetic.)
	function tkGolden() {
		if ( ! tkSunset ) { return ''; }
		var now = Math.floor( Date.now() / 1000 ), start = tkSunset - 3600;
		if ( now < start )    { return 'Golden: ' + tkDur( start - now ); }
		if ( now < tkSunset ) { return 'Golden: now'; }
		return 'Sunset: ' + tkClock( tkSunset );
	}
	// Daylight remaining by day; the next sunrise by night — the LIGHT group's
	// anchor either side of dusk.
	function tkDaylight() {
		if ( ! tkSunset ) { return ''; }
		var now = Math.floor( Date.now() / 1000 );
		if ( tkSunrise && now >= tkSunrise && now < tkSunset ) {
			return 'Daylight: ' + tkDur( tkSunset - now );
		}
		if ( tkSunrise ) {
			var sr = now >= tkSunset ? tkSunrise + 86400 : tkSunrise;   // past sunset → ~tomorrow's
			return 'Sunrise: ' + tkClock( sr );
		}
		return '';
	}
	// Blue hour ≈ the ~25min after sunset (sun 4–8° below). Surfaces from an hour
	// before sunset as an upcoming time, then reads "now" through the window.
	function tkBlue() {
		if ( ! tkSunset ) { return ''; }
		var now = Math.floor( Date.now() / 1000 ), start = tkSunset + 10 * 60, end = tkSunset + 35 * 60;
		if ( now < tkSunset - 3600 || now >= end ) { return ''; }
		return now >= start ? 'Blue: now' : 'Blue: ' + tkClock( start );
	}
	// Pirate Weather moonPhase (0..1) → a plain phase name. Text, not a glyph:
	// keeps the riso-icon count down and never doubles the sky item's night moon.
	function moonLabel( p ) {
		if ( p <= 0.02 || p > 0.98 ) { return 'new moon'; }
		if ( p < 0.24 )  { return 'waxing crescent'; }
		if ( p <= 0.26 ) { return 'first quarter'; }
		if ( p < 0.49 )  { return 'waxing gibbous'; }
		if ( p <= 0.51 ) { return 'full moon'; }
		if ( p < 0.74 )  { return 'waning gibbous'; }
		if ( p <= 0.76 ) { return 'last quarter'; }
		return 'waning crescent';
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
	// Days to the next solstice/equinox — an occasional seasonal note, surfaced
	// only within a fortnight so it stays special. Approximate fixed dates.
	function tkSeason() {
		var now = new Date(), y = now.getFullYear();
		var marks = [ [ 2, 20, 'Spr Equinox' ], [ 5, 21, 'Sum Solstice' ], [ 8, 22, 'Aut Equinox' ], [ 11, 21, 'Win Solstice' ] ];
		var today = new Date( y, now.getMonth(), now.getDate() );
		for ( var i = 0; i < marks.length; i++ ) {
			var days = Math.round( ( new Date( y, marks[ i ][ 0 ], marks[ i ][ 1 ] ) - today ) / 86400000 );
			if ( days >= 0 && days <= 14 ) { return marks[ i ][ 2 ] + ': ' + ( days === 0 ? 'today' : days + 'd' ); }
		}
		return '';
	}
	// ISO week number + day-of-year — quiet calendar context in the NOW group.
	function tkWeek() {
		var d = new Date(), t = new Date( Date.UTC( d.getFullYear(), d.getMonth(), d.getDate() ) );
		t.setUTCDate( t.getUTCDate() + 4 - ( t.getUTCDay() || 7 ) );   // shift to the ISO week's Thursday
		return 'Wk ' + Math.ceil( ( ( t - new Date( Date.UTC( t.getUTCFullYear(), 0, 1 ) ) ) / 86400000 + 1 ) / 7 );
	}
	function tkDayOfYear() {
		var d = new Date();
		return 'Day ' + Math.round( ( Date.UTC( d.getFullYear(), d.getMonth(), d.getDate() ) - Date.UTC( d.getFullYear(), 0, 0 ) ) / 86400000 );
	}
	// The LIGHT group adapts to the time of day: golden hour always (once /now has
	// resolved), daylight-left by day or the next sunrise by night, blue hour around
	// dusk, and the moon phase after dark.
	function tkLight() {
		if ( ! tkSunset ) { return []; }
		var now = Math.floor( Date.now() / 1000 );
		var out = [ { c: 'tk-golden', h: tkGolden() } ];
		var bh  = tkBlue();
		if ( bh ) { out.push( { c: 'tk-blue', h: bh } ); }
		var dl = tkDaylight();
		if ( dl ) { out.push( { c: 'tk-daylight', h: dl } ); }
		var isNight = ! ( tkSunrise && now >= tkSunrise && now < tkSunset );
		if ( isNight && tkMoon ) { out.push( { c: 'tk-moon', h: 'Moon: ' + tkMoon } ); }
		return out;
	}
	// Four labelled clusters reading out of the logo: NOW (the moment) · HERE
	// (place + conditions) · LIGHT (the photographer's light) · LOG (the posting
	// record). Items with no datum yet drop out; an empty group is omitted whole.
	function tkGroups() {
		var nowG = [];
		if ( tkTime ) { nowG.push( { c: 'tk-time', h: tkTime } ); }
		if ( tkDate ) { nowG.push( { c: 'tk-date', h: tkDate } ); }
		nowG.push( { c: 'tk-week', h: tkWeek() } );
		nowG.push( { c: 'tk-doy', h: tkDayOfYear() } );
		var season = tkSeason();
		if ( season ) { nowG.push( { c: 'tk-season', h: season } ); }

		var here = [];
		if ( tkPlace ) { here.push( { c: 'tk-place', h: tkPlace } ); }
		if ( tkTemp )  { here.push( { c: 'tk-temp',  h: tkTemp } ); }
		if ( tkSky )   { here.push( { c: 'tk-sky',   h: tkSky } ); }

		var log = [];
		if ( ! navigator.onLine ) { log.push( { c: 'tk-offline', h: 'Offline' } ); }
		log.push( { c: 'tk-id', h: TK_ID_PRE + nextSerial } );
		if ( queueCount > 0 ) { log.push( { c: 'tk-queue', h: 'Queue: ' + queueCount } ); }
		log.push( { c: 'tk-cadence', h: tkCadence() } );
		if ( lastPostTs ) { log.push( { c: 'tk-last', h: 'Last: ' + tkAgo( lastPostTs ) } ); }

		var groups = [];
		if ( nowG.length ) { groups.push( { label: 'NOW',  items: nowG } ); }
		if ( here.length ) { groups.push( { label: 'HERE', items: here } ); }
		var light = tkLight();
		if ( light.length ) { groups.push( { label: 'LIGHT', items: light } ); }
		groups.push( { label: 'LOG', items: log } );
		return groups;
	}
	// Each group renders as: a tracked uppercase heading (the divider — no icon),
	// then its items joined by middle-dots. The face contrast (display heading vs
	// readout data) and the dot/space hierarchy do the structural work.
	function tkSeqHTML() {
		return tkGroups().map( function ( g ) {
			var items = g.items.map( function ( it, i ) {
				return ( i ? '<span class="ticker__sep" aria-hidden="true">·</span>' : '' )
					+ '<span class="ticker__item ' + it.c + '"><span class="ticker__val">' + it.h + '</span></span>';
			} ).join( '' );
			return '<span class="ticker__group"><span class="ticker__head">' + g.label + '</span>' + items + '</span>';
		} ).join( '' );
	}
	function renderTicker() {
		if ( ! tickerTrack ) { return; }
		var seq = tkSeqHTML();
		tickerTrack.innerHTML = '<span class="ticker__seq">' + seq + '</span>'
			+ '<span class="ticker__seq">' + seq + '</span>';
		tkSeqW = tickerTrack.firstChild.getBoundingClientRect().width;   // one loop = one sequence
		tkLightSig = tkLight().map( function ( it ) { return it.c; } ).join();   // baseline for refreshLight
		startTickerMotion();
	}
	// Per-minute LIGHT upkeep: update the live readouts in place (golden countdown,
	// daylight, blue hour) so the crawl never restarts; but when the set of items
	// changes (day→dusk→night) rebuild the track once so items can come and go.
	function refreshLight() {
		if ( tkLight().map( function ( it ) { return it.c; } ).join() !== tkLightSig ) { renderTicker(); return; }
		setTk( 'tk-golden', tkGolden() );
		setTk( 'tk-daylight', tkDaylight() );
		setTk( 'tk-blue', tkBlue() );
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
	// Hybrid crawl: CSS @keyframes ticker-crawl owns the AMBIENT crawl (no rAF at
	// rest, the win), JS takes over during a finger scrub and the post-flick decay,
	// then hands back to CSS once the friction-eased velocity settles within ε of
	// the ambient speed. Both speeds are matched because JS sets --ticker-dur =
	// tkSeqW / TK_SPEED, so the handoff doesn't change crawl speed. Under
	// prefers-reduced-motion we leave the (disabled) CSS animation alone and never
	// attach scrub listeners.
	var tkSeqW    = 0;                 // px width of one sequence — the wrap point
	var tkOffset  = 0;                 // px scrolled left out of view; wraps at tkSeqW
	var tkVel     = TK_SPEED;          // current px/sec; eases toward TK_SPEED when idle
	var TK_TAU    = 0.45;              // s — friction time constant (≈1.5s to settle)
	var TK_VMAX   = 1800;              // px/sec flick clamp
	var TK_EPS    = 0.5;               // px/sec — settle window for the JS→CSS handoff
	var tkRAF     = 0, tkLastFrame = 0, tkDragging = false;
	var tkDragX   = 0, tkDragOff = 0, tkMoveX = 0, tkMoveT = 0;
	var tkReduce  = prefersReduce.matches;
	var tkCssDriven = false;            // true while CSS owns the crawl (rAF idle)

	// Read the CSS animation's current translateX so JS can pick up the same offset.
	function tkReadCssOffset() {
		if ( ! tickerTrack ) { return 0; }
		var m = getComputedStyle( tickerTrack ).transform;
		if ( ! m || m === 'none' ) { return 0; }
		try {
			var off = -( new DOMMatrixReadOnly( m ) ).m41;   // CSS translateX is negative; store positive
			if ( tkSeqW > 0 ) { off = ( ( off % tkSeqW ) + tkSeqW ) % tkSeqW; }
			return off;
		} catch ( e ) { return 0; }
	}
	// Snapshot the live offset off the CSS animation, then KILL the animation so
	// JS-written transform actually takes effect. CSS animations live in their own
	// cascade origin ABOVE inline styles — pausing isn't enough (the paused frame
	// still owns the property). Setting animation-name: none releases it, while
	// leaving animation-delay / --ticker-dur set inline for the hand-back.
	function takeOverFromCSS() {
		if ( ! tickerTrack ) { return; }
		tkOffset = tkReadCssOffset();
		// Preempt the next paint with the same offset, so the kill+rAF gap can't flash.
		tickerTrack.style.transform     = 'translateX(' + ( -tkOffset ).toFixed( 2 ) + 'px)';
		tickerTrack.style.animationName = 'none';
		tkCssDriven = false;
		tkLastFrame = 0;
		if ( ! tkRAF ) { tkRAF = requestAnimationFrame( tkFrame ); }
	}
	// Hand the crawl back: seek the CSS animation to the current pixel offset via
	// a negative animation-delay, then clear the inline animation-name so the CSS
	// rule's `ticker-crawl` re-asserts and takes over. Because
	// --ticker-dur = tkSeqW / TK_SPEED, delay = -tkOffset / TK_SPEED lands the CSS
	// phase exactly on tkOffset — no visual jump.
	function handBackToCSS() {
		if ( ! tickerTrack || tkSeqW <= 0 ) { return; }
		tickerTrack.style.animationDelay = ( -( tkOffset / TK_SPEED ) ) + 's';
		tickerTrack.style.animationName  = '';      // clear inline → CSS rule's name applies
		tickerTrack.style.transform      = '';      // CSS animation now owns transform
		tkCssDriven = true;
		cancelAnimationFrame( tkRAF );
		tkRAF = 0;
		tkVel = TK_SPEED;
	}

	function tkFrame( t ) {
		var dt = tkLastFrame ? ( t - tkLastFrame ) / 1000 : 0;
		tkLastFrame = t;
		if ( dt > 0.05 ) { dt = 0.05; }                                 // clamp tab-switch gaps
		if ( ! tkDragging ) {
			tkVel = TK_SPEED + ( tkVel - TK_SPEED ) * Math.exp( -dt / TK_TAU );   // ease to ambient
			tkOffset += tkVel * dt;
			// Settled within ε of ambient — hand the crawl back to CSS and stop rAF.
			if ( Math.abs( tkVel - TK_SPEED ) < TK_EPS ) {
				if ( tkSeqW > 0 ) { tkOffset = ( ( tkOffset % tkSeqW ) + tkSeqW ) % tkSeqW; }
				handBackToCSS();
				return;
			}
		}
		if ( tkSeqW > 0 ) {
			tkOffset %= tkSeqW;
			if ( tkOffset < 0 ) { tkOffset += tkSeqW; }
		}
		tickerTrack.style.transform = 'translateX(' + ( -tkOffset ).toFixed( 2 ) + 'px)';
		tkRAF = requestAnimationFrame( tkFrame );
	}
	function startTickerMotion() {
		if ( tkReduce || ! tickerTrack ) { return; }                   // CSS rule disables animation
		if ( tkSeqW > 0 ) {
			tickerTrack.style.setProperty( '--ticker-dur', ( tkSeqW / TK_SPEED ) + 's' );
		}
		// Mid-scrub or mid-flick: keep rAF in control by killing the CSS animation
		// outright (animation-name: none releases the cascade so JS transform wins).
		if ( tkDragging || ( tkRAF && Math.abs( tkVel - TK_SPEED ) >= TK_EPS ) ) {
			tickerTrack.style.animationName = 'none';
			tkCssDriven = false;
			if ( ! tkRAF ) { tkLastFrame = 0; tkRAF = requestAnimationFrame( tkFrame ); }
		} else {
			handBackToCSS();
		}
	}
	function tkDown( x ) {
		tkDragging = true;
		// Coming off the ambient CSS crawl — snapshot the live phase before pausing it.
		if ( tkCssDriven ) { takeOverFromCSS(); }
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

	// The current place, captured from the same /now resolution the ticker uses, so
	// the opt-in geotag can attach it without a second lookup. Populated as coords
	// (GEO_KEY) and the reverse-geocode (renderNow) resolve.
	var geoLat = null, geoLon = null, geoLocality = '', geoCountry = '';

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
		geoLocality = d.place || '';
		geoCountry  = d.country || '';
		updateLocationUI();   // fill the geotag readout if the toggle is on
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
		tkSunset  = d.sunset  ? Number( d.sunset )  : 0;   // golden hour / daylight derive from this
		tkSunrise = d.sunrise ? Number( d.sunrise ) : 0;   // daylight-left + the dawn readout
		tkMoon    = ( d.moonphase != null && d.moonphase !== '' ) ? moonLabel( Number( d.moonphase ) ) : '';
		renderTicker();   // rebuild once now that place/temp/sky/sun/moon have resolved
	}

	function fetchNow( lat, lon ) {
		geoLat = lat; geoLon = lon;
		var sep = NOP.nowUrl.indexOf( '?' ) >= 0 ? '&' : '?';
		var opts = { headers: { 'X-WP-Nonce': NOP.nonce } };
		// Bound the request — a hung network would otherwise leave the moment data
		// pending forever; a timeout aborts into the same silent catch as any failure.
		if ( AbortSignal.timeout ) { opts.signal = AbortSignal.timeout( 8000 ); }
		fetch( NOP.nowUrl + sep + 'lat=' + encodeURIComponent( lat ) + '&lon=' + encodeURIComponent( lon ), opts )
			.then( function ( r ) {
				if ( ! r.ok ) { console.warn( '[nop /now] HTTP', r.status, r.statusText ); return null; }
				return r.json();
			} )
			.then( function ( d ) {
				if ( ! d ) { return; }
				// Surface "endpoint OK but no useful data" — usually a server-side key
				// (Pirate Weather / Geoapify) or a transient provider failure.
				if ( ! d.place && d.temp_c == null && ! d.summary ) {
					console.warn( '[nop /now] response was empty', d );
				}
				renderNow( d );
				writeJSON( NOW_KEY, { data: d, ts: Date.now() } );
			} )
			.catch( function ( e ) { console.warn( '[nop /now] network error', e ); } );
	}

	function withCoords( cb ) {
		var g = readJSON( GEO_KEY );
		if ( g && g.lat != null && ( Date.now() - g.ts ) < GEO_TTL ) { cb( g.lat, g.lon ); return; }
		if ( ! navigator.geolocation ) { console.warn( '[nop /now] no Geolocation API' ); return; }
		navigator.geolocation.getCurrentPosition(
			function ( pos ) {
				var lat = pos.coords.latitude, lon = pos.coords.longitude;
				writeJSON( GEO_KEY, { lat: lat, lon: lon, ts: Date.now() } );
				cb( lat, lon );
			},
			function ( err ) {
				// PERMISSION_DENIED=1 / POSITION_UNAVAILABLE=2 / TIMEOUT=3.
				console.warn( '[nop /now] geolocation error', err && err.code, err && err.message );
			},
			{ enableHighAccuracy: false, timeout: 8000, maximumAge: GEO_TTL }
		);
	}

	// "Useful" = the payload has at least one of the three HERE fields populated.
	// An all-empty response (Pirate Weather quota, Geoapify timeout, etc.) used to
	// poison the cache for the full 30-min TTL — the early-return would pin no-data
	// and never re-fetch, leaving no console breadcrumb. Treat empty as no-cache.
	function isUsefulNow( d ) {
		return !! ( d && ( d.place || d.temp_c != null || d.summary ) );
	}
	function loadNow() {
		var cached = readJSON( NOW_KEY );
		var geo    = readJSON( GEO_KEY );
		if ( geo && geo.lat != null ) { geoLat = geo.lat; geoLon = geo.lon; }   // seed coords for the geotag
		var useful = cached && isUsefulNow( cached.data );
		console.info( '[nop /now] startup', {
			nowAgeMin: cached ? Math.round( ( Date.now() - cached.ts ) / 60000 ) : 'no-cache',
			nowUseful: useful,
			geoAgeMin: geo ? Math.round( ( Date.now() - geo.ts ) / 60000 ) : 'no-cache',
		} );
		if ( useful ) { renderNow( cached.data ); }                                          // paint instantly
		if ( useful && ( Date.now() - cached.ts ) < NOW_TTL ) { return; }                    // still fresh
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
		if ( document.visibilityState === 'visible' ) { loadNow(); updateClock(); scheduleClock(); }
	} );
	window.addEventListener( 'pageshow', loadNow );

	// ── Type configuration ────────────────────────────────────────────────────

	var TYPE_CONFIG = {
		note:     { urlProp: null,           hasContent: true,  hasTags: true,  hasPhoto: true, hasLocation: true, contentPlaceholder: 'Write a note…' },
		photo:    { urlProp: null,           hasContent: true,  hasTags: true,  hasPhoto: true, hasLocation: true, contentPlaceholder: 'What are we looking at?' },
		reply:    { urlProp: 'in-reply-to',  hasContent: true,  hasTags: false, hasPhoto: true, urlLabel: 'In reply to', contentPlaceholder: 'Say your piece…' },
		like:     { urlProp: 'like-of',      hasContent: false, hasTags: false, urlLabel: 'Liking', urlHint: "Paste the URL you're liking" },
		bookmark: { urlProp: 'bookmark-of',  hasContent: true,  hasTags: false, urlLabel: 'Bookmarking', contentPlaceholder: 'A note to future you…' },
		repost:   { urlProp: 'repost-of',    hasContent: false, hasTags: false, urlLabel: 'Reposting', urlHint: "Paste the URL you're reposting" },
		rsvp:     { urlProp: 'in-reply-to',  hasContent: true,  hasTags: false, urlLabel: 'Event', contentPlaceholder: 'Add a word (optional)…', hasRsvp: true },
	};

	var currentType   = 'note';
	var selectedFiles = [];
	var photoAlts     = [];
	var currentTags   = [];
	var currentRsvp   = 'yes';
	var includeLocation = false;   // opt-in geotag for note/photo

	// ── DOM refs ──────────────────────────────────────────────────────────────

	var postBtn      = document.getElementById( 'postBtn' );
	var fieldUrl     = document.getElementById( 'fieldUrl' );
	var fieldRsvp    = document.getElementById( 'fieldRsvp' );
	var fieldPhoto   = document.getElementById( 'fieldPhoto' );
	var fieldContent = document.getElementById( 'fieldContent' );
	var fieldTags    = document.getElementById( 'fieldTags' );
	var urlInput     = document.getElementById( 'typeUrl' );
	var contentInput  = document.getElementById( 'content' );
	var composePrompt = document.getElementById( 'composePrompt' );
	var picker       = document.getElementById( 'photoPicker' );
	var docket       = document.getElementById( 'docket' );
	var docketKind   = document.getElementById( 'docketKind' );
	var docketSerial = document.getElementById( 'docketSerial' );
	var docketDate   = document.getElementById( 'docketDate' );
	var clearBtn     = document.getElementById( 'clearBtn' );
	var slipRef      = document.getElementById( 'slipRef' );
	var slipHost     = document.getElementById( 'slipHost' );
	var slipPath     = document.getElementById( 'slipPath' );
	var slipTitle    = document.getElementById( 'slipTitle' );
	var slipExcerpt  = document.getElementById( 'slipExcerpt' );
	var fieldEvent      = document.getElementById( 'fieldEvent' );
	var eventName       = document.getElementById( 'eventName' );
	var eventStartDate  = document.getElementById( 'eventStartDate' );
	var eventStartTime  = document.getElementById( 'eventStartTime' );
	var eventLocation   = document.getElementById( 'eventLocation' );
	var eventImage      = document.getElementById( 'eventImage' );
	var eventPoster     = document.getElementById( 'eventPoster' );
	var eventPosterImg  = document.getElementById( 'eventPosterImg' );
	var eventPosterRm   = document.getElementById( 'eventPosterRemove' );
	var eventStatus     = document.getElementById( 'eventStatus' );
	var fieldLocation   = document.getElementById( 'fieldLocation' );
	var locationCheck   = document.getElementById( 'locationCheck' );
	var locationPlace   = document.getElementById( 'locationPlace' );

	// Apply (or clear) the hot-linked event poster: hidden input carries the URL
	// through to the Micropub payload, the <figure> is just author confirmation
	// ("yep, that's the show"). Empty/missing URL hides the figure.
	function setEventPoster( url ) {
		url = ( url || '' ).trim();
		if ( eventImage ) { eventImage.value = url; }
		if ( ! eventPoster || ! eventPosterImg ) { return; }
		if ( url ) {
			eventPosterImg.src = url;
			eventPoster.hidden = false;
		} else {
			eventPosterImg.removeAttribute( 'src' );
			eventPoster.hidden = true;
		}
	}
	if ( eventPosterRm ) {
		eventPosterRm.addEventListener( 'click', function () {
			setEventPoster( '' );
			saveDraft();
		} );
	}

	// The event start rides as a single ISO string ("YYYY-MM-DD" when only the
	// date is known, "YYYY-MM-DDTHH:MM" when a time was set, blank when unknown).
	// These helpers join/split that string against the two inputs so the draft
	// and Micropub payload shapes stay simple while the form represents
	// date-only honestly. Only dt-start is captured — an RSVP records the single
	// day the author is attending, not the event's full run.
	function joinEventDt( dateEl, timeEl ) {
		var d = dateEl ? dateEl.value.trim() : '';
		var t = timeEl ? timeEl.value.trim() : '';
		if ( ! d ) { return ''; }
		return t ? d + 'T' + t : d;
	}
	function splitEventDt( dateEl, timeEl, value ) {
		value = ( value || '' ).trim();
		var m = value.match( /^(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2}))?/ );
		if ( dateEl ) { dateEl.value = m ? m[1] : ''; }
		if ( timeEl ) { timeEl.value = ( m && m[2] ) ? m[2] : ''; }
	}
	// The event URL the detail fetch last ran against — so an unchanged URL
	// (re-blur, keystroke after the value settled) doesn't re-hit the endpoint.
	var fetchedEventUrl = '';

	// The docket's printed filing line — kind · serial · date — re-stamped on each
	// kind switch (and after a post, when the serial bumps).
	function fillDocketHeader( type ) {
		var lbl = document.querySelector( '.type-btn[data-type="' + type + '"] .type-btn__label' );
		if ( docketKind )   { docketKind.textContent = lbl ? lbl.textContent : type; }
		if ( docketSerial ) { docketSerial.textContent = TK_ID_PRE + nextSerial; }
		if ( docketDate )   { var n = new Date(), p = function ( v ) { return ( v < 10 ? '0' : '' ) + v; }; docketDate.textContent = p( n.getDate() ) + '/' + p( n.getMonth() + 1 ) + '/' + n.getFullYear(); }
	}

	// Big rotating prompt overlay — set its text, and fade it once typing starts.
	function setPrompt( text ) { composePrompt.textContent = text; syncPrompt(); }
	function syncPrompt() { composePrompt.classList.toggle( 'is-hidden', contentInput.value.length > 0 ); }
	// The note placeholder — one of a rotating set of openers.
	function notePlaceholder() { return notePrompt; }
	// Fallback for browsers without CSS field-sizing (Firefox today). Native
	// field-sizing: content on .compose-field handles the rest for free.
	var hasFieldSizing    = window.CSS && CSS.supports && CSS.supports( 'field-sizing', 'content' );
	// Scroll-driven CSS handles --reveal natively on the four fade overlays where
	// supported (Chrome/Edge ship, Safari 26+, Firefox pending) — the JS writers
	// below no-op out so we skip a per-scroll var write everywhere supported.
	var hasScrollTimeline = window.CSS && CSS.supports && CSS.supports( 'animation-timeline', 'scroll()' );
	function autoGrowContent() {
		if ( ! hasFieldSizing ) {
			contentInput.style.height = 'auto';
			contentInput.style.height = contentInput.scrollHeight + 'px';
		}
		updateScrollFades();
	}
	var composeScroll = document.querySelector( '.compose-scroll' );
	var fadeTop       = document.querySelector( '.scroll-fade-top' );
	var fadeBottom    = document.querySelector( '.scroll-fade-bottom' );
	// --reveal (0..1) drives the mask's ALPHA only — the shadow is a fixed-depth
	// layer cast at the seam (its mask stops sit at constant px, see style.scss),
	// so it fades in over RAMP as you scroll and holds; it never deepens with the
	// content's length. RAMP is the fade-in distance, not the shadow's pixel depth.
	function updateScrollFades() {
		if ( hasScrollTimeline || ! composeScroll ) return;
		var RAMP  = 240;
		var top   = composeScroll.scrollTop;
		var below = composeScroll.scrollHeight - composeScroll.clientHeight - top;
		if ( fadeTop )    fadeTop.style.setProperty(    '--reveal', Math.min( Math.max( top,   0 ) / RAMP, 1 ) );
		if ( fadeBottom ) fadeBottom.style.setProperty( '--reveal', Math.min( Math.max( below, 0 ) / RAMP, 1 ) );
	}
	// Vertical clip of the kind-strip edge-shadows. The box is anchored at the strip's
	// resting top and runs --strip-extra px past it; --vclip hides that tail from the
	// bottom. One formula spans both directions: hidden = extra + scrollTop, clamped.
	// At rest (0) it clips the whole tail → only the strip band shows; scrolling DOWN
	// adds to it (the strip leaves under the masthead); an overscroll pull-down makes
	// scrollTop negative → clips LESS → the tail's tiled dots fill the opening gap.
	// Writing a clip — never background-position — can't swim.
	var stripExtra = 600, stripH = 0, lastVclip = '';
	function updateTypeClip() {
		if ( ! composeScroll || ( ! typeShadowLeft && ! typeShadowRight ) ) { return; }
		var hidden = Math.max( 0, Math.min( stripExtra + composeScroll.scrollTop, stripH + stripExtra ) );
		var v = hidden.toFixed( 2 ) + 'px';
		// Skip the write when the clip hasn't moved. The strip is ~one band tall, so
		// past it --vclip sits clamped at its max for the rest of a long scroll — this
		// drops every one of those redundant style writes (the bulk of the scroll).
		if ( v === lastVclip ) { return; }
		lastVclip = v;
		if ( typeShadowLeft )  { typeShadowLeft.style.setProperty(  '--vclip', v ); }
		if ( typeShadowRight ) { typeShadowRight.style.setProperty( '--vclip', v ); }
	}
	// Coalesce scroll events into one paint-aligned write: the rAF callback reads
	// scrollTop at paint time, so the clip uses the freshest position the frame can
	// have — closing the lag that flashed the tail over the docket on a hard fling,
	// without a scroll-timeline. Init/resize call updateTypeClip() directly.
	var clipRAF = 0;
	function scheduleTypeClip() {
		if ( clipRAF ) { return; }
		clipRAF = requestAnimationFrame( function () { clipRAF = 0; updateTypeClip(); } );
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
	// Page grain alignment — lock every dot layer's background-position to one
	// viewport-origin grid (iOS Safari has no working background-attachment:fixed,
	// so we phase-lock by JS). The scroll-fades are sticky — pinned to the scroller's
	// edges — so their lock comes from those STABLE edges and never needs a per-scroll
	// recompute (which is what desynced on iOS momentum scroll). The kind-strip
	// edge-shadows now live in #view-compose (which never scrolls), so they lock the
	// same way — once, from a box that never moves.
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
		// Shadow overlays — same grid (replaces background-attachment:fixed). The
		// scroll-fades stick to the scroller's top/bottom, so lock from those edges.
		var sr = composeScroll.getBoundingClientRect();
		if ( fadeTop )    { fadeTop.style.backgroundPosition    = lockXY( sr.left, sr.top, pitch ); }
		if ( fadeBottom ) { fadeBottom.style.backgroundPosition = lockXY( sr.left, sr.bottom - fadeBottom.offsetHeight, pitch ); }
		// The kind-strip edge-shadows live in #view-compose (which never scrolls), not
		// in the strip, so their dots lock to the universal grid ONCE and never swim.
		// Measure the strip's resting band so they sit exactly over it, then phase-lock
		// their dots — both stay valid for every scroll, with no per-frame work.
		if ( viewCompose && typeGridWrap ) {
			var vr = viewCompose.getBoundingClientRect();
			var wr = typeGridWrap.getBoundingClientRect();
			stripH     = wr.height;
			stripExtra = Math.round( window.innerHeight );   // tail long enough to outrun any rubber-band
			viewCompose.style.setProperty( '--strip-top',   ( wr.top - vr.top ).toFixed( 2 ) + 'px' );
			viewCompose.style.setProperty( '--strip-h',     stripH.toFixed( 2 ) + 'px' );
			viewCompose.style.setProperty( '--strip-extra', stripExtra + 'px' );
			updateTypeClip();                                // re-clip to the freshly measured band
		}
		lockEl( typeShadowLeft, pitch );
		lockEl( typeShadowRight, pitch );
	}
	if ( ! hasScrollTimeline ) {
		composeScroll.addEventListener( 'scroll', updateScrollFades, { passive: true } );
	}
	// --vclip is JS-driven for everyone (overscroll is outside any scroll-timeline),
	// so this listener is always on — unlike updateScrollFades, which is the fallback.
	// rAF-coalesced so the write lands in step with paint.
	composeScroll.addEventListener( 'scroll', scheduleTypeClip, { passive: true } );

	// Horizontal scroll-fades for the kind row — same ramp-by-distance as the
	// vertical ones, so the left/right halftone edges fade in by how far there is
	// left to scroll. The right one is visible on load (Repost/RSVP sit off-screen).
	var typeBar         = document.getElementById( 'typeBar' );
	var typeGridWrap    = document.querySelector( '.type-grid-wrap' );
	var viewCompose     = document.getElementById( 'view-compose' );
	var typeShadowLeft  = document.querySelector( '.type-shadow-left' );
	var typeShadowRight = document.querySelector( '.type-shadow-right' );
	function updateTypeFades() {
		if ( hasScrollTimeline || ! typeBar ) { return; }
		// Matches the vertical fade's RAMP so growth-per-finger-pixel is the
		// same on both axes — the shadow rate feels consistent whether you're
		// scrolling the compose body or the kind strip.
		var RAMP  = 240;
		var left  = typeBar.scrollLeft;
		var right = typeBar.scrollWidth - typeBar.clientWidth - left;
		if ( typeShadowLeft )  { typeShadowLeft.style.setProperty(  '--reveal', Math.min( Math.max( left,  0 ) / RAMP, 1 ) ); }
		if ( typeShadowRight ) { typeShadowRight.style.setProperty( '--reveal', Math.min( Math.max( right, 0 ) / RAMP, 1 ) ); }
	}
	if ( ! hasScrollTimeline ) {
		typeBar.addEventListener( 'scroll', updateTypeFades, { passive: true } );
	}

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
	var specimenTitle = document.getElementById( 'specimenTitle' );

	// URL specimen — on URL-only kinds the empty space below the field shows the
	// kind glyph as a watermark; once the URL parses it becomes a type specimen
	// of the target's hostname, confirming what you're about to act on.
	// Parses the URL field and drives the two URL readouts: the big watermark
	// specimen on URL-only kinds (like/repost), and the printed reference slip on
	// kinds that also write (reply/bookmark/rsvp). Forward-compat: the slip's
	// title/excerpt slots stay hidden until a future server fetch fills them.
	function updateSpecimen() {
		var cfg          = TYPE_CONFIG[ currentType ];
		var specimenKind = !! cfg.urlProp && ! cfg.hasContent;   // like, repost
		var slipKind     = !! cfg.urlProp && cfg.hasContent;     // reply, bookmark, rsvp

		specimen.hidden = ! specimenKind;
		if ( slipRef ) { slipRef.hidden = true; }
		if ( ! cfg.urlProp ) { return; }                         // note/photo — neither

		var parsed = null;
		var raw    = urlInput.value.trim();
		if ( raw ) {
			try { parsed = new URL( raw ); } catch ( e ) {}
		}
		var filled = !! ( parsed && parsed.hostname );
		var host   = filled ? parsed.hostname.replace( /^www\./, '' ) : '';
		var path   = filled ? ( parsed.pathname + parsed.search ) : '';

		// Reply / bookmark / rsvp — the printed reference line on the docket.
		if ( slipKind ) {
			if ( slipRef ) {
				slipRef.hidden = ! filled;
				if ( filled ) {
					slipHost.textContent = host;
					slipPath.textContent = ( path === '/' ) ? '' : path;
				}
			}
			return;
		}

		// Like / repost — the big watermark specimen.
		specimenGlyph.hidden = filled;
		specimenHint.hidden  = filled;
		specimenHost.hidden  = ! filled;
		if ( filled ) {
			specimenHost.textContent = host;
			specimenPath.textContent = path;
			specimenPath.hidden = path === '/';
		} else {
			specimenPath.hidden = true;
			if ( specimenTitle ) { specimenTitle.hidden = true; }
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

	// ── RSVP event lookup ───────────────────────────────────────────────────────
	// Pasting the event URL on an RSVP fetches the event's name / start / end /
	// location from the page (mf2 h-event → JSON-LD → OG → <title>) and fills the
	// editable fields. Best-effort: a miss just shows the manual-entry hint.
	//
	// The status line names the source that matched so the author knows whether
	// they're trusting rich structured data (mf2 / JSON-LD) or a thin fallback
	// (just the page title — usually a sign the URL was an index/listing page).

	var EVENT_SOURCE_LABEL = {
		mf2:       'microformats h-event',
		jsonld:    'schema.org/Event',
		opengraph: 'Open Graph',
		title:     'page title',
	};

	function setEventStatus( state, source ) {
		if ( ! eventStatus ) { return; }
		var msg = '';
		if ( state === 'loading' ) {
			msg = 'Fetching event details…';
		} else if ( state === 'found' ) {
			var label = EVENT_SOURCE_LABEL[ source ];
			msg = label ? ( 'Found via ' + label + '.' ) : 'Found event details.';
		} else if ( state === 'thin' ) {
			msg = 'Only the page title was readable — please fill in the details.';
		} else if ( state === 'empty' || state === 'error' ) {
			msg = 'Couldn’t find event data — please fill in manually.';
		}
		eventStatus.textContent = msg;
		eventStatus.hidden      = ! msg;
		eventStatus.className   = 'event-status' + ( state ? ' is-' + state : '' );
	}

	function fetchEvent( url ) {
		url = ( url || '' ).trim();
		if ( currentType !== 'rsvp' || ! url || url === fetchedEventUrl || ! NOP.fetchEventUrl ) { return; }
		try { new URL( url ); } catch ( e ) { return; }
		fetchedEventUrl = url;
		setEventStatus( 'loading' );

		fetch( NOP.fetchEventUrl, {
			method:  'POST',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			body:    JSON.stringify( { url: url } ),
		} ).then( function ( r ) {
			return r.ok ? r.json() : null;
		} ).then( function ( d ) {
			if ( ! d || ! d.source ) { setEventStatus( 'empty' ); return; }
			// Fill from the fetch; anything it returns wins (the author can still
			// edit), anything it misses is left for manual entry. start/end may be
			// date-only — splitEventDt fills the date and leaves the time blank in
			// that case, rather than inventing midnight.
			if ( d.name )     { eventName.value     = d.name; }
			if ( d.start )    { splitEventDt( eventStartDate, eventStartTime, d.start ); }
			if ( d.location ) { eventLocation.value = d.location; }
			if ( d.image )    { setEventPoster( d.image ); }
			// "Thin" = only a name came back (no date and no location). Usually a
			// page-title fallback or a venue/listings page that exposes an og:title
			// but nothing else useful. Flag it so the author knows to step in.
			var thin = ! d.start && ! d.location;
			setEventStatus( thin ? 'thin' : 'found', d.source );
			saveDraft();
		} ).catch( function () {
			setEventStatus( 'error' );
		} );
	}

	var fetchEventSoon = debounce( function () { fetchEvent( urlInput.value ); }, 600 );

	// ── URL context preview ───────────────────────────────────────────────────────
	// reply / like / bookmark / repost all act on a URL. Fetch a lightweight
	// preview of the target (title / author / excerpt) so you see WHAT you're acting
	// on rather than a bare hostname. Best-effort: a miss leaves the host/path slip
	// as-is and stays quiet. RSVP has its own richer event lookup, so it's excluded.

	var fetchedContextUrl = '';

	// True for the kinds that should preview their target: any URL kind except RSVP.
	function isContextKind() {
		var cfg = TYPE_CONFIG[ currentType ];
		return !! cfg.urlProp && currentType !== 'rsvp';
	}

	function clearContextPreview() {
		if ( slipTitle )     { slipTitle.hidden     = true; slipTitle.textContent     = ''; }
		if ( slipExcerpt )   { slipExcerpt.hidden   = true; slipExcerpt.textContent   = ''; }
		if ( specimenTitle ) { specimenTitle.hidden = true; specimenTitle.textContent = ''; }
	}

	function applyContextPreview( d ) {
		if ( ! d || ! d.source ) { return; }
		var title   = ( d.title   || '' ).trim();
		var excerpt = ( d.excerpt || '' ).trim();
		var author  = ( d.author  || '' ).trim();
		// reply / bookmark write alongside the link → fill the printed slip's
		// title + excerpt slots. like / repost are URL-only → show the title under
		// the big hostname specimen.
		if ( TYPE_CONFIG[ currentType ].hasContent ) {
			if ( slipTitle )   { slipTitle.textContent   = title;   slipTitle.hidden   = ! title; }
			if ( slipExcerpt ) { slipExcerpt.textContent = excerpt; slipExcerpt.hidden = ! excerpt; }
		} else if ( specimenTitle ) {
			var headline = title || author;
			specimenTitle.textContent = headline;
			specimenTitle.hidden      = ! headline;
		}
	}

	function fetchContext( url ) {
		url = ( url || '' ).trim();
		if ( ! isContextKind() || ! url || url === fetchedContextUrl || ! NOP.fetchContextUrl ) { return; }
		try { new URL( url ); } catch ( e ) { return; }
		fetchedContextUrl = url;

		fetch( NOP.fetchContextUrl, {
			method:  'POST',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			body:    JSON.stringify( { url: url } ),
		} ).then( function ( r ) {
			return r.ok ? r.json() : null;
		} ).then( function ( d ) {
			// Drop a stale response if the field changed (or the kind switched) while
			// the request was in flight.
			if ( urlInput.value.trim() !== url || ! isContextKind() ) { return; }
			applyContextPreview( d );
		} ).catch( function () {} );
	}

	var fetchContextSoon = debounce( function () { fetchContext( urlInput.value ); }, 600 );

	// ── Location (opt-in geotag) ──────────────────────────────────────────────────
	// note / photo can carry the device's current place. Off by default; turning it
	// on attaches the coordinates + the reverse-geocoded place the ticker already
	// resolved. Coordinates are only requested when you ask for them here, so a plain
	// post never touches geolocation.

	// "Belfast, United Kingdom" from the resolved parts, or '' when nothing's known.
	function locationLabel() {
		var parts = [];
		if ( geoLocality ) { parts.push( geoLocality ); }
		if ( geoCountry )  { parts.push( geoCountry ); }
		return parts.join( ', ' );
	}

	// Reflect the toggle + resolved place into the readout. When on but the place
	// hasn't resolved yet, show a quiet "Locating…" rather than an empty chip.
	function updateLocationUI() {
		if ( ! locationPlace ) { return; }
		if ( ! includeLocation ) {
			locationPlace.hidden = true;
			locationPlace.textContent = '';
			return;
		}
		var label = locationLabel();
		locationPlace.hidden = false;
		locationPlace.textContent = label || ( geoLat != null ? '…' : 'Locating…' );
	}

	if ( locationCheck ) {
		locationCheck.addEventListener( 'change', function () {
			includeLocation = locationCheck.checked;
			if ( navigator.vibrate ) { navigator.vibrate( 8 ); }
			// First opt-in with no coords yet → ask for them now (may prompt once).
			if ( includeLocation && geoLat == null ) { loadNow(); }
			updateLocationUI();
			saveDraft();
		} );
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
				+ '<span class="syndicator-box" aria-hidden="true"><svg width="12" height="12" aria-hidden="true" focusable="false"><use href="#nop-check"/></svg></span>'
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
	var quickTags = document.getElementById( 'quickTags' );

	tagsField.addEventListener( 'click', function () { tagInput.focus(); } );

	// Most-used tag chips: revealed only while the tag field is in use, so they
	// cost no space at rest. Tap to add, tap again to remove; stays in sync with
	// the tag list however a tag was added/removed (see renderTags below).
	if ( quickTags ) {
		var quickTagsTimer;
		tagInput.addEventListener( 'focus', function () {
			clearTimeout( quickTagsTimer );
			quickTags.classList.add( 'is-open' );
		} );
		tagInput.addEventListener( 'blur', function () {
			// Delay the fold so a chip tap (which momentarily blurs the input)
			// doesn't collapse the panel mid-interaction — the click handler
			// re-focuses the input, cancelling this.
			quickTagsTimer = setTimeout( function () { quickTags.classList.remove( 'is-open' ); }, 200 );
		} );
		quickTags.addEventListener( 'click', function (e) {
			var btn = e.target.closest( '.quick-tag' );
			if ( ! btn ) return;
			var tag = btn.dataset.tag;
			var idx = currentTags.indexOf( tag );
			if ( idx === -1 ) { currentTags.push( tag ); } else { currentTags.splice( idx, 1 ); }
			renderTags();
			saveDraft();
			tagInput.focus(); // keep the panel open + keyboard up for more taps
		} );
	}

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
				+ '<button class="tag-chip__remove" type="button" data-index="' + i + '" aria-label="Remove ' + escAttr( tag ) + '"><svg width="11" height="11" aria-hidden="true" focusable="false"><use href="#nop-x"/></svg></button>'
				+ '</span>';
		} ).join( '' );
		if ( quickTags ) {
			quickTags.querySelectorAll( '.quick-tag' ).forEach( function (b) {
				b.classList.toggle( 'is-used', currentTags.indexOf( b.dataset.tag ) !== -1 );
			} );
		}
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

		app.dataset.type = type;
		setInk( type );

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
		if ( fieldEvent ) { fieldEvent.hidden = ! cfg.hasRsvp; }
		fieldPhoto.hidden   = ! cfg.hasPhoto;
		fieldContent.hidden = ! cfg.hasContent;
		fieldTags.hidden    = ! cfg.hasTags;
		if ( fieldLocation ) { fieldLocation.hidden = ! cfg.hasLocation; }

		// Note = a body-only card (the pure ruled writing page); every other kind
		// carries a printed-fields region.
		if ( docket ) { docket.classList.toggle( 'docket--body-only', ! cfg.urlProp && ! cfg.hasPhoto && ! cfg.hasRsvp ); }
		fillDocketHeader( type );

		if ( cfg.urlProp ) urlInput.setAttribute( 'aria-label', cfg.urlLabel || 'URL' );
		if ( cfg.hasContent ) {
			setPrompt( ( type === 'note' ) ? notePlaceholder() : ( cfg.contentPlaceholder || 'Write…' ) );
		}

		updateSpecimen();
		// Reset any previewed target, then re-preview if this kind already carries a
		// URL (a draft restore, or switching reply→bookmark with the link kept).
		clearContextPreview();
		fetchedContextUrl = '';
		if ( isContextKind() && urlInput.value.trim() ) { fetchContext( urlInput.value ); }
		renderSyndicators();
		updateLocationUI();
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
		updateClearBtn();
	}

	// Clear lives in the docket header. It's only useful once the form has
	// something to clear — an empty compose page is its own affordance.
	// Piggybacks on updatePostBtn so every input/state change already calls it;
	// the dedicated event-field listeners (below) cover the fields that didn't
	// previously hook updatePostBtn (eventName, the date/time inputs, location).
	function hasFormContent() {
		if ( urlInput.value.trim() )       { return true; }
		if ( contentInput.value.trim() )   { return true; }
		if ( selectedFiles.length )        { return true; }
		if ( currentTags.length )          { return true; }
		if ( eventName && eventName.value.trim() )           { return true; }
		if ( eventStartDate && eventStartDate.value )        { return true; }
		if ( eventStartTime && eventStartTime.value )        { return true; }
		if ( eventLocation && eventLocation.value.trim() )   { return true; }
		if ( eventImage && eventImage.value )                { return true; }
		return false;
	}
	function updateClearBtn() {
		if ( clearBtn ) { clearBtn.hidden = ! hasFormContent(); }
	}

	urlInput.addEventListener( 'input', function () {
		updateSpecimen(); updatePostBtn(); saveDraftSoon();
		if ( currentType === 'rsvp' ) { fetchEventSoon(); }
		else if ( isContextKind() ) {
			// Drop a stale preview the moment the URL changes, then re-fetch.
			if ( urlInput.value.trim() !== fetchedContextUrl ) { clearContextPreview(); }
			fetchContextSoon();
		}
	} );
	// Blur fetches immediately — covers a paste-then-tab-away before the debounce.
	urlInput.addEventListener( 'blur', function () {
		if ( currentType === 'rsvp' ) { fetchEvent( urlInput.value ); }
		else if ( isContextKind() ) { fetchContext( urlInput.value ); }
	} );
	contentInput.addEventListener( 'input', function () { updatePostBtn(); updateCounter(); saveDraftSoon(); syncPrompt(); autoGrowContent(); } );
	document.getElementById( 'syndicators' ).addEventListener( 'change', updateCounter );

	// Event-field input listeners — keep the Clear button's "form has content"
	// flag honest when the author types into the event detail fields. Each
	// also persists a draft on settle so a refresh mid-edit doesn't lose it.
	[ eventName, eventStartDate, eventStartTime, eventLocation ].forEach( function ( el ) {
		if ( ! el ) { return; }
		el.addEventListener( 'input',  function () { updatePostBtn(); saveDraftSoon(); } );
		el.addEventListener( 'change', function () { updatePostBtn(); saveDraft(); } );
	} );

	if ( clearBtn ) {
		clearBtn.addEventListener( 'click', function () {
			if ( navigator.vibrate ) { navigator.vibrate( 8 ); }
			clearFields();
		} );
	}

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

	// Render the selected prints + their alt slips from selectedFiles/photoAlts.
	// Split out so removePhoto() can re-render after dropping one (alt values kept).
	function renderThumbs() {
		thumbs.innerHTML  = '';
		altTexts.innerHTML = '';
		selectedFiles.forEach( function (file, i) {
			var cell       = document.createElement( 'figure' );
			cell.className = 'thumb';
			cell.dataset.index = i;          // current position — read back after a drag reorder
			var img = document.createElement( 'img' );
			img.src = URL.createObjectURL( file );
			img.alt = '';
			cell.appendChild( img );
			// Ordinal badge — ties each print to its "Alt text N" slip below. Only when
			// there's more than one (single photo needs no number, like the alt label).
			if ( selectedFiles.length > 1 ) {
				var num = document.createElement( 'span' );
				num.className   = 'thumb__num';
				num.textContent = i + 1;
				num.setAttribute( 'aria-hidden', 'true' );   // decorative; aria-labels already carry the ordinal
				cell.appendChild( num );
			}
			var rm = document.createElement( 'button' );
			rm.type          = 'button';
			rm.className     = 'thumb__remove';
			rm.dataset.index = i;
			rm.innerHTML     = '<svg width="14" height="14" aria-hidden="true" focusable="false"><use href="#nop-x"/></svg>';
			rm.setAttribute( 'aria-label', 'Remove photo ' + ( i + 1 ) );
			cell.appendChild( rm );
			thumbs.appendChild( cell );

			var row   = document.createElement( 'div' );
			row.className = 'alt-text-row';
			var lbl   = document.createElement( 'span' );
			lbl.className = 'alt-text-label';
			lbl.textContent = selectedFiles.length > 1
				? 'Alt text ' + ( i + 1 )
				: 'Alt text';
			var alt           = document.createElement( 'input' );
			alt.type          = 'text';
			alt.className     = 'thumb__alt';
			alt.placeholder   = 'Describe it…';
			alt.autocomplete  = 'off';
			alt.value         = photoAlts[ i ] || '';
			alt.dataset.index = i;
			alt.setAttribute( 'aria-label', 'Alt text for photo ' + ( i + 1 ) );
			row.appendChild( lbl );
			row.appendChild( alt );
			altTexts.appendChild( row );
		} );
		picker.querySelector( 'p' ).textContent = selectedFiles.length
			? selectedFiles.length + ' selected'
			: 'Add photos';
		updatePostBtn();
	}
	function handleFiles( files ) {
		selectedFiles = files.slice( 0, 10 );
		photoAlts     = [];
		renderThumbs();
		saveDraft();
	}
	// Pull one print off the card — drop it (and its alt) from the selection, re-index.
	function removePhoto( i ) {
		selectedFiles.splice( i, 1 );
		photoAlts.splice( i, 1 );
		renderThumbs();
	}
	thumbs.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.thumb__remove' );
		if ( btn ) { removePhoto( parseInt( btn.dataset.index, 10 ) ); }
	} );

	altTexts.addEventListener( 'input', function (e) {
		if ( e.target.classList.contains( 'thumb__alt' ) ) {
			photoAlts[ parseInt( e.target.dataset.index, 10 ) ] = e.target.value;
		}
	} );

	// ── Photo reorder (pointer drag) ────────────────────────────────────────────
	// Drag a print to reorder the set — its alt slip rides along. Pointer-based so
	// it works on touch (HTML5 draggable doesn't fire there at all). The DOM is
	// reordered live for feedback; the new order is committed back to
	// selectedFiles/photoAlts on drop, then both containers re-render in step.
	var dragEl = null, dragStartX = 0, dragStartY = 0, dragLifted = false;
	function thumbAtPoint( x, y ) {
		var nodes = thumbs.querySelectorAll( '.thumb' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var r = nodes[ i ].getBoundingClientRect();
			if ( x >= r.left && x <= r.right && y >= r.top && y <= r.bottom ) { return nodes[ i ]; }
		}
		return null;
	}
	thumbs.addEventListener( 'pointerdown', function ( e ) {
		var fig = e.target.closest( '.thumb' );
		if ( ! fig || e.target.closest( '.thumb__remove' ) ) { return; }
		dragEl = fig; dragStartX = e.clientX; dragStartY = e.clientY; dragLifted = false;
	} );
	thumbs.addEventListener( 'pointermove', function ( e ) {
		if ( ! dragEl ) { return; }
		// A small threshold before "lifting" so a tap/scroll isn't hijacked as a drag.
		if ( ! dragLifted ) {
			if ( Math.abs( e.clientX - dragStartX ) + Math.abs( e.clientY - dragStartY ) < 8 ) { return; }
			dragLifted = true;
			dragEl.classList.add( 'is-dragging' );
			if ( thumbs.setPointerCapture ) { try { thumbs.setPointerCapture( e.pointerId ); } catch ( err ) {} }
			if ( navigator.vibrate ) { navigator.vibrate( 8 ); }
		}
		e.preventDefault();
		var over = thumbAtPoint( e.clientX, e.clientY );
		if ( over && over !== dragEl ) {
			var r     = over.getBoundingClientRect();
			var after = ( e.clientX - r.left ) > r.width / 2;
			thumbs.insertBefore( dragEl, after ? over.nextSibling : over );
		}
	} );
	function endThumbDrag() {
		if ( ! dragEl ) { return; }
		var lifted = dragLifted;
		dragEl.classList.remove( 'is-dragging' );
		dragEl = null; dragLifted = false;
		if ( ! lifted ) { return; }
		// Read the new DOM order (the data-index each print started at) and apply that
		// permutation to both arrays, then rebuild so indices/labels re-sync.
		var order = Array.prototype.map.call( thumbs.querySelectorAll( '.thumb' ), function ( n ) { return parseInt( n.dataset.index, 10 ); } );
		selectedFiles = order.map( function ( i ) { return selectedFiles[ i ]; } );
		photoAlts     = order.map( function ( i ) { return photoAlts[ i ] || ''; } );
		renderThumbs();
		saveDraft();
	}
	thumbs.addEventListener( 'pointerup', endThumbDrag );
	thumbs.addEventListener( 'pointercancel', endThumbDrag );

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
			setProgress( 'Sharing…', 0.97 );
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
			event:       {
				name:     eventName ? eventName.value.trim() : '',
				start:    joinEventDt( eventStartDate, eventStartTime ),
				location: eventLocation ? eventLocation.value.trim() : '',
				image:    eventImage ? eventImage.value.trim() : '',
			},
			tags:        currentTags.slice(),
			location:    ( TYPE_CONFIG[ currentType ].hasLocation && includeLocation && geoLat != null )
				? { lat: geoLat, lon: geoLon, locality: geoLocality, country: geoCountry }
				: null,
			syndicateTo: Array.from( document.querySelectorAll( '#syndicators input[type="checkbox"]:checked' ), function ( cb ) { return cb.value; } ),
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
		if ( cfg.hasRsvp ) {
			props.rsvp = [ post.rsvp ];
			// Event detail (h-event) carried as extra Micropub properties; the RSVP
			// service maps each to its nop_indieweb_rsvp_event_* meta. Empty ones omitted.
			var ev = post.event || {};
			if ( ev.name )     { props[ 'event-name' ]     = [ ev.name ]; }
			if ( ev.start )    { props[ 'event-start' ]    = [ ev.start ]; }
			if ( ev.location ) { props[ 'event-location' ] = [ ev.location ]; }
			if ( ev.image )    { props[ 'event-photo' ]    = [ ev.image ]; }
		}

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

		// Opt-in geotag: the coordinates as a geo: URI (the Micropub-standard simple
		// location value) plus the reverse-geocoded place as flat companions the
		// server maps onto the shared venue meta.
		if ( cfg.hasLocation && post.location && post.location.lat != null ) {
			props.location = [ 'geo:' + post.location.lat + ',' + post.location.lon ];
			if ( post.location.locality ) { props[ 'location-locality' ] = [ post.location.locality ]; }
			if ( post.location.country )  { props[ 'location-country' ]  = [ post.location.country ]; }
		}

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
		var d = Promise.withResolvers();
		var req = indexedDB.open( DB_NAME, 1 );
		req.onupgradeneeded = function () { req.result.createObjectStore( STORE, { keyPath: 'id' } ); };
		req.onsuccess = function () { d.resolve( req.result ); };
		req.onerror   = function () { d.reject( req.error ); };
		return d.promise;
	}
	function qAdd( post ) {
		var d = Promise.withResolvers();
		qOpen().then( function ( db ) {
			var tx = db.transaction( STORE, 'readwrite' ); tx.objectStore( STORE ).put( post );
			tx.oncomplete = function () { d.resolve(); }; tx.onerror = function () { d.reject( tx.error ); };
		}, d.reject );
		return d.promise;
	}
	function qAll() {
		var d = Promise.withResolvers();
		qOpen().then( function ( db ) {
			var out = [], cur = db.transaction( STORE, 'readonly' ).objectStore( STORE ).openCursor();
			cur.onsuccess = function ( e ) { var c = e.target.result; if ( c ) { out.push( c.value ); c.continue(); } else { d.resolve( out ); } };
			cur.onerror = function () { d.reject( cur.error ); };
		}, d.reject );
		return d.promise;
	}
	function qDelete( id ) {
		var d = Promise.withResolvers();
		qOpen().then( function ( db ) {
			var tx = db.transaction( STORE, 'readwrite' ); tx.objectStore( STORE ).delete( id );
			tx.oncomplete = function () { d.resolve(); }; tx.onerror = function () { d.resolve(); };
		}, d.reject );
		return d.promise;
	}
	// Count without loading the stored blobs into memory.
	function qCount() {
		var d = Promise.withResolvers();
		qOpen().then( function ( db ) {
			var r = db.transaction( STORE, 'readonly' ).objectStore( STORE ).count();
			r.onsuccess = function () { d.resolve( r.result ); }; r.onerror = function () { d.resolve( 0 ); };
		}, d.reject );
		return d.promise;
	}

	// Reflect the pending count in the ticker's "Queue: N" item. Rebuild only when
	// the item appears/disappears (0↔N); otherwise just swap the number in place.
	function setQueueCount( n ) {
		var toggled = ( n > 0 ) !== ( queueCount > 0 );
		queueCount = n;
		if ( toggled ) { renderTicker(); }
		else if ( n > 0 ) { setTk( 'tk-queue', 'Queue: ' + n ); }
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
		setQueueCount( queueCount + 1 );        // show "Queue: N" in the ticker
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
	// Raise/drop the LOG "Offline" chip the moment connectivity flips.
	window.addEventListener( 'online',  renderTicker );
	window.addEventListener( 'offline', renderTicker );
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

	// Field wipe shared by the Clear button (in-form "start over") and the
	// post-success "Post another" flow. Resets everything the author can fill
	// — content, URL, photos, tags, event detail, the rotating note prompt —
	// but leaves the currently-selected kind, the serial, and the syndicator
	// tick state alone. The caller decides whether to also swap kind / bump
	// serial / change view.
	function clearFields() {
		selectedFiles = []; photoAlts = []; currentTags = [];
		contentInput.value = ''; urlInput.value = '';
		if ( eventName )      { eventName.value      = ''; }
		if ( eventStartDate ) { eventStartDate.value = ''; }
		if ( eventStartTime ) { eventStartTime.value = ''; }
		if ( eventLocation )  { eventLocation.value  = ''; }
		setEventPoster( '' );
		fetchedEventUrl = '';
		setEventStatus( '' );
		// RSVP response toggle back to its default — same wipe semantics as
		// every other field on the form.
		currentRsvp = 'yes';
		document.querySelectorAll( '.rsvp-btn' ).forEach( function ( b ) {
			var on = b.dataset.rsvp === currentRsvp;
			b.classList.toggle( 'is-active', on );
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		thumbs.innerHTML = ''; altTexts.innerHTML = ''; photoInput.value = '';
		picker.querySelector( 'p' ).textContent = 'Add photos';
		includeLocation = false;
		if ( locationCheck ) { locationCheck.checked = false; }
		updateLocationUI();
		renderTags();
		updateSpecimen();
		clearContextPreview();
		fetchedContextUrl = '';
		notePrompt = NOTE_PROMPTS[ Math.floor( Math.random() * NOTE_PROMPTS.length ) ];
		setPrompt( ( currentType === 'note' ) ? notePrompt : ( TYPE_CONFIG[ currentType ].contentPlaceholder || 'Write…' ) );
		autoGrowContent();
		updateCounter();
		updatePostBtn();
		clearDraft();
	}

	function resetForm() {
		clearFields();
		if ( nextSerial ) { setTk( 'tk-id', TK_ID_PRE + ( ++nextSerial ) ); }
		switchType( 'note' );
		showView( 'compose' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	function showView( name ) {
		function swap() {
			document.getElementById( 'view-compose'  ).hidden = name !== 'compose';
			document.getElementById( 'view-progress' ).hidden = name !== 'progress';
			document.getElementById( 'view-success'  ).hidden = name !== 'success';
		}
		// Cross-fade the swap where supported (Safari 18.2+); hard-swap otherwise, and
		// when the visitor prefers reduced motion — skipping the transition is cleaner
		// than overriding the UA's ::view-transition pseudo-elements.
		if ( document.startViewTransition && ! prefersReduce.matches ) {
			document.startViewTransition( swap );
		} else {
			swap();
		}
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
				location: includeLocation,
				event:   {
					name:     eventName ? eventName.value : '',
					start:    joinEventDt( eventStartDate, eventStartTime ),
					location: eventLocation ? eventLocation.value : '',
					image:    eventImage ? eventImage.value : '',
				},
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
		includeLocation = !! d.location;
		if ( locationCheck ) { locationCheck.checked = includeLocation; }
		updateLocationUI();
		if ( d.event ) {
			if ( eventName )     { eventName.value     = d.event.name     || ''; }
			splitEventDt( eventStartDate, eventStartTime, d.event.start );
			if ( eventLocation ) { eventLocation.value = d.event.location || ''; }
			setEventPoster( d.event.image || '' );
			// Treat a restored URL as already fetched so reopening a draft doesn't
			// re-hit the endpoint and clobber restored edits.
			if ( typeof d.url === 'string' ) { fetchedEventUrl = d.url.trim(); }
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
		document.querySelectorAll( '#syndicators input[type="checkbox"]:checked' ).forEach( function ( cb ) {
			var l = CHAR_LIMITS[ cb.value ];
			if ( l ) lim = lim ? Math.min( lim, l ) : l;
		} );
		return lim;
	}

	// A live character count under the textarea — shown the moment you start typing,
	// hidden when the field is empty (or the kind has no content). When syndicators
	// are ticked it reads "len / lowest-limit" and subtly warns (.is-over) once you
	// pass the smallest connected network's limit; with no limit applying it just
	// counts. Looked up per-call: the first updateCounter() runs inside
	// renderSyndicators() at module init, before any cached ref would resolve.
	function updateCounter() {
		var el  = document.getElementById( 'charCount' );
		var len = contentInput.value.length;
		if ( ! TYPE_CONFIG[ currentType ].hasContent || ! len ) {
			el.hidden = true;
			return;
		}
		var lim = currentLimit();   // smallest char limit among the ticked syndicators
		el.hidden = false;
		el.textContent = lim ? ( len + ' / ' + lim ) : ( len + ( len === 1 ? ' character' : ' characters' ) );
		el.classList.toggle( 'is-over', !! lim && len > lim );
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
		// display + @starting-style (CSS) drive the show/hide transition now — no
		// forced reflow, no hidden-attribute toggling; just flip the class.
		el.className   = 'toast is-visible' + ( kind === 'error' ? ' toast--error' : '' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			el.classList.remove( 'is-visible' );
		}, 3500 );
	}

	// ── Kind order ───────────────────────────────────────────────────────────────
	// The tile order is FIXED (the source order in the markup) so it stays put for
	// muscle memory — the strip never reshuffles under your thumb. MRU is still
	// tracked, but only to pick which kind the app LANDS on at launch (a stable
	// default), not to reorder the dial.
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
	function mruDefaultKind() {
		var mru = readKindMru();
		return ( mru[0] && TYPE_CONFIG[ mru[0] ] ) ? mru[0] : 'note';
	}

	// ── Init ───────────────────────────────────────────────────────────────────

	setInk( app.dataset.type );               // seed :root ink for the initial kind
	app.classList.add( 'no-anim' );           // suppress the re-ink flash for the initial kind
	setPrompt( notePlaceholder() );
	// Defensive: explicit value="" on init. Each browser renders its own empty
	// state for <input type="date"> / <input type="time"> (Chrome shows the
	// dd/mm/yyyy pattern, Safari shows today's date as a hint) — we don't try
	// to override that. input.value is "" when the user hasn't picked, which is
	// all every consumer downstream reads.
	if ( eventStartDate ) { eventStartDate.value = ''; }
	if ( eventStartTime ) { eventStartTime.value = ''; }
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
	requestAnimationFrame( updateTypeFades );  // after layout settles

} )();
