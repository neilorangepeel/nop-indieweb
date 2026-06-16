import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getHealthStatus, runHealthCheck } from '../api';

/**
 * Health table — reads `/settings/health` to render last-known status for
 * each enrichment provider (Pirate Weather, Geoapify, Foursquare, TMDB),
 * with a "Check now" button that POSTs to the same endpoint to re-run.
 *
 * Mirrors `wp nop-indieweb health-check` and the daily WP-Cron — three paths,
 * one stored snapshot. A failing provider raises a persistent wp-admin
 * notice elsewhere; this table is the "looking at it on purpose" view.
 */

function relativeTime( unix ) {
	if ( ! unix ) return '—';
	const diff = Math.max( 0, Math.floor( Date.now() / 1000 ) - unix );
	if ( diff < 60 )      return __( 'just now', 'nop-indieweb' );
	if ( diff < 3600 )    return Math.floor( diff / 60 )    + 'm ' + __( 'ago', 'nop-indieweb' );
	if ( diff < 86400 )   return Math.floor( diff / 3600 )  + 'h ' + __( 'ago', 'nop-indieweb' );
	if ( diff < 2592000 ) return Math.floor( diff / 86400 ) + 'd ' + __( 'ago', 'nop-indieweb' );
	return new Date( unix * 1000 ).toLocaleDateString();
}

function StatusBadge( { status } ) {
	const palette = {
		ok:      { bg: '#d1f0d8', fg: '#0a5f1c', label: __( 'OK',        'nop-indieweb' ) },
		error:   { bg: '#fbd1d1', fg: '#7a1f1f', label: __( 'Failing',   'nop-indieweb' ) },
		no_key:  { bg: '#eee',    fg: '#666',    label: __( 'No key',    'nop-indieweb' ) },
		unknown: { bg: '#eee',    fg: '#666',    label: __( 'Unchecked', 'nop-indieweb' ) },
	};
	const p = palette[ status ] || palette.unknown;
	return (
		<span style={ {
			display:       'inline-block',
			padding:       '2px 8px',
			borderRadius:  '3px',
			background:    p.bg,
			color:         p.fg,
			fontWeight:    600,
			fontSize:      '12px',
			letterSpacing: '0.02em',
		} }>{ p.label }</span>
	);
}

export default function HealthTable() {
	const [ payload, setPayload ] = useState( null );
	const [ error,   setError   ] = useState( null );
	const [ running, setRunning ] = useState( false );

	useEffect( () => {
		getHealthStatus()
			.then( setPayload )
			.catch( () => setError( __( 'Could not load health status.', 'nop-indieweb' ) ) );
	}, [] );

	const handleCheck = async () => {
		setRunning( true );
		setError( null );
		try {
			const fresh = await runHealthCheck();
			setPayload( fresh );
		} catch {
			setError( __( 'Health check failed to run. See server logs.', 'nop-indieweb' ) );
		} finally {
			setRunning( false );
		}
	};

	if ( error )           return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	if ( payload === null ) return <Spinner />;

	const providers = payload.providers || [];

	return (
		<div>
			<p className="description" style={ { display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap' } }>
				<span>
					{ payload.last_run_at
						? __( 'Last run:', 'nop-indieweb' ) + ' ' + relativeTime( payload.last_run_at )
						: __( 'Never run. Click below to check now.', 'nop-indieweb' )
					}
				</span>
				<Button variant="secondary" onClick={ handleCheck } disabled={ running } isBusy={ running }>
					{ running ? __( 'Checking…', 'nop-indieweb' ) : __( 'Check now', 'nop-indieweb' ) }
				</Button>
			</p>

			<table className="nop-health-table widefat" style={ { marginTop: '8px' } }>
				<thead>
					<tr>
						<th>{ __( 'Provider', 'nop-indieweb' ) }</th>
						<th>{ __( 'Status',   'nop-indieweb' ) }</th>
						<th>{ __( 'Last OK',  'nop-indieweb' ) }</th>
						<th>{ __( 'Detail',   'nop-indieweb' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ providers.map( ( p ) => (
						<tr key={ p.slug }>
							<td><strong>{ p.label }</strong></td>
							<td><StatusBadge status={ p.status } /></td>
							<td style={ { color: '#666' } }>{ relativeTime( p.last_ok_at ) }</td>
							<td style={ { color: '#666' } }>
								{ p.status === 'error' && p.last_message
									? p.last_message
									: p.status === 'no_key'
										? __( 'API key not configured', 'nop-indieweb' )
										: p.last_http_code
											? 'HTTP ' + p.last_http_code
											: '—'
								}
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
