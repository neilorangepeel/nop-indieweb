/**
 * Browse — the `nop-indieweb/browse` Interactivity store.
 *
 * Makes a wall of entries (the Logbook, a kind's archive, any wall a theme
 * adds with the `nop_indieweb_is_wall` filter) feel like an app without
 * changing what the server renders; every link still works as a page load.
 * Any block theme gets it: the plugin marks the template's <main> (Browse,
 * includes/browse/class-browse.php) and the contract is a few classes.
 *
 * - Filters and pages swap in place: links in a Query Pagination, a Terms
 *   Query or anything inside `.nop-wall-filter` navigate with the core
 *   router, which replaces the wall (and `.nop-wall-crumbs`) and keeps the
 *   header, scroll and focus. A new page lands under `--nop-wall-offset`;
 *   a filter leaves the scroll alone.
 * - Links are fetched on hover, so the click usually finds the page waiting.
 * - An entry (a kind post's title or date link) opens over the wall.
 *   Its page (`?entry`, the Entry template) attaches to <body>; the page
 *   handed to the router also carries the current wall and styles, so
 *   nothing behind it changes. The address is the entry's, Back or Escape
 *   closes it, and focus returns to the link it came from.
 * - The controls (`.nop-entry-nav`, with `.nop-entry__previous`, `__next`,
 *   `__close`) and the left/right keys step through the wall in place,
 *   replacing the address, so Close still returns to the wall.
 * - Opening morphs the post into the entry (a view transition named
 *   nop-entry), and closing morphs it back. The scrim has no animation of
 *   its own: it is in the open picture and gone from the closing one, so the
 *   transition's root cross-fade fades it, composited, with the morph.
 * - Speed: an entry is made ready before it is opened (fetched, built for
 *   the router, prefetched, its first picture decoded, in idle time) when
 *   the pointer rests on it and for an open entry's neighbours.
 */
import { store, getElement, withSyncEvent } from '@wordpress/interactivity';

const REGION = 'data-wp-router-region';
const entries = new Map();
// Entries made ready for the router, by address: the wall they were built against, and the promise.
const ready = new Map();
let wallVersion = 0;
let opener = null;
// Whether core's Image lightbox was open when the key went down: it closes itself on Escape before our handler
// runs, so without this one press would close the lightbox and the entry under it. Read in the capture phase.
let lightboxOpenAtKey = false;
document.addEventListener(
	'keydown',
	() => {
		lightboxOpenAtKey = !! document.querySelector( '.wp-lightbox-overlay.active' );
	},
	true
);

const idle = ( callback ) =>
	window.requestIdleCallback
		? window.requestIdleCallback( callback, { timeout: 400 } )
		: window.setTimeout( callback, 1 );

const wait = ( ms ) => new Promise( ( resolve ) => window.setTimeout( resolve, ms ) );

const router = () => import( '@wordpress/interactivity-router' );

// The open entry's kind on <html> (data-nop-entry-kind), so a theme can colour the wall around it without
// html:has(), which Safari is slow to re-check when the router swaps the entry in place.
const markKind = () => {
	const panel = document.querySelector( 'body > .nop-entry' );
	// Lay the swapped-in entry out from scratch: Safari kept sizes from the previous entry. One synchronous
	// detach and read, before any paint, so nothing flickers.
	if ( panel ) {
		panel.style.display = 'none';
		void panel.offsetHeight;
		panel.style.display = '';
	}
	const kind = panel?.className.match( /\bnop-kind-([\w-]+)/ )?.[ 1 ];
	if ( kind ) {
		document.documentElement.dataset.nopEntryKind = kind;
	} else {
		delete document.documentElement.dataset.nopEntryKind;
	}
};

const reducedMotion = () =>
	window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

const isPlainClick = ( event, link ) =>
	link &&
	0 === event.button &&
	! event.metaKey &&
	! event.ctrlKey &&
	! event.shiftKey &&
	! event.altKey &&
	( ! link.target || '_self' === link.target ) &&
	! link.hasAttribute( 'download' ) &&
	link.origin === window.location.origin;

