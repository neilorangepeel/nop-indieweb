import { useState, useEffect } from '@wordpress/element';
import { getSettings, updateSettings } from '../api';
import { __ } from '@wordpress/i18n';

export default function useSettings() {
	const [ settings, setSettings ] = useState( null );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null ); // { type: 'success'|'error', message: string }

	useEffect( () => {
		getSettings()
			.then( ( data ) => setSettings( data ) )
			.catch( () =>
				setNotice( {
					type: 'error',
					message: __( 'Could not load settings. Please reload the page.', 'nop-indieweb' ),
				} )
			);
	}, [] );

	const save = async () => {
		if ( ! settings ) {
			return;
		}
		setIsSaving( true );
		setNotice( null );
		try {
			await updateSettings( settings );
			setNotice( { type: 'success', message: __( 'Settings saved.', 'nop-indieweb' ) } );
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message: err?.message ?? __( 'Save failed. Please try again.', 'nop-indieweb' ),
			} );
		} finally {
			setIsSaving( false );
		}
	};

	const dismissNotice = () => setNotice( null );

	return { settings, setSettings, save, isSaving, notice, dismissNotice };
}
