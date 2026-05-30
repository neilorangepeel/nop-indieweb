import { __ } from '@wordpress/i18n';

export default function App() {
	return (
		<div className="nop-settings-app">
			<p>{ __( 'Settings loading…', 'nop-indieweb' ) }</p>
		</div>
	);
}