const withTransition = ( update ) =>
	document.startViewTransition && ! reducedMotion()
		? document.startViewTransition( update ).finished
		: update();

// Both caches hold whole entry pages (about 150KB of HTML and styles each, and the router keeps its own copy of what it is
// handed), so a long visit would grow without end: the most recently used few are kept, the rest fetched again if wanted.
const KEEP = 24;
const remember = ( map, key, value ) => {
	map.delete( key );
	map.set( key, value );
	for ( const oldest of map.keys() ) {
		if ( map.size <= KEEP ) {
			break;
		}
		map.delete( oldest );
	}
};

const fetchEntry = ( href ) => {
	const known = entries.get( href );
	if ( known ) {
		remember( entries, href, known );
		return known;
	}
	// ?entry renders the Entry template (the spread) instead of the full page.
	const request = fetch( href + ( href.includes( '?' ) ? '&' : '?' ) + 'entry' ).then( ( response ) =>
		response.ok ? response.text() : Promise.reject()
	);
	remember( entries, href, request );
	return request;
};

/**
 * The rules in one stylesheet's text that another's lacks, as text (parsed by
 * the browser, compared rule by rule).
 *
 * @param {string} have The sheet already on this page.
 * @param {string} next The entry page's copy.
 * @return {string} The missing rules, or an empty string.
 */
const newRules = ( have, next ) => {
	const parse = ( text ) => {
		const sheet = new window.CSSStyleSheet();
		sheet.replaceSync( text );
		return [ ...sheet.cssRules ].map( ( rule ) => rule.cssText );
	};
	try {
		const known = new Set( parse( have ) );
		return parse( next ).filter( ( rule ) => ! known.has( rule ) ).join( '\n' );
	} catch {
		return next;
	}
};

/**
 * The entry page with this page's regions and styles folded in, so the router
 * updates nothing but the attached entry. The router turns off any stylesheet
 * missing from the page it is given, so this page's sheets come along; the
 * entry's own copies of them, and its classic scripts (which the router never
 * runs), are dropped so there is less to parse and compare.
 *
 * @param {string} html The entry page's HTML.
 * @return {{html: string, picture: Promise}|null} The page to hand the router and its picture decoding, or null if it has no entry.
 */
