import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getSessions, revokeSession } from '../api';

function formatDate( iso ) {
	if ( ! iso ) return '—';
	const d = new Date( iso );
	return isNaN( d ) ? iso : d.toLocaleDateString( undefined, { day: 'numeric', month: 'short', year: 'numeric' } );
}

export default function SessionsTable() {
	const [ sessions,    setSessions    ] = useState( null );
	const [ confirming,  setConfirming  ] = useState( null ); // id being confirmed
	const [ revoking,    setRevoking    ] = useState( null ); // id being revoked
	const [ error,       setError       ] = useState( null );

	useEffect( () => {
		getSessions()
			.then( setSessions )
			.catch( () => setError( __( 'Could not load sessions.', 'nop-indieweb' ) ) );
	}, [] );

	const handleRevoke = async ( id ) => {
		setRevoking( id );
		setError( null );
		try {
			await revokeSession( id );
			setSessions( ( prev ) => prev.filter( ( s ) => s.id !== id ) );
		} catch {
			setError( __( 'Could not revoke session. Please try again.', 'nop-indieweb' ) );
		} finally {
			setRevoking( null );
			setConfirming( null );
		}
	};

	if ( error ) {
		return <Notice status="error" isDismissible={ false }>{ error }</Notice>;
	}

	if ( sessions === null ) {
		return <Spinner />;
	}

	if ( sessions.length === 0 ) {
		return (
			<p className="description">
				{ __( 'No applications authorised yet. Connect a Micropub client to see sessions here.', 'nop-indieweb' ) }
			</p>
		);
	}

	return (
		<table className="nop-sessions-table widefat">
			<thead>
				<tr>
					<th>{ __( 'Application', 'nop-indieweb' ) }</th>
					<th>{ __( 'Permissions', 'nop-indieweb' ) }</th>
					<th>{ __( 'Authorised', 'nop-indieweb' ) }</th>
					<th>{ __( 'Last used', 'nop-indieweb' ) }</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				{ sessions.map( ( s ) => (
					<tr key={ s.id }>
						<td>{ s.client_name }</td>
						<td><code>{ s.scope }</code></td>
						<td>{ formatDate( s.issued_at ) }</td>
						<td>{ formatDate( s.last_used_at ) }</td>
						<td className="nop-sessions-table__actions">
							{ confirming === s.id ? (
								<span className="nop-revoke-confirm">
									<Button
										variant="link"
										isDestructive
										size="small"
										isBusy={ revoking === s.id }
										disabled={ revoking === s.id }
										onClick={ () => handleRevoke( s.id ) }
									>
										{ __( 'Confirm revoke', 'nop-indieweb' ) }
									</Button>
									{ ' · ' }
									<Button
										variant="link"
										size="small"
										onClick={ () => setConfirming( null ) }
									>
										{ __( 'Cancel', 'nop-indieweb' ) }
									</Button>
								</span>
							) : (
								<Button
									variant="link"
									isDestructive
									size="small"
									onClick={ () => setConfirming( s.id ) }
								>
									{ __( 'Revoke', 'nop-indieweb' ) }
								</Button>
							) }
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
