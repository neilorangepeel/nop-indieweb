import { ToggleControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import SecretInput from '../components/SecretInput';
import SessionsTable from '../components/SessionsTable';

const ENRICHMENT_KEYS = [
	{
		section: 'maps',
		key:     'geoapify_api_key',
		label:   __( 'Geoapify API key', 'nop-indieweb' ),
		help:    __( 'Generates static map images for check-in posts. Free tier: 3,000 requests/day.', 'nop-indieweb' ),
	},
	{
		section: 'weather',
		key:     'pirate_weather_api_key',
		label:   __( 'Pirate Weather API key', 'nop-indieweb' ),
		help:    __( 'Snapshots weather conditions at each check-in. Free tier: 10,000 requests/day.', 'nop-indieweb' ),
	},
	{
		section: 'venue',
		key:     'foursquare_api_key',
		label:   __( 'Foursquare API key', 'nop-indieweb' ),
		help:    __( 'Looks up venue categories for check-ins. Results are cached for 30 days.', 'nop-indieweb' ),
	},
	{
		section: 'lookups',
		key:     'tmdb_api_key',
		label:   __( 'TMDB API key', 'nop-indieweb' ),
		help:    __( 'Film metadata for watch posts. Free from themoviedb.org.', 'nop-indieweb' ),
	},
];

export default function AdvancedTab( { settings, setSettings } ) {
	const setSection = ( section, key, value ) =>
		setSettings( {
			...settings,
			[ section ]: { ...( settings[ section ] ?? {} ), [ key ]: value },
		} );

	const set = ( key, value ) => setSettings( { ...settings, [ key ]: value } );

	return (
		<div className="nop-tab-content">

			<h3 className="nop-section-heading nop-section-heading--first">
				{ __( 'Enrichment APIs', 'nop-indieweb' ) }
			</h3>
			<p className="description nop-section-intro">
				{ __( 'Optional third-party services that enrich check-in and watch posts. None are required.', 'nop-indieweb' ) }
			</p>
			<div className="nop-field-group">
				{ ENRICHMENT_KEYS.map( ( { section, key, label, help } ) => (
					<SecretInput
						key={ key }
						label={ label }
						value={ settings[ section ]?.[ key ] ?? '' }
						onChange={ ( val ) => setSection( section, key, val ) }
						help={ help }
					/>
				) ) }
			</div>

			<h3 className="nop-section-heading">
				{ __( 'Site', 'nop-indieweb' ) }
			</h3>
			<div className="nop-field-group">
				<ToggleControl
					label={ __( 'Microformats2 markup', 'nop-indieweb' ) }
					help={ __( 'Add mf2 classes to IndieWeb post templates for machine-readable metadata.', 'nop-indieweb' ) }
					checked={ settings.mf2_enabled ?? true }
					onChange={ ( val ) => set( 'mf2_enabled', val ) }
					__nextHasNoMarginBottom
				/>
				<ToggleControl
					label={ __( 'Block AI training crawlers', 'nop-indieweb' ) }
					help={ __( 'Opt your content and images out of AI model training via robots.txt rules and noai meta tags. Honoured by OpenAI, Anthropic, Google, Apple, and Common Crawl; ignored by bad actors. Does not affect search engines or normal visitors.', 'nop-indieweb' ) }
					checked={ settings.block_ai_training ?? false }
					onChange={ ( val ) => set( 'block_ai_training', val ) }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Twitter archive URL', 'nop-indieweb' ) }
					value={ settings.twitter_archive_url ?? '' }
					onChange={ ( val ) => set( 'twitter_archive_url', val ) }
					type="url"
					placeholder="https://yoursite.com/twitter-archive/"
					help={ __( 'Optional link shown on imported tweet posts.', 'nop-indieweb' ) }
					className="nop-url-field"
					__nextHasNoMarginBottom
				/>
			</div>

			<h3 className="nop-section-heading">
				{ __( 'Developer', 'nop-indieweb' ) }
			</h3>
			<div className="nop-field-group">
				<ToggleControl
					label={ __( 'Debug mode', 'nop-indieweb' ) }
					help={ __( 'Write plugin activity to wp-content/debug.log when WP_DEBUG_LOG is enabled.', 'nop-indieweb' ) }
					checked={ settings.debug_mode ?? false }
					onChange={ ( val ) => set( 'debug_mode', val ) }
					__nextHasNoMarginBottom
				/>
			</div>

			<h3 className="nop-section-heading">
				{ __( 'Active Sessions', 'nop-indieweb' ) }
			</h3>
			<SessionsTable />

		</div>
	);
}
