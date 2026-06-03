/**
 * Syndication panel — block editor sidebar.
 *
 * Before publish: a checkbox per enabled syndicator (Mastodon, Bluesky).
 * Checked by default. Unchecking persists to nop_indieweb_syndicate_to meta,
 * which the syndication manager reads on publish to skip that platform.
 *
 * After publish: a per-platform delivery status view driven by the
 * nop_indieweb_syndication_status journal meta — sent (linked), pending
 * (polled while the cron worker runs), or failed (error + Retry button).
 *
 * No build step — window.wp globals only.
 */
( function ( plugins, editor, editPost, element, data, components, i18n, apiFetch ) {
	'use strict';

	var el          = element.createElement;
	var useState    = element.useState;
	var useEffect   = element.useEffect;
	var useSelect   = data.useSelect;
	var useDispatch = data.useDispatch;
	var Panel        = ( editor && editor.PluginDocumentSettingPanel ) || editPost.PluginDocumentSettingPanel;
	var CheckboxControl = components.CheckboxControl;
	var ExternalLink    = components.ExternalLink;
	var Button          = components.Button;
	var Spinner         = components.Spinner;
	var __          = i18n.__;
	var sprintf     = i18n.sprintf;

	var syndicators = window.nopIndiewebSyndication ? window.nopIndiewebSyndication.syndicators : [];

	if ( ! syndicators.length ) {
		return;
	}

	var POLL_INTERVAL_MS = 15000;

	function journalFromMeta( meta ) {
		var journal = meta && meta['nop_indieweb_syndication_status'];
		return ( journal && ! Array.isArray( journal ) && typeof journal === 'object' ) ? journal : {};
	}

	function StatusRow( props ) {
		var entry      = props.entry;
		var syndicator = props.syndicator;

		if ( entry.state === 'sent' ) {
			return el( 'div', { className: 'nop-syndication-status' },
				el( 'span', { className: 'nop-syndication-status__icon is-sent', 'aria-hidden': 'true' }, '✓' ),
				el( ExternalLink, { href: entry.url }, syndicator.label )
			);
		}

		if ( entry.state === 'pending' ) {
			var pendingText = entry.attempts > 0
				? sprintf(
					/* translators: 1: platform label, 2: attempt count */
					__( '%1$s — retry queued (attempt %2$d failed)', 'nop-indieweb' ),
					syndicator.label,
					entry.attempts
				)
				: sprintf(
					/* translators: %s: platform label */
					__( '%s — publishing…', 'nop-indieweb' ),
					syndicator.label
				);
			return el( 'div', { className: 'nop-syndication-status' },
				el( Spinner, { className: 'nop-syndication-status__spinner' } ),
				el( 'span', { className: 'nop-syndication-status__text' }, pendingText )
			);
		}

		if ( entry.state === 'failed' ) {
			return el( 'div', { className: 'nop-syndication-status is-failed' },
				el( 'div', null,
					el( 'span', { className: 'nop-syndication-status__icon is-failed-icon', 'aria-hidden': 'true' }, '✕' ),
					el( 'span', { className: 'nop-syndication-status__text' }, syndicator.label )
				),
				entry.error ? el( 'div', { className: 'nop-syndication-status__error' }, entry.error ) : null,
				el( Button, {
					className: 'nop-syndication-status__retry',
					variant:   'secondary',
					size:      'small',
					isBusy:    props.retrying,
					disabled:  props.retrying,
					onClick:   props.onRetry,
				}, __( 'Retry', 'nop-indieweb' ) )
			);
		}

		return null;
	}

	function PublishedStatusView( props ) {
		var postId      = props.postId;
		var metaJournal = props.metaJournal;

		// Once polling or a retry has fetched fresher data, it wins over the
		// (stale) copy in the editor's post record.
		var fetchedState  = useState( null );
		var fetched       = fetchedState[0];
		var setFetched    = fetchedState[1];
		var retryingState = useState( {} );
		var retrying      = retryingState[0];
		var setRetrying   = retryingState[1];

		var journal = fetched || metaJournal;

		// Poll while an initial send is in flight (attempts === 0). Scheduled
		// retries can be minutes or hours away — no point polling for those.
		var awaitingFirstSend = syndicators.some( function ( s ) {
			var entry = journal[ s.slug ];
			return entry && entry.state === 'pending' && ! entry.attempts;
		} );

		useEffect( function () {
			if ( ! awaitingFirstSend ) {
				return undefined;
			}
			var timer = setInterval( function () {
				apiFetch( { path: '/wp/v2/posts/' + postId + '?context=edit&_fields=meta' } ).then( function ( post ) {
					setFetched( journalFromMeta( post.meta ) );
				} ).catch( function () {} );
			}, POLL_INTERVAL_MS );
			return function () { clearInterval( timer ); };
		}, [ awaitingFirstSend, postId ] );

		function retry( slug ) {
			setRetrying( function ( prev ) {
				var next = {}; Object.keys( prev ).forEach( function ( k ) { next[ k ] = prev[ k ]; } );
				next[ slug ] = true;
				return next;
			} );
			apiFetch( {
				path:   '/nop-indieweb/v1/syndication/retry',
				method: 'POST',
				data:   { post_id: postId, target: slug },
			} ).then( function () {
				// Optimistically flip this platform back to pending; polling takes over.
				var next = {};
				Object.keys( journal ).forEach( function ( k ) { next[ k ] = journal[ k ]; } );
				next[ slug ] = { state: 'pending', attempts: 0, error: '', updated: 0 };
				setFetched( next );
			} ).finally( function () {
				setRetrying( function ( prev ) {
					var next = {}; Object.keys( prev ).forEach( function ( k ) { next[ k ] = prev[ k ]; } );
					delete next[ slug ];
					return next;
				} );
			} );
		}

		var rows = syndicators.map( function ( syndicator ) {
			var entry = journal[ syndicator.slug ];
			if ( ! entry ) {
				return null;
			}
			return el( StatusRow, {
				key:        syndicator.slug,
				syndicator: syndicator,
				entry:      entry,
				retrying:   !! retrying[ syndicator.slug ],
				onRetry:    function () { retry( syndicator.slug ); },
			} );
		} ).filter( Boolean );

		if ( ! rows.length ) {
			return null;
		}

		return el( Panel, { name: 'nop-indieweb-syndication', title: __( 'Syndicated to', 'nop-indieweb' ) },
			el( 'div', { className: 'nop-syndication-targets' }, rows )
		);
	}

	function SyndicationPanel() {
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );

		var editPostFn = useDispatch( 'core/editor' ).editPost;

		var status = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'status' );
		}, [] );

		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );

		// Published posts get the delivery status view. Posts published before
		// the status journal existed have no entries — panel stays hidden.
		if ( status === 'publish' ) {
			return el( PublishedStatusView, {
				postId:      postId,
				metaJournal: journalFromMeta( meta ),
			} );
		}

		var selected = meta['nop_indieweb_syndicate_to'];
		// 'none' is the explicit "this site only" sentinel — show nothing checked.
		// An empty/missing selection defaults to all syndicators.
		var isNone = Array.isArray( selected ) && selected.indexOf( 'none' ) !== -1;
		var activeTargets = isNone
			? []
			: ( Array.isArray( selected ) && selected.length )
				? selected
				: syndicators.map( function ( s ) { return s.slug; } );

		function toggle( slug, checked ) {
			var next = checked
				? activeTargets.concat( [ slug ] ).filter( function ( v, i, a ) { return a.indexOf( v ) === i; } )
				: activeTargets.filter( function ( s ) { return s !== slug; } );
			// Unchecking the last platform stores the sentinel, not an empty array —
			// empty has always meant "use defaults" elsewhere in the plugin.
			editPostFn( { meta: { nop_indieweb_syndicate_to: next.length ? next : [ 'none' ] } } );
		}

		return el( Panel, { name: 'nop-indieweb-syndication', title: __( 'Syndicate to', 'nop-indieweb' ) },
			el( 'div', { className: 'nop-syndication-targets' },
				syndicators.map( function ( syndicator ) {
					return el( CheckboxControl, {
						key:                     syndicator.slug,
						label:                   syndicator.label,
						checked:                 activeTargets.indexOf( syndicator.slug ) !== -1,
						onChange:                function ( checked ) { toggle( syndicator.slug, checked ); },
						__nextHasNoMarginBottom: true,
					} );
				} )
			)
		);
	}

	plugins.registerPlugin( 'nop-indieweb-syndication-panel', { render: SyndicationPanel } );

} )(
	window.wp.plugins,
	window.wp.editor,
	window.wp.editPost,
	window.wp.element,
	window.wp.data,
	window.wp.components,
	window.wp.i18n,
	window.wp.apiFetch
);
