import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import PerSourceDefaults from '../components/PerSourceDefaults';
import InteractionKindsTable from '../components/InteractionKindsTable';

const PER_SOURCE = [
	{
		slug:        'mastodon',
		section:     'syndicators',
		label:       'Mastodon',
		showPhotos:  true,
		photosLabel: __( 'Save imported photos to media library', 'nop-indieweb' ),
	},
	{
		slug:        'bluesky',
		section:     'syndicators',
		label:       'Bluesky',
		showPhotos:  true,
		photosLabel: __( 'Save imported photos to media library', 'nop-indieweb' ),
	},
	{
		slug:        'pixelfed',
		section:     'syndicators',
		label:       'Pixelfed',
		showPhotos:  true,
		photosLabel: __( 'Save imported photos to media library', 'nop-indieweb' ),
	},
	{
		slug:        'letterboxd',
		section:     'services',
		label:       'Letterboxd',
		showPhotos:  true,
		photosLabel: __( 'Save film poster to media library', 'nop-indieweb' ),
	},
	{
		slug:        'swarm',
		section:     'services',
		label:       'Swarm',
		showPhotos:  true,
		photosLabel: __( 'Save check-in photos to media library', 'nop-indieweb' ),
	},
];

export default function ContentTab( { settings, setSettings } ) {
	const syndicators = settings.syndicators ?? {};
	const services    = settings.services    ?? {};

	const setEntries = ( val ) =>
		setSettings( { ...settings, services: { ...services, entries: val } } );

	const setEntriesKey = ( key, val ) =>
		setEntries( { ...( services.entries ?? {} ), [ key ]: val } );

	const setServices = ( val ) =>
		setSettings( { ...settings, services: val } );

	const setSource = ( section, slug ) => ( val ) => {
		if ( section === 'syndicators' ) {
			setSettings( { ...settings, syndicators: { ...syndicators, [ slug ]: val } } );
		} else {
			setSettings( { ...settings, services: { ...services, [ slug ]: val } } );
		}
	};

	const getSource = ( section, slug ) =>
		section === 'syndicators' ? ( syndicators[ slug ] ?? {} ) : ( services[ slug ] ?? {} );

	return (
		<div className="nop-tab-content">

			<h3 className="nop-section-heading nop-section-heading--first">
				{ __( 'Notes & Entries', 'nop-indieweb' ) }
			</h3>
			<p className="description nop-section-intro">
				{ __( 'Defaults for Micropub notes and manually created posts.', 'nop-indieweb' ) }
			</p>
			<div className="nop-field-group">
				<ToggleControl
					label={ __( 'Enable', 'nop-indieweb' ) }
					checked={ services.entries?.enabled ?? true }
					onChange={ ( val ) => setEntriesKey( 'enabled', val ) }
					__nextHasNoMarginBottom
				/>
				<PerSourceDefaults
					value={ services.entries ?? {} }
					onChange={ setEntries }
					showPhotos
					photosLabel={ __( 'Save incoming photos to media library', 'nop-indieweb' ) }
				/>
			</div>

			<h3 className="nop-section-heading">
				{ __( 'Interaction kinds', 'nop-indieweb' ) }
			</h3>
			<p className="description nop-section-intro">
				{ __( 'Enable or disable each kind and choose where it lands.', 'nop-indieweb' ) }
			</p>
			<InteractionKindsTable services={ services } onChange={ setServices } />

			<h3 className="nop-section-heading">
				{ __( 'Per-source defaults', 'nop-indieweb' ) }
			</h3>
			<p className="description nop-section-intro">
				{ __( 'Override status, category, and tags for posts imported from each network.', 'nop-indieweb' ) }
			</p>
			{ PER_SOURCE.map( ( { slug, section, label, showPhotos, photosLabel } ) => (
				<div key={ slug } className="nop-per-source">
					<h4 className="nop-per-source__label">{ label }</h4>
					<PerSourceDefaults
						value={ getSource( section, slug ) }
						onChange={ setSource( section, slug ) }
						showPhotos={ showPhotos }
						photosLabel={ photosLabel }
					/>
				</div>
			) ) }

		</div>
	);
}
