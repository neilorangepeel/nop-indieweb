/**
 * Pure helpers for the /post composer — no DOM, no globals, so they're unit-testable.
 * Imported by index.js (the single source of truth) and exercised in lib.test.js.
 */

// 1 → "1st", 2 → "2nd", 3 → "3rd", 11 → "11th" …
export function ordinal( n ) {
	var s = [ 'th', 'st', 'nd', 'rd' ], v = n % 100;
	return n + ( s[ ( v - 20 ) % 10 ] || s[ v ] || s[ 0 ] );
}

// Seconds → a short human duration: 45 → "1m", 3540 → "59m", 3600 → "1h 0m".
export function tkDur( s ) {
	var m = Math.round( s / 60 );
	return m < 60 ? m + 'm' : Math.floor( m / 60 ) + 'h ' + ( m % 60 ) + 'm';
}

// Map a share/Shortcut query string to a post intent. Explicit kind params
// (?reply=/?like=/?repost=/?bookmark=/?rsvp=) win; otherwise a shared link
// (?url=) becomes a bookmark and shared text (?text/?note/?title) a note.
// Returns { kind, url, text } — kind '' when there's nothing to act on.
export function parseShareParams( search ) {
	var qs = new URLSearchParams( search || '' );
	var explicit = [ 'reply', 'like', 'repost', 'bookmark', 'rsvp' ];
	var kind = '', url = '', text = '', i, v;
	for ( i = 0; i < explicit.length; i++ ) {
		v = qs.get( explicit[ i ] );
		if ( v ) { kind = explicit[ i ]; url = v; break; }
	}
	if ( kind ) {
		text = qs.get( 'text' ) || qs.get( 'note' ) || '';
	} else {
		var u = qs.get( 'url' ) || '';
		text = qs.get( 'text' ) || qs.get( 'note' ) || qs.get( 'title' ) || '';
		if ( u ) { kind = 'bookmark'; url = u; }
		else if ( text ) { kind = 'note'; }
	}
	return { kind: kind, url: url, text: text };
}
