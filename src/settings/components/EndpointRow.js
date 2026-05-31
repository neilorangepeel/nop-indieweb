import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function EndpointRow( { label, url } ) {
	const [ copied, setCopied ] = useState( false );

	const handleCopy = () => {
		if ( ! url ) return;
		// navigator.clipboard is undefined on insecure (http) origins and the
		// promise can reject; guard and swallow so there's no unhandled rejection.
		if ( ! navigator.clipboard?.writeText ) return;
		navigator.clipboard
			.writeText( url )
			.then( () => {
				setCopied( true );
				setTimeout( () => setCopied( false ), 2000 );
			} )
			.catch( () => {} );
	};

	return (
		<div className="nop-url-copy-row">
			<span className="nop-url-copy-row__label">{ label }</span>
			<code className="nop-url-display">{ url || '—' }</code>
			{ url && (
				<Button
					variant="secondary"
					size="small"
					onClick={ handleCopy }
					disabled={ copied }
				>
					{ copied ? __( 'Copied ✓', 'nop-indieweb' ) : __( 'Copy', 'nop-indieweb' ) }
				</Button>
			) }
		</div>
	);
}
