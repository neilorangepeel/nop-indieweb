import { __ } from '@wordpress/i18n';

export default function NetworkStatusCard( { label, status = {}, onConfigure } ) {
	const { active = false, color = '#c3c4c7', last_label = null } = status;
	const accentColor = active ? color : '#c3c4c7';

	return (
		<div
			className={ `nop-network-card ${ active ? 'nop-network-card--active' : 'nop-network-card--inactive' }` }
			style={ { '--nop-card-accent': accentColor } }
		>
			<div className="nop-network-card__header">
				<span className="nop-network-card__dot" />
				<strong className="nop-network-card__name">{ label }</strong>
			</div>
			<p className="nop-network-card__status">
				{ active ? __( 'Active', 'nop-indieweb' ) : __( 'Not configured', 'nop-indieweb' ) }
			</p>
			{ last_label && (
				<p className="nop-network-card__last">{ last_label }</p>
			) }
			<button
				type="button"
				className="nop-network-card__link button-link"
				onClick={ onConfigure }
			>
				{ active ? __( 'Configure', 'nop-indieweb' ) : __( 'Set up', 'nop-indieweb' ) }
				{ ' →' }
			</button>
		</div>
	);
}