const entryPage = ( html ) => {
	const page = new window.DOMParser().parseFromString( html, 'text/html' );
	if ( ! page.querySelector( `[${ REGION }*="nop-indieweb/entry"]` ) ) {
		return null;
	}
	const sheetKey = ( sheet ) =>
		sheet.tagName + ( sheet.id || '' ) + ( sheet.href || sheet.textContent );
	const present = new Set(
		[ ...document.querySelectorAll( 'style, link[rel=stylesheet]' ) ].map( sheetKey )
	);
	page.querySelectorAll( 'style, link[rel=stylesheet]' ).forEach( ( sheet ) => {
		if ( present.has( sheetKey( sheet ) ) ) {
			sheet.remove();
		}
	} );
	page.querySelectorAll( 'script' ).forEach( ( script ) => {
		const type = script.type;
		if ( 'module' !== type && 'importmap' !== type && 'application/json' !== type ) {
			script.remove();
		}
	} );
	// The router keeps this page's copy of any stylesheet the entry page shares
	// by name, so styles generated for the entry's own blocks (block-support
	// layout, block style variations) would be lost. Those travel inside the
	// entry region instead.
	// Only the rules this page lacks travel: a whole sheet appended after this
	// page's styles would also repeat every shared rule later in the cascade and
	// undo this page's own overrides (the global styles differ only by the
	// per-block rules WordPress prints for the blocks on each page).
	const region = page.querySelector( `main[${ REGION }*="nop-indieweb/entry"]` );
	page.querySelectorAll( 'style[id]' ).forEach( ( sheet ) => {
		const ours = document.getElementById( sheet.id );
		if ( ! region || ! ours || ours.textContent === sheet.textContent ) {
			return;
		}
		const extra = newRules( ours.textContent, sheet.textContent );
		sheet.remove();
		if ( extra ) {
			const style = page.createElement( 'style' );
			style.className = 'nop-entry-styles';
			style.textContent = extra;
			region.append( style );
		}
	} );
	// Duotone (a block's Duotone setting) points at an SVG filter WordPress prints at the foot of the page, outside
	// the entry region; it travels inside the region too, unless this page has its own copy outside any entry (a copy
	// inside the open entry goes when the next entry replaces it).
	page.querySelectorAll( 'svg filter[id^="wp-duotone-"]' ).forEach( ( filter ) => {
		const ours = document.getElementById( filter.id );
		if ( region && ( ! ours || ours.closest( `[${ REGION }*="nop-indieweb/entry"]` ) ) ) {
			region.append( filter.closest( 'svg' ) );
		}
	} );
	// Decode the entry's first picture now, so it is on screen the moment the entry opens.
	const img = region?.querySelector( 'img' );
	let picture = Promise.resolve();
	if ( img?.getAttribute( 'src' ) ) {
		const preload = new window.Image();
		if ( img.sizes ) {
			preload.sizes = img.sizes;
		}
		if ( img.srcset ) {
			preload.srcset = img.srcset;
		}
		preload.src = img.getAttribute( 'src' );
		picture = preload.decode().catch( () => {} );
	}
	document.querySelectorAll( `[${ REGION }]` ).forEach( ( region ) => {
		const id = region.getAttribute( REGION );
		if ( id.includes( 'nop-indieweb/entry' ) ) {
			return;
		}
		const copy = page.importNode( region, true );
		const twin = page.querySelector(
			`[${ REGION }="${ window.CSS.escape( id ) }"]`
		);
		if ( twin ) {
			twin.replaceWith( copy );
		} else {
			page.body.append( copy );
		}
	} );
	document
		.querySelectorAll( 'style, link[rel=stylesheet]' )
		.forEach( ( sheet ) => {
			page.head.append( page.importNode( sheet, true ) );
		} );
	return { html: page.documentElement.outerHTML, picture };
};

/**
 * Make an entry ready to open: fetched, built for the router against the wall
 * as it is now, prefetched into the router, its picture decoded. Once per
 * entry per wall; a filter or page change makes the wall new.
 *
 * @param {string} href Entry address.
 * @return {Promise<boolean>} Resolves true when the entry can open at once.
 */
const prepareEntry = ( href ) => {
	const known = ready.get( href );
	if ( known && known.version === wallVersion ) {
		return known.promise;
	}
	const promise = ( async () => {
		const prepared = entryPage( await fetchEntry( href ) );
		if ( ! prepared ) {
			return false;
		}
		const { actions: routerActions } = await router();
		await routerActions.prefetch( href, { html: prepared.html, force: true } );
		// Wait for the picture, but never long: the entry can open while it finishes.
		await Promise.race( [ prepared.picture, wait( 300 ) ] );
		return true;
	} )();
	promise.catch( () => ready.delete( href ) );
	remember( ready, href, { version: wallVersion, promise } );
	return promise;
};

const panel = () => document.querySelector( 'body > .nop-entry' );

// The panel takes the transition's name only while it morphs (see overlay.css).
const namePanel = ( name ) => {
	const element = panel();
	if ( element ) {
		element.style.viewTransitionName = name;
	}
};

// How long a pointer rests on a tile before its entry is fetched (ms), and the tile it is resting on.
const DWELL = 90;
let dwell = 0;
let dwellHref = '';
let dwellPost = null;
const cancelDwell = () => {
	window.clearTimeout( dwell );
	dwell = 0;
	dwellHref = '';
	dwellPost = null;
};

