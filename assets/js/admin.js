/**
 * NOP IndieWeb admin — tab switching + token field for the settings page.
 * No build step: plain JS, no dependencies.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		// ── Tab switching ─────────────────────────────────────────────────────────
		// Implements the WAI-ARIA Tabs pattern: roving tabindex, aria-selected on
		// the active tab, arrow keys to move between tabs, Home/End to jump.

		var wrap = document.querySelector( '.nop-indieweb-settings' );
		if ( wrap ) {
			var tabs   = Array.prototype.slice.call( wrap.querySelectorAll( '.nop-nav-tabs [role="tab"]' ) );
			var panels = wrap.querySelectorAll( '.nop-tab-panel' );

			function activate( targetId, focusTab ) {
				tabs.forEach( function ( tab ) {
					var isActive = tab.getAttribute( 'href' ) === targetId;
					tab.classList.toggle( 'nav-tab-active', isActive );
					tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
					tab.setAttribute( 'tabindex', isActive ? '0' : '-1' );
					if ( isActive && focusTab ) {
						tab.focus();
					}
				} );
				panels.forEach( function ( panel ) {
					panel.hidden = ( '#' + panel.id ) !== targetId;
				} );
			}

			tabs.forEach( function ( tab, index ) {
				tab.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var target = this.getAttribute( 'href' );
					activate( target, false );
					if ( history.replaceState ) {
						history.replaceState( null, '', target );
					}
				} );

				tab.addEventListener( 'keydown', function ( e ) {
					var next = null;
					switch ( e.key ) {
						case 'ArrowRight': next = tabs[ ( index + 1 ) % tabs.length ]; break;
						case 'ArrowLeft':  next = tabs[ ( index - 1 + tabs.length ) % tabs.length ]; break;
						case 'Home':       next = tabs[ 0 ]; break;
						case 'End':        next = tabs[ tabs.length - 1 ]; break;
						default: return;
					}
					e.preventDefault();
					var target = next.getAttribute( 'href' );
					activate( target, true );
					if ( history.replaceState ) {
						history.replaceState( null, '', target );
					}
				} );
			} );

			var hash = window.location.hash;
			if ( hash && wrap.querySelector( '.nop-nav-tabs [role="tab"][href="' + hash + '"]' ) ) {
				activate( hash, false );
			} else if ( tabs.length ) {
				activate( tabs[ 0 ].getAttribute( 'href' ), false );
			}
		}

		// ── Copy-to-clipboard buttons ─────────────────────────────────────────────

		document.querySelectorAll( '.nop-copy-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var text = btn.dataset.copy;

				var showCopied = function () {
					btn.textContent = 'Copied ✓';
					btn.disabled = true;
					setTimeout( function () {
						btn.textContent = 'Copy';
						btn.disabled = false;
					}, 2000 );
				};

				var showFailed = function () {
					// Insecure context (http://) or an old browser with no async
					// clipboard API — restore the label so the button isn't stuck.
					btn.textContent = 'Press ⌘/Ctrl-C';
					setTimeout( function () { btn.textContent = 'Copy'; }, 2000 );
				};

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( showCopied ).catch( showFailed );
				} else {
					showFailed();
				}
			} );
		} );

		// ── Setup guide links — click activates the target tab ────────────────────

		document.querySelectorAll( '.nop-setup-link[href^="#nop-tab-"]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				var hash = link.getAttribute( 'href' );
				var tab  = wrap && wrap.querySelector( '.nop-nav-tabs .nav-tab[href="' + hash + '"]' );
				if ( tab ) {
					e.preventDefault();
					tab.click();
					window.scrollTo( { top: 0, behavior: 'smooth' } );
				}
			} );
		} );

		// ── Test connection buttons ───────────────────────────────────────────────

		document.querySelectorAll( '.nop-test-connection' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var result  = btn.parentNode.querySelector( '.nop-test-result' );
				var service = btn.dataset.service;
				var nonce   = btn.dataset.nonce;

				btn.disabled = true;
				result.classList.remove( 'is-success', 'is-error' );
				result.textContent = 'Testing…';

				var body = new FormData();
				body.append( 'action',  'nop_test_connection' );
				body.append( 'service', service );
				body.append( '_ajax_nonce', nonce );

				fetch( ajaxurl, { method: 'POST', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( data ) {
						result.textContent = data.data;
						result.classList.add( data.success ? 'is-success' : 'is-error' );
					} )
					.catch( function () {
						result.textContent = 'Request failed.';
						result.classList.add( 'is-error' );
					} )
					.finally( function () {
						btn.disabled = false;
					} );
			} );
		} );

		// ── Revoke-token confirmation ─────────────────────────────────────────────

		document.querySelectorAll( '.nop-revoke-link[data-confirm]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				if ( ! window.confirm( link.dataset.confirm ) ) {
					e.preventDefault();
				}
			} );
		} );

		// ── Secret reveal toggles ─────────────────────────────────────────────────
		// Tokens and app passwords render as type=password so they don't sit in
		// the DOM in plain text. The toggle flips the input type so the user can
		// verify a paste, then a click reverts it.

		document.querySelectorAll( '.nop-secret-toggle' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var input = document.getElementById( btn.dataset.target );
				if ( ! input ) return;
				var hidden = input.type === 'password';
				input.type      = hidden ? 'text'  : 'password';
				btn.textContent = hidden ? 'Hide'  : 'Show';
			} );
		} );

		// ── Token fields ──────────────────────────────────────────────────────────

		document.querySelectorAll( '.nop-token-field' ).forEach( initTokenField );

	} );

	/**
	 * Initialises a single token field widget.
	 *
	 * Expected DOM shape (generated by PHP):
	 *   <div class="nop-token-field" data-max="1" data-suggestions="[...]">
	 *     <div class="nop-token-field__tokens"></div>
	 *     <input type="text" class="nop-token-field__input">
	 *     <ul  class="nop-token-field__suggestions" hidden></ul>
	 *     <input type="hidden" name="...field_name..." value="...stored_value...">
	 *   </div>
	 *
	 * data-max: maximum number of tokens (omit or 0 = unlimited). Set to "1" for
	 *           the category field so only one category can be active at a time.
	 * data-suggestions: JSON array of existing term names to offer as autocomplete.
	 */
	function initTokenField( wrapper ) {
		var textInput    = wrapper.querySelector( '.nop-token-field__input' );
		var suggestions  = wrapper.querySelector( '.nop-token-field__suggestions' );
		var hidden       = wrapper.querySelector( 'input[type="hidden"]' );
		var tokenList    = wrapper.querySelector( '.nop-token-field__tokens' );
		var max          = parseInt( wrapper.dataset.max || '0', 10 ); // 0 = unlimited
		var available    = JSON.parse( wrapper.dataset.suggestions || '[]' );

		// Bootstrap from the stored comma-separated value.
		var selected = hidden.value
			? hidden.value.split( ',' ).map( function ( s ) { return s.trim(); } ).filter( Boolean )
			: [];

		// ── Combobox a11y wiring ──────────────────────────────────────────────────
		// Promote the text input to a WAI-ARIA combobox that owns the suggestion
		// listbox, so keyboard and screen-reader users can reach the autocomplete
		// options — not just mouse users.

		var listboxId = ( textInput.id || ( 'nop-tf-' + Math.random().toString( 36 ).slice( 2 ) ) ) + '-listbox';
		suggestions.id = listboxId;
		textInput.setAttribute( 'role', 'combobox' );
		textInput.setAttribute( 'aria-autocomplete', 'list' );
		textInput.setAttribute( 'aria-expanded', 'false' );
		textInput.setAttribute( 'aria-controls', listboxId );

		// Index of the keyboard-highlighted option (-1 = none).
		var activeIndex = -1;

		function options() {
			return Array.prototype.slice.call( suggestions.querySelectorAll( '[role="option"]' ) );
		}

		function setActive( index ) {
			var opts = options();
			if ( ! opts.length ) {
				activeIndex = -1;
				textInput.removeAttribute( 'aria-activedescendant' );
				return;
			}
			activeIndex = ( index + opts.length ) % opts.length; // wrap at the ends
			opts.forEach( function ( opt, i ) {
				var isActive = i === activeIndex;
				opt.classList.toggle( 'is-active', isActive );
				opt.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				if ( isActive ) {
					textInput.setAttribute( 'aria-activedescendant', opt.id );
					opt.scrollIntoView( { block: 'nearest' } );
				}
			} );
		}

		// ── Rendering ───────────────────────────────────────────────────────────

		function render() {
			tokenList.innerHTML = '';

			selected.forEach( function ( val ) {
				var pill    = document.createElement( 'span' );
				pill.className   = 'nop-token';
				pill.textContent = val;

				var btn = document.createElement( 'button' );
				btn.type      = 'button';
				btn.className = 'nop-token__remove';
				btn.innerHTML = '&times;';
				btn.setAttribute( 'aria-label', 'Remove ' + val );

				btn.addEventListener( 'click', function () {
					selected = selected.filter( function ( s ) { return s !== val; } );
					syncHidden();
					render();
					textInput.disabled = false;
					textInput.focus();
				} );

				pill.appendChild( btn );
				tokenList.appendChild( pill );
			} );

			// Disable the text input when the token limit is reached.
			var atMax = max > 0 && selected.length >= max;
			textInput.disabled = atMax;
			if ( ! atMax ) {
				textInput.placeholder = textInput.getAttribute( 'placeholder' ) || 'Add…';
			}
		}

		function syncHidden() {
			hidden.value = selected.join( ', ' );
		}

		// ── Adding / removing tokens ─────────────────────────────────────────────

		function addToken( val ) {
			val = val.trim();
			if ( ! val ) return;

			if ( selected.indexOf( val ) === -1 ) {
				selected.push( val );
			}

			textInput.value = '';
			hideSuggestions();
			syncHidden();
			render();
		}

		// ── Suggestion dropdown ──────────────────────────────────────────────────

		function showSuggestions( query ) {
			var q       = query.toLowerCase();
			var matches = available.filter( function ( s ) {
				return s.toLowerCase().indexOf( q ) !== -1 && selected.indexOf( s ) === -1;
			} );

			if ( ! matches.length && ! query ) {
				hideSuggestions();
				return;
			}

			suggestions.innerHTML = '';

			matches.forEach( function ( s ) {
				var li  = document.createElement( 'li' );
				var btn = document.createElement( 'button' );
				btn.type      = 'button';
				btn.className = 'nop-token-field__suggestion';
				btn.setAttribute( 'role', 'option' );
				btn.textContent = s;
				btn.addEventListener( 'mousedown', function ( e ) {
					// Prevent blur on textInput so addToken can run cleanly.
					e.preventDefault();
					addToken( s );
				} );
				li.appendChild( btn );
				suggestions.appendChild( li );
			} );

			// "Create new" entry when the typed value has no exact match.
			var exactMatch = available.some( function ( s ) {
				return s.toLowerCase() === q;
			} );
			if ( query && ! exactMatch ) {
				var li  = document.createElement( 'li' );
				var btn = document.createElement( 'button' );
				btn.type      = 'button';
				btn.className = 'nop-token-field__suggestion nop-token-field__suggestion--new';
				btn.setAttribute( 'role', 'option' );
				btn.textContent = 'Create “' + query + '”';
				btn.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault();
					addToken( query );
				} );
				li.appendChild( btn );
				suggestions.appendChild( li );
			}

			// Give options ids so aria-activedescendant can point at the active one.
			options().forEach( function ( opt, i ) {
				opt.id = listboxId + '-opt-' + i;
				opt.setAttribute( 'aria-selected', 'false' );
			} );

			suggestions.hidden = ! suggestions.children.length;
			textInput.setAttribute( 'aria-expanded', suggestions.hidden ? 'false' : 'true' );
			activeIndex = -1;
			textInput.removeAttribute( 'aria-activedescendant' );
		}

		function hideSuggestions() {
			suggestions.hidden = true;
			suggestions.innerHTML = '';
			textInput.setAttribute( 'aria-expanded', 'false' );
			activeIndex = -1;
			textInput.removeAttribute( 'aria-activedescendant' );
		}

		// ── Events ───────────────────────────────────────────────────────────────

		// Clicking anywhere in the wrapper focuses the text input.
		wrapper.addEventListener( 'click', function ( e ) {
			if ( e.target === wrapper || e.target === tokenList ) {
				textInput.focus();
			}
		} );

		textInput.addEventListener( 'input', function () {
			showSuggestions( textInput.value );
		} );

		textInput.addEventListener( 'focus', function () {
			if ( textInput.value ) showSuggestions( textInput.value );
		} );

		textInput.addEventListener( 'blur', function () {
			// Allow mousedown on a suggestion to fire before we close.
			setTimeout( function () {
				hideSuggestions();
				// Commit whatever's typed when the user tabs away.
				if ( textInput.value.trim() ) {
					addToken( textInput.value.trim() );
				}
			}, 160 );
		} );

		textInput.addEventListener( 'keydown', function ( e ) {
			var open = ! suggestions.hidden && options().length;

			// Arrow keys move the highlight through the open suggestion list.
			if ( open && ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) ) {
				e.preventDefault();
				setActive( activeIndex + ( e.key === 'ArrowDown' ? 1 : -1 ) );
				return;
			}

			// Escape closes the list without committing.
			if ( e.key === 'Escape' && open ) {
				e.preventDefault();
				hideSuggestions();
				return;
			}

			if ( e.key === 'Enter' || e.key === ',' ) {
				e.preventDefault();
				// Enter commits the highlighted suggestion when one is active,
				// otherwise whatever has been typed.
				var opts = options();
				if ( e.key === 'Enter' && open && activeIndex > -1 && opts[ activeIndex ] ) {
					opts[ activeIndex ].dispatchEvent( new MouseEvent( 'mousedown' ) );
					return;
				}
				if ( textInput.value.trim() ) addToken( textInput.value.trim() );
			}
			// Backspace on an empty input removes the last token.
			if ( e.key === 'Backspace' && ! textInput.value && selected.length ) {
				selected.pop();
				syncHidden();
				render();
			}
		} );

		// ── Boot ─────────────────────────────────────────────────────────────────

		render();
	}

} )();
