import { ToggleControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const APPROVAL_OPTIONS = [
	{
		value: 'bridgy_only',
		label: __( 'Auto-approve Bridgy, hold everything else', 'nop-indieweb' ),
	},
	{
		value: 'auto_all',
		label: __( 'Auto-approve all', 'nop-indieweb' ),
	},
	{
		value: 'manual_all',
		label: __( 'Hold all for manual review', 'nop-indieweb' ),
	},
];

export default function ReactionsTab( { settings, setSettings } ) {
	const wm = settings.webmentions ?? {};

	const set = ( key, value ) =>
		setSettings( { ...settings, webmentions: { ...wm, [ key ]: value } } );

	return (
		<div className="nop-tab-content">
			<h3 className="nop-section-heading nop-section-heading--first">
				{ __( 'Webmentions', 'nop-indieweb' ) }
			</h3>

			<ToggleControl
				label={ __( 'Accept incoming webmentions', 'nop-indieweb' ) }
				help={ __( 'Receive likes, reposts, and replies from other IndieWeb sites.', 'nop-indieweb' ) }
				checked={ wm.receive_enabled ?? true }
				onChange={ ( val ) => set( 'receive_enabled', val ) }
				__nextHasNoMarginBottom
			/>

			{ ( wm.receive_enabled ?? true ) && (
				<SelectControl
					label={ __( 'Approval', 'nop-indieweb' ) }
					value={ wm.approval ?? 'bridgy_only' }
					options={ APPROVAL_OPTIONS }
					onChange={ ( val ) => set( 'approval', val ) }
					className="nop-approval-select"
					__nextHasNoMarginBottom
				/>
			) }
		</div>
	);
}