const prepareSoon = ( href ) => idle( () => prepareEntry( href ).catch( () => {} ) );

// A wall's entry links: a kind post's title or date link in a Query Loop (never its other links: kind, like, source).
const ENTRY_LINK = '.wp-block-post[class*="nop_kind-"] :is(.wp-block-post-title, .wp-block-post-date, .wp-block-post-featured-image) a[href]';
const FILTER_LINK = '.wp-block-query-pagination, .wp-block-terms-query, .nop-wall-filter, .nop-wall-chips';
const isEntryLink = ( link ) => !! link?.closest( '.nop-wall' ) && link.matches( ENTRY_LINK );
// The post an entry link belongs to: what the entry morphs out of and back into.
const postOf = ( link ) => link?.closest( '.wp-block-post' );

/**
 * The wall's entries in order: one link per post (its title, its picture, or its date when
 * the title is hidden).
 *
 * @return {HTMLAnchorElement[]} Links.
 */
const tileLinks = () => {
	const seen = new Set();
	return [
		...document.querySelectorAll(
			'.nop-wall ' + ENTRY_LINK
		),
	].filter( ( link ) => {
		if ( link.origin !== window.location.origin || seen.has( link.href ) ) {
			return false;
		}
		seen.add( link.href );
		return true;
	} );
};

/**
 * The tile before or after the open entry on the wall.
 *
 * @param {number} step -1 or 1.
 * @return {HTMLAnchorElement|null} Link, or null at either end.
 */
function neighbour( step ) {
	const links = tileLinks();
	const index = links.findIndex( ( link ) => link.href === state.entryUrl );
	return index < 0 ? null : links[ index + step ] ?? null;
}

let searchTimer = 0;

// An open (or step) in flight, and a Close asked for while it was: the entry is shown first, then closed, never half of each.
let opening = false;
let closeQueued = false;

const isTyping = ( event ) =>
	event.target.closest?.( 'input, textarea, select, [contenteditable]' );

