import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const SENTINEL = '__redacted__';

/**
 * Password-style input for API keys and tokens.
 *
 * When value is the sentinel '__redacted__', the field renders empty with a
 * placeholder — the actual credential is never sent to the browser, so Show
 * is not offered. Typing a new value replaces the sentinel; saving with the
 * sentinel intact preserves the stored credential.
 */
export default function SecretInput( { label, value, onChange, help, id } ) {
	const [ revealed, setRevealed ] = useState( false );

	const isRedacted   = value === SENTINEL;
	const displayValue = isRedacted ? '' : ( value ?? '' );
	const placeholder  = isRedacted
		? __( '••••••••  (stored — type to replace)', 'nop-indieweb' )
		: '';
	const inputId = id ?? `nop-secret-${ label.toLowerCase().replace( /\s+/g, '-' ) }`;

	return (
		<div className="nop-secret-field">
			{ label && (
				<label htmlFor={ inputId } className="nop-secret-field__label">
					{ label }
				</label>
			) }
			<div className="nop-secret-field__row">
				<input
					id={ inputId }
					type={ ( ! isRedacted && revealed ) ? 'text' : 'password' }
					className="nop-secret-field__input regular-text code"
					value={ displayValue }
					placeholder={ placeholder }
					autoComplete="off"
					spellCheck={ false }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
				{ ! isRedacted && (
					<Button
						variant="secondary"
						size="small"
						onClick={ () => setRevealed( ( r ) => ! r ) }
						aria-label={ revealed
							? __( 'Hide value', 'nop-indieweb' )
							: __( 'Show value', 'nop-indieweb' )
						}
					>
						{ revealed ? __( 'Hide', 'nop-indieweb' ) : __( 'Show', 'nop-indieweb' ) }
					</Button>
				) }
			</div>
			{ help && <p className="nop-secret-field__help description">{ help }</p> }
		</div>
	);
}
