import { useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { testConnection } from '../api';

export default function TestConnectionButton( { platform, disabled = false } ) {
	const [ status, setStatus ] = useState( null ); // null | { ok, message }
	const [ testing, setTesting ] = useState( false );

	const handleTest = async () => {
		setTesting( true );
		setStatus( null );
		try {
			const result = await testConnection( platform );
			setStatus( result );
		} catch ( err ) {
			setStatus( { ok: false, message: err?.message ?? __( 'Connection failed.', 'nop-indieweb' ) } );
		} finally {
			setTesting( false );
		}
	};

	return (
		<div className="nop-test-connection">
			<Button
				variant="secondary"
				size="small"
				onClick={ handleTest }
				disabled={ disabled || testing }
				isBusy={ testing }
			>
				{ testing ? __( 'Testing…', 'nop-indieweb' ) : __( 'Test connection', 'nop-indieweb' ) }
			</Button>
			{ testing && <Spinner /> }
			{ status && (
				<span className={ `nop-test-result nop-test-result--${ status.ok ? 'ok' : 'error' }` }>
					{ status.message }
				</span>
			) }
		</div>
	);
}