const { state, actions } = store( 'nop-indieweb/browse', {
	state: {
		entryUrl: '',
		// The wall's filter bar (a Group with the class nop-wall-filters): its kinds and topics show while it is in use.
		filtersOpen: false,
		get entryRole() {
			return state.entryOpen ? 'dialog' : null;
		},
		get hasPrevious() {
			return !! neighbour( -1 );
		},
		get hasNext() {
			return !! neighbour( 1 );
		},
		get entryOpen() {
			return (
				!! state.entryUrl &&
				// Compared without the hash, so a jump within the entry never reads as leaving it.
				store( 'core/router' ).state.url.split( '#' )[ 0 ] === state.entryUrl.split( '#' )[ 0 ]
			);
		},
	},
	actions: {
		follow: withSyncEvent( function* ( event ) {
			const link = event.target.closest( 'a[href]' );
			if (
				! isPlainClick( event, link ) ||
				link.closest( '.wp-block-nop-indieweb-like-button' )
			) {
				return;
			}
			if ( isEntryLink( link ) ) {
				event.preventDefault();
				opener = link;
				yield actions.openEntry( link.href );
				return;
			}
			if ( ! link.closest( FILTER_LINK ) ) {
				return;
			}
			event.preventDefault();
			const { actions: routerActions } = yield router();
			const region = getElement().ref;
			yield withTransition( () => routerActions.navigate( link.href ) );
			// The wall is new: entries made ready against the old one are rebuilt when next wanted.
			wallVersion += 1;
			// A filter changes the wall in place, with no scroll. A new page lands on
			// the wall itself, below a sticky header (--nop-wall-offset), since its
			// links sit at the wall's foot.
			const wall = region.querySelector( '.wp-block-query' ) ?? region;
			const header = parseFloat(
				getComputedStyle( document.documentElement ).getPropertyValue(
					'--nop-wall-offset'
				)
			);
			if (
				link.closest( '.wp-block-query-pagination' ) &&
				wall.getBoundingClientRect().top < ( header || 0 )
			) {
				wall.scrollIntoView( {
					behavior: reducedMotion() ? 'instant' : 'smooth',
				} );
			}
			// A choice in the filter bar keeps the bar in use: focus goes back to its search, not to the wall.
			if ( link.closest( '.nop-wall-filters' ) ) {
				region.querySelector( '.nop-wall-filters input[type="search"]' )?.focus( { preventScroll: true } );
				return;
			}
			region.focus( { preventScroll: true } );
		} ),
		// The filter bar opens when anything in it takes focus, and stays open while the wall swaps under it (a choice
		// replaces the bar too, and focus is lost with the old one). It closes on Escape, on a click elsewhere, or when
		// focus moves on to something outside it.
		openFilters() {
			state.filtersOpen = true;
		},
		leaveFilters( event ) {
			const next = event.relatedTarget;
			if ( next && ! next.closest( '.nop-wall-filters' ) ) {
				state.filtersOpen = false;
			}
		},
		outsideFilters( event ) {
			if ( state.filtersOpen && ! event.target.closest?.( '.nop-wall-filters' ) ) {
				state.filtersOpen = false;
			}
		},
		// Pressing a pill or a chip must not take focus out of the search: Safari does not focus a link on click, so
		// the search would blur, the bar would read as no longer in use and the drawer would close before the click
		// landed. Holding the press keeps focus (and the drawer) where it is; the click still follows the link.
		holdFilters: withSyncEvent( ( event ) => {
			if ( event.target.closest?.( '.orange-peel-filter__drawer a, .nop-wall-chips a, .nop-wall-clear a' ) ) {
				event.preventDefault();
			}
		} ),
		filterKeys: withSyncEvent( ( event ) => {
			if ( 'Escape' === event.key && state.filtersOpen ) {
				event.stopPropagation();
				state.filtersOpen = false;
				event.target.blur?.();
			}
		} ),
		// The wall's search (a Search block with the class nop-wall-search): as you type (after a pause) or on submit,
		// the wall is swapped for the results, the address keeping the search with the other filters.
		searchWall: withSyncEvent( function* ( event ) {
			const form = event.target.closest( 'form' );
			if ( 'submit' === event.type ) {
				event.preventDefault();
			} else {
				window.clearTimeout( searchTimer );
				yield new Promise( ( resolve ) => {
					searchTimer = window.setTimeout( resolve, 350 );
				} );
			}
			const url = new URL( form.action );
			new FormData( form ).forEach( ( value, key ) => {
				if ( String( value ).trim() ) {
					url.searchParams.set( key, String( value ).trim() );
				}
			} );
			const href = url.toString().replace( /%2C/g, ',' ).replace( /%2B/g, '+' );
			if ( href === window.location.href ) {
				return;
			}
			const { actions: routerActions } = yield router();
			yield routerActions.navigate( href, { replace: 'submit' !== event.type } );
		} ),
		*prefetch( event ) {
			const link = event.target.closest?.( 'a[href]' );
			if (
				! link ||
				link.origin !== window.location.origin ||
				link.href === window.location.href
			) {
				return;
			}
			if ( isEntryLink( link ) ) {
				// Every entry is a server render, so a pointer sweeping across the wall must not ask for each tile it
				// crosses: it has to rest (DWELL) on one. A keyboard focus or a touch is already a decision: at once.
				// Someone saving data gets no guesses: the entry is fetched when it is opened.
				if ( window.navigator.connection?.saveData ) {
					return;
				}
				const start = () => {
					dwellHref = '';
					// Start fetching at once; build the router's page when the browser is idle.
					fetchEntry( link.href ).catch( () => entries.delete( link.href ) );
					prepareSoon( link.href );
				};
				if ( 'focusin' === event.type || 'mouse' !== event.pointerType ) {
					start();
				} else if ( dwellHref !== link.href ) {
					cancelDwell();
					dwellHref = link.href;
					dwellPost = postOf( link );
					dwell = window.setTimeout( start, DWELL );
				}
				return;
			}
			if ( link.closest( FILTER_LINK ) ) {
				const { actions: routerActions } = yield router();
				yield routerActions.prefetch( link.href );
			}
		},
		// The pointer left the tile it was resting on before it rested long enough.
		cancelPrefetch( event ) {
			if ( dwellHref && ! dwellPost?.contains( event.relatedTarget ) ) {
				cancelDwell();
			}
		},
		*openEntry( href, replace = false ) {
			if ( opening ) {
				return;
			}
			opening = true;
			try {
				yield actions.showEntry( href, replace );
			} finally {
				opening = false;
			}
			if ( closeQueued ) {
				closeQueued = false;
				actions.closeEntry();
			}
		},
		*showEntry( href, replace ) {
			let isReady = false;
			try {
				isReady = yield prepareEntry( href );
			} catch {}
			if ( ! isReady ) {
				window.location.assign( href );
				return;
			}
			const { actions: routerActions } = yield router();
			const options = { replace, loadingAnimation: false };
			document.documentElement.classList.remove( 'nop-entry-closing' );
			state.entryUrl = href;
			// The post grows into the entry: it carries the transition's name until the entry takes it.
			// Stepping between entries swaps at once.
			const tile = replace ? null : postOf( opener );
			if ( tile && document.startViewTransition && ! reducedMotion() ) {
				tile.style.viewTransitionName = 'nop-entry';
				const transition = document.startViewTransition( async () => {
					tile.style.viewTransitionName = '';
					// The dimmed wall is in place for the transition's new picture, so it fades with the morph rather than after it.
					document.documentElement.classList.add( 'nop-entry-open' );
					await routerActions.navigate( href, options );
					markKind();
					namePanel( 'nop-entry' );
				} );
				transition.finished.finally( () => namePanel( '' ) );
				yield transition.updateCallbackDone;
			} else {
				yield routerActions.navigate( href, options );
			}
			markKind();
			// Have the neighbours waiting.
			[ -1, 1 ].forEach( ( step ) => {
				const link = neighbour( step );
				if ( link ) {
					prepareSoon( link.href );
				}
			} );
		},
		*stepEntry( step ) {
			const link = neighbour( step );
			if ( ! state.entryOpen || ! link ) {
				return;
			}
			opener = link;
			yield actions.openEntry( link.href, true );
		},
		previousEntry() {
			return actions.stepEntry( -1 );
		},
		nextEntry() {
			return actions.stepEntry( 1 );
		},
		// A link to a spot in this entry (a #respond) scrolls there inside the panel,
		// opening the reply form if that is where it points. The address keeps no hash, so Close still returns to the wall.
		entryLink: withSyncEvent( ( event ) => {
			const link = event.target.closest( 'a[href*="#"]' );
			if (
				! isPlainClick( event, link ) ||
				link.href.split( '#' )[ 0 ] !== state.entryUrl.split( '#' )[ 0 ]
			) {
				return;
			}
			event.preventDefault();
			const target = document.getElementById( decodeURIComponent( link.hash.slice( 1 ) ) );
			const details = target?.closest( 'details' ) ?? target?.querySelector?.( 'details' );
			if ( details ) {
				details.open = true;
			}
			( details ?? target )?.scrollIntoView( { block: 'nearest', behavior: reducedMotion() ? 'instant' : 'smooth' } );
			( details?.querySelector( 'textarea, input' ) ?? target )?.focus?.( { preventScroll: true } );
		} ),
		// The panel goes at once (morphing back into its tile where it can); the wall catches up underneath.
		closeEntry() {
			if ( opening ) {
				closeQueued = true;
				return;
			}
			if ( ! state.entryOpen ) {
				return;
			}
			const root = document.documentElement;
			const tile = postOf( opener );
			// The closing picture: no panel, no controls, no scrim (overlay.css), and the post carrying the panel's name, so
			// the view transition morphs the panel home and cross-fades the scrim away in one composited animation.
			const hide = () => {
				namePanel( '' );
				root.classList.add( 'nop-entry-closing' );
				if ( tile ) {
					tile.scrollIntoView( { block: 'nearest' } );
					tile.style.viewTransitionName = 'nop-entry';
				}
			};
			if ( document.startViewTransition && ! reducedMotion() ) {
				if ( tile ) {
					namePanel( 'nop-entry' );
				}
				document.startViewTransition( hide ).finished.finally( () => {
					if ( tile ) {
						tile.style.viewTransitionName = '';
					}
					// The router may have shown the wall before the closing picture was taken; clear the state here too.
					if ( ! state.entryOpen ) {
						root.classList.remove( 'nop-entry-closing' );
					}
				} );
			} else {
				hide();
				if ( tile ) {
					tile.style.viewTransitionName = '';
				}
			}
			// The router then shows the wall; holdFocus clears the closing state once the entry is gone.
			window.history.back();
		},
		// The dimmed wall around the panel: it is inert, so a click there lands outside the entry.
		closeOnScrim( event ) {
			// The previous and next arrows sit outside the panel but are part of the entry, and so is core's lightbox (attached to <body>).
			if ( state.entryOpen && ! event.target.closest?.( '.nop-entry, .nop-entry-nav, .wp-lightbox-overlay' ) ) {
				actions.closeEntry();
			}
		},
		closeOnEscape( event ) {
			// An open lightbox (core's Image lightbox, a modal) has the keys: Escape closes it and the arrows step its gallery.
			// The lightbox may close itself first, so a key pressed inside it counts too.
			// Escape in a field leaves the field first (a second press closes), so a half-written reply is not lost to a stray key.
			if ( 'Escape' === event.key && isTyping( event ) && state.entryOpen ) {
				event.target.blur?.();
				return;
			}
			if ( ! ( state.entryOpen || opening ) || isTyping( event ) || lightboxOpenAtKey || event.target.closest?.( '.wp-lightbox-overlay' ) || document.querySelector( 'dialog:modal, .wp-lightbox-overlay.active' ) ) {
				return;
			}
			if ( 'Escape' === event.key ) {
				actions.closeEntry();
				return;
			}
			// An entry with several photos steps through them first, and on to the next entry only from its last (or first) photo.
			const back = 'ArrowLeft' === event.key;
			if ( ! back && 'ArrowRight' !== event.key ) {
				return;
			}
			const photo = document.querySelector( `.nop-entry .nop-photos.has-several .nop-photos__${ back ? 'previous' : 'next' }:not(:disabled)` );
			if ( photo ) {
				photo.click();
			} else if ( back ) {
				actions.previousEntry();
			} else {
				actions.nextEntry();
			}
		},
	},
	callbacks: {
		/**
		 * Runs on the wall: while an entry is open the page behind is still and
		 * inert, focus starts on the entry's Close button, and returns to the
		 * link it came from afterwards.
		 */
		holdFocus() {
			const open = state.entryOpen;
			document.documentElement.classList.toggle(
				'nop-entry-open',
				open
			);
			const site = document.querySelector( '.wp-site-blocks' );
			if ( site ) {
				site.inert = open;
			}
			if ( ! open ) {
				document.documentElement.classList.remove( 'nop-entry-closing' );
			}
			markKind();
			window.requestAnimationFrame( () => {
				if ( open ) {
					document
						.querySelector(
							'body > .nop-entry-nav .nop-entry__close :is(button, a)'
						)
						// Focus for keyboards and screen readers, without a ring after a click.
						?.focus( { focusVisible: false } );
				} else if ( opener ) {
					opener.focus( { preventScroll: true } );
					opener = null;
				}
			} );
		},
	},
} );
