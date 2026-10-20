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

const idle = ( callback ) =>
	window.requestIdleCallback
		? window.requestIdleCallback( callback, { timeout: 400 } )
		: window.setTimeout( callback, 1 );

const wait = ( ms ) => new Promise( ( resolve ) => window.setTimeout( resolve, ms ) );

const router = () => import( '@wordpress/interactivity-router' );

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

const fetchEntry = ( href ) => {
	if ( ! entries.has( href ) ) {
		entries.set(
			href,
			// ?entry renders the Entry template (the spread) instead of the full page.
			fetch( href + ( href.includes( '?' ) ? '&' : '?' ) + 'entry' ).then( ( response ) =>
				response.ok ? response.text() : Promise.reject()
			)
		);
	}
	return entries.get( href );
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
	ready.set( href, { version: wallVersion, promise } );
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

const prepareSoon = ( href ) => idle( () => prepareEntry( href ).catch( () => {} ) );

// A wall's entry links: a kind post's title or date link in a Query Loop (never its other links: kind, like, source).
const ENTRY_LINK = '.wp-block-post[class*="nop_kind-"] :is(.wp-block-post-title, .wp-block-post-date) a[href]';
const FILTER_LINK = '.wp-block-query-pagination, .wp-block-terms-query, .nop-wall-filter';
const isEntryLink = ( link ) => !! link?.closest( '.nop-wall' ) && link.matches( ENTRY_LINK );
// The post an entry link belongs to: what the entry morphs out of and back into.
const postOf = ( link ) => link?.closest( '.wp-block-post' );

/**
 * The wall's entries in order: one link per post (its title, or its date when
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

const isTyping = ( event ) =>
	event.target.closest?.( 'input, textarea, select, [contenteditable]' );

const { state, actions } = store( 'nop-indieweb/browse', {
	state: {
		entryUrl: '',
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
			region.focus( { preventScroll: true } );
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
				// Start fetching at once; build the router's page when the browser is idle.
				fetchEntry( link.href ).catch( () => entries.delete( link.href ) );
				prepareSoon( link.href );
				return;
			}
			if ( link.closest( FILTER_LINK ) ) {
				const { actions: routerActions } = yield router();
				yield routerActions.prefetch( link.href );
			}
		},
		*openEntry( href, replace = false ) {
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
					namePanel( 'nop-entry' );
				} );
				transition.finished.finally( () => namePanel( '' ) );
				yield transition.updateCallbackDone;
			} else {
				yield routerActions.navigate( href, options );
			}
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
			// The previous and next arrows sit outside the panel but are part of the entry.
			if ( state.entryOpen && ! event.target.closest?.( '.nop-entry, .nop-entry-nav' ) ) {
				actions.closeEntry();
			}
		},
		closeOnEscape( event ) {
			if ( ! state.entryOpen || isTyping( event ) ) {
				return;
			}
			if ( 'Escape' === event.key ) {
				actions.closeEntry();
			} else if ( 'ArrowLeft' === event.key ) {
				actions.previousEntry();
			} else if ( 'ArrowRight' === event.key ) {
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
