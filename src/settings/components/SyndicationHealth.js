import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import { getSyndicationHealth, retrySyndication } from '../api';

/**
 * Outbound syndication health — reads `/syndication/health` (the aggregate of
 * posts whose delivery to a network failed) so a dead token is visible here
 * without opening individual posts. Per-network state badges + a list of the
 * failed posts, each with a Retry that re-queues the existing retry endpoint.
 */

function StateBadge( { state, count } ) {
	const palette = {
		ok:      { bg: '#d1f0d8', fg: '#0a5f1c', label: __( 'Healthy', 'nop-indieweb' ) },
		failing: { bg: '#fbd1d1', fg: '#7a1f1f', label: sprintf( /* translators: %d: failure count */ __( 'Failing %d', 'nop-indieweb' ), count ) },
		off:     { bg: '#eee',    fg: '#666',    label: __( 'Off', 'nop-indieweb' ) },
	};
	const p = palette[ state ] || palette.off;
	return (
		<span style={ {
			display: 'inline-block', padding: '2px 8px', borderRadius: '3px',
			background: p.bg, color: p.fg, fontWeight: 600, fontSize: '12px',
		} }>{ p.label }</span>
	);
}

export default function SyndicationHealth() {
	const [ data,     setData ]     = useState( null );
	const [ error,    setError ]    = useState( null );
	const [ loading,  setLoading ]  = useState( false );
	const [ retrying, setRetrying ] = useState( {} ); // `${postId}:${slug}` → true
	const [ resolved, setResolved ] = useState( {} ); // optimistically retried rows

	const load = () => {
		setLoading( true );
		setError( null );
		getSyndicationHealth()
			.then( setData )
			.catch( () => setError( __( 'Could not load syndication health.', 'nop-indieweb' ) ) )
			.finally( () => setLoading( false ) );
	};

	useEffect( load, [] );

	const handleRetry = async ( postId, slug ) => {
		const key = postId + ':' + slug;
		setRetrying( ( p ) => ( { ...p, [ key ]: true } ) );
		try {
			await retrySyndication( postId, slug );
			setResolved( ( p ) => ( { ...p, [ key ]: true } ) ); // it's pending now — drop the row
		} catch {
			/* leave the row so the user can try again */
		} finally {
			setRetrying( ( p ) => {
				const next = { ...p };
				delete next[ key ];
				return next;
			} );
		}
	};

	if ( error )         return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	if ( data === null ) return <Spinner />;

	const total       = data.total_failed_posts || 0;
	const networks    = data.networks || [];
	const failedPosts = data.failed_posts || [];

	return (
		<div className="nop-synd-health" style={ { marginBottom: '20px' } }>
			<p className="description" style={ { display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap' } }>
				<strong>{ __( 'Outbound delivery', 'nop-indieweb' ) }</strong>
				<span>
					{ total > 0
						? sprintf(
							/* translators: %d: number of posts */
							_n( '%d post failed to syndicate.', '%d posts failed to syndicate.', total, 'nop-indieweb' ),
							total
						)
						: __( 'All deliveries healthy.', 'nop-indieweb' ) }
				</span>
				<Button variant="secondary" onClick={ load } disabled={ loading } isBusy={ loading }>
					{ loading ? __( 'Checking…', 'nop-indieweb' ) : __( 'Re-check', 'nop-indieweb' ) }
				</Button>
			</p>

			<div style={ { display: 'flex', gap: '14px', flexWrap: 'wrap', margin: '8px 0 4px' } }>
				{ networks.map( ( n ) => (
					<span key={ n.slug } style={ { display: 'inline-flex', alignItems: 'center', gap: '6px' } }>
						<strong>{ n.label }</strong>
						<StateBadge state={ n.state } count={ n.failed_count } />
					</span>
				) ) }
			</div>

			{ failedPosts.length > 0 && (
				<table className="nop-health-table widefat" style={ { marginTop: '8px' } }>
					<thead>
						<tr>
							<th>{ __( 'Post', 'nop-indieweb' ) }</th>
							<th>{ __( 'Network', 'nop-indieweb' ) }</th>
							<th>{ __( 'Error', 'nop-indieweb' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ failedPosts.flatMap( ( post ) => post.targets.map( ( t ) => {
							const key = post.post_id + ':' + t.slug;
							if ( resolved[ key ] ) return null;
							const title = post.title || ( '#' + post.post_id );
							return (
								<tr key={ key }>
									<td>{ post.edit_url ? <a href={ post.edit_url }>{ title }</a> : title }</td>
									<td>{ t.slug }</td>
									<td style={ { color: '#7a1f1f' } }>{ t.error || '—' }</td>
									<td>
										<Button
											variant="secondary"
											size="small"
											isBusy={ !! retrying[ key ] }
											disabled={ !! retrying[ key ] }
											onClick={ () => handleRetry( post.post_id, t.slug ) }
										>{ __( 'Retry', 'nop-indieweb' ) }</Button>
									</td>
								</tr>
							);
						} ) ).filter( Boolean ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
