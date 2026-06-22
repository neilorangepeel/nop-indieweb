import { useState } from '@wordpress/element';
import { PanelBody, ToggleControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import SecretInput from '../components/SecretInput';
import TestConnectionButton from '../components/TestConnectionButton';
import SyncNowButton from '../components/SyncNowButton';
import EndpointRow from '../components/EndpointRow';
import SyndicationHealth from '../components/SyndicationHealth';

// ——— Shared: inbound import section ————————————————————————————————————————

function ImportSection( { slug, value, onChange, importLabel } ) {
	const enabled = value.import_enabled ?? false;
	const set     = ( key, val ) => onChange( { ...value, [ key ]: val } );

	return (
		<div className="nop-network-import">
			<ToggleControl
				label={ importLabel }
				checked={ enabled }
				onChange={ ( val ) => set( 'import_enabled', val ) }
				__nextHasNoMarginBottom
			/>
			{ enabled && (
				<SyncNowButton
					platform={ slug }
					lastSyncedAt={ value.import_last_at ?? '' }
				/>
			) }
		</div>
	);
}

// ——— Panel title with status dot ——————————————————————————————————————————

function panelTitle( label, active ) {
	const dot = active
		? <span className="nop-panel-dot nop-panel-dot--active" aria-hidden="true" />
		: <span className="nop-panel-dot" aria-hidden="true" />;
	return <span className="nop-panel-title">{ dot }{ label }</span>;
}

// ——— Mastodon ——————————————————————————————————————————————————————————————

function MastodonPanel( { value, onChange, networkStatus, opened, onToggle } ) {
	const set     = ( key, val ) => onChange( { ...value, [ key ]: val } );
	const active  = networkStatus?.active ?? false;
	const canTest = !! ( value.instance && value.access_token );

	return (
		<PanelBody title={ panelTitle( 'Mastodon', active ) } opened={ opened } onToggle={ onToggle }>
			<div className="nop-network-panel">
				<ToggleControl
					label={ __( 'Syndicate posts to Mastodon on publish', 'nop-indieweb' ) }
					checked={ value.enabled ?? false }
					onChange={ ( val ) => set( 'enabled', val ) }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Instance URL', 'nop-indieweb' ) }
					value={ value.instance ?? '' }
					onChange={ ( val ) => set( 'instance', val ) }
					placeholder="https://mastodon.social"
					type="url"
					__nextHasNoMarginBottom
				/>
				<SecretInput
					label={ __( 'Access Token', 'nop-indieweb' ) }
					value={ value.access_token ?? '' }
					onChange={ ( val ) => set( 'access_token', val ) }
					help={ __( 'Preferences → Development → New Application. Needs write:statuses read:statuses scopes.', 'nop-indieweb' ) }
				/>
				<TestConnectionButton platform="mastodon" disabled={ ! canTest } />
				<ImportSection
					slug="mastodon"
					value={ value }
					onChange={ onChange }
					importLabel={ __( 'Import Mastodon posts hourly', 'nop-indieweb' ) }
				/>
			</div>
		</PanelBody>
	);
}

// ——— Bluesky ———————————————————————————————————————————————————————————————

function BlueskyPanel( { value, onChange, networkStatus, opened, onToggle } ) {
	const set     = ( key, val ) => onChange( { ...value, [ key ]: val } );
	const active  = networkStatus?.active ?? false;
	const canTest = !! ( value.handle && value.app_password );

	return (
		<PanelBody title={ panelTitle( 'Bluesky', active ) } opened={ opened } onToggle={ onToggle }>
			<div className="nop-network-panel">
				<ToggleControl
					label={ __( 'Syndicate posts to Bluesky on publish', 'nop-indieweb' ) }
					checked={ value.enabled ?? false }
					onChange={ ( val ) => set( 'enabled', val ) }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Handle', 'nop-indieweb' ) }
					value={ value.handle ?? '' }
					onChange={ ( val ) => set( 'handle', val ) }
					placeholder="you.bsky.social"
					__nextHasNoMarginBottom
				/>
				<SecretInput
					label={ __( 'App Password', 'nop-indieweb' ) }
					value={ value.app_password ?? '' }
					onChange={ ( val ) => set( 'app_password', val ) }
					help={ __( 'Settings → Privacy and Security → App Passwords. Never your main password.', 'nop-indieweb' ) }
				/>
				<TestConnectionButton platform="bluesky" disabled={ ! canTest } />
				<ImportSection
					slug="bluesky"
					value={ value }
					onChange={ onChange }
					importLabel={ __( 'Import Bluesky posts hourly', 'nop-indieweb' ) }
				/>
			</div>
		</PanelBody>
	);
}

// ——— Pixelfed ——————————————————————————————————————————————————————————————

function PixelfedPanel( { value, onChange, networkStatus, opened, onToggle } ) {
	const set     = ( key, val ) => onChange( { ...value, [ key ]: val } );
	const active  = networkStatus?.active ?? false;
	const canTest = !! ( value.instance && value.access_token );

	return (
		<PanelBody title={ panelTitle( 'Pixelfed', active ) } opened={ opened } onToggle={ onToggle }>
			<div className="nop-network-panel">
				<ToggleControl
					label={ __( 'Syndicate posts to Pixelfed on publish', 'nop-indieweb' ) }
					checked={ value.enabled ?? false }
					onChange={ ( val ) => set( 'enabled', val ) }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Instance URL', 'nop-indieweb' ) }
					value={ value.instance ?? '' }
					onChange={ ( val ) => set( 'instance', val ) }
					placeholder="https://pixelfed.social"
					type="url"
					__nextHasNoMarginBottom
				/>
				<SecretInput
					label={ __( 'Access Token', 'nop-indieweb' ) }
					value={ value.access_token ?? '' }
					onChange={ ( val ) => set( 'access_token', val ) }
					help={ __( 'Settings → Applications → Create New Token.', 'nop-indieweb' ) }
				/>
				<TestConnectionButton platform="pixelfed" disabled={ ! canTest } />
				<ImportSection
					slug="pixelfed"
					value={ value }
					onChange={ onChange }
					importLabel={ __( 'Import Pixelfed posts hourly', 'nop-indieweb' ) }
				/>
			</div>
		</PanelBody>
	);
}

// ——— Tumblr ————————————————————————————————————————————————————————————————

function TumblrPanel( { value, onChange, networkStatus, opened, onToggle } ) {
	const set       = ( key, val ) => onChange( { ...value, [ key ]: val } );
	const active    = networkStatus?.active ?? false;
	const connected = networkStatus?.connected ?? false;
	// Connecting needs the app credentials saved first (the callback reads them).
	const canConnect = !! ( value.consumer_key && value.consumer_secret && value.blog_identifier );

	return (
		<PanelBody title={ panelTitle( 'Tumblr', active ) } opened={ opened } onToggle={ onToggle }>
			<div className="nop-network-panel">
				<ToggleControl
					label={ __( 'Syndicate posts to Tumblr on publish', 'nop-indieweb' ) }
					checked={ value.enabled ?? false }
					onChange={ ( val ) => set( 'enabled', val ) }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Blog identifier', 'nop-indieweb' ) }
					value={ value.blog_identifier ?? '' }
					onChange={ ( val ) => set( 'blog_identifier', val ) }
					placeholder="yourblog.tumblr.com"
					help={ __( 'Your blog hostname, e.g. yourblog.tumblr.com (or a custom domain).', 'nop-indieweb' ) }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Consumer Key', 'nop-indieweb' ) }
					value={ value.consumer_key ?? '' }
					onChange={ ( val ) => set( 'consumer_key', val ) }
					help={ __( 'Register an app at tumblr.com/oauth/apps. Callback must be the URL below.', 'nop-indieweb' ) }
					__nextHasNoMarginBottom
				/>
				<SecretInput
					label={ __( 'Consumer Secret', 'nop-indieweb' ) }
					value={ value.consumer_secret ?? '' }
					onChange={ ( val ) => set( 'consumer_secret', val ) }
				/>
				<EndpointRow
					label={ __( 'OAuth callback URL', 'nop-indieweb' ) }
					url={ networkStatus?.callbackUrl ?? '' }
				/>
				<p className="description">
					{ connected
						? ( networkStatus?.userName
							? `${ __( 'Connected as', 'nop-indieweb' ) } ${ networkStatus.userName }`
							: __( 'Connected.', 'nop-indieweb' ) )
						: __( 'Save the credentials above, then connect.', 'nop-indieweb' ) }
				</p>
				<a
					className="components-button is-secondary"
					href={ canConnect ? ( networkStatus?.authUrl ?? '#' ) : undefined }
					aria-disabled={ ! canConnect }
				>
					{ connected ? __( 'Reconnect Tumblr', 'nop-indieweb' ) : __( 'Connect Tumblr', 'nop-indieweb' ) }
				</a>
				<ImportSection
					slug="tumblr"
					value={ value }
					onChange={ onChange }
					importLabel={ __( 'Import Tumblr posts hourly', 'nop-indieweb' ) }
				/>
			</div>
		</PanelBody>
	);
}

// ——— Letterboxd ————————————————————————————————————————————————————————————

function LetterboxdPanel( { value, onChange, networkStatus, opened, onToggle } ) {
	const set    = ( key, val ) => onChange( { ...value, [ key ]: val } );
	const active = networkStatus?.active ?? false;

	return (
		<PanelBody title={ panelTitle( 'Letterboxd', active ) } opened={ opened } onToggle={ onToggle }>
			<div className="nop-network-panel">
				<ToggleControl
					label={ __( 'Import Letterboxd diary hourly', 'nop-indieweb' ) }
					checked={ value.import_enabled ?? false }
					onChange={ ( val ) => set( 'import_enabled', val ) }
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Username', 'nop-indieweb' ) }
					value={ value.username ?? '' }
					onChange={ ( val ) => set( 'username', val ) }
					placeholder="your-letterboxd-username"
					__nextHasNoMarginBottom
				/>
				{ ( value.import_enabled ?? false ) && (
					<SyncNowButton
						platform="letterboxd"
						lastSyncedAt={ value.import_last_at ?? '' }
					/>
				) }
			</div>
		</PanelBody>
	);
}

// ——— Swarm —————————————————————————————————————————————————————————————————

function SwarmPanel( { value, onChange, networkStatus, opened, onToggle } ) {
	const set    = ( key, val ) => onChange( { ...value, [ key ]: val } );
	const active = networkStatus?.active ?? false;
	return (
		<PanelBody title={ panelTitle( 'Swarm', active ) } opened={ opened } onToggle={ onToggle }>
			<div className="nop-network-panel">
				<ToggleControl
					label={ __( 'Accept check-ins from OwnYourSwarm', 'nop-indieweb' ) }
					checked={ value.enabled ?? false }
					onChange={ ( val ) => set( 'enabled', val ) }
					__nextHasNoMarginBottom
				/>
				<EndpointRow
					label={ __( 'Micropub endpoint', 'nop-indieweb' ) }
					url={ networkStatus?.micropubUrl ?? '' }
				/>
				<p className="description">
					{ __( 'Point OwnYourSwarm at the Micropub endpoint above to receive check-ins automatically.', 'nop-indieweb' ) }
				</p>
			</div>
		</PanelBody>
	);
}

// ——— NetworksTab ———————————————————————————————————————————————————————————

export default function NetworksTab( { settings, setSettings, targetNetwork } ) {
	const syndicators   = settings.syndicators   ?? {};
	const services      = settings.services      ?? {};
	const networkStatus = settings._meta?.network_status ?? {};

	const [ openPanels, setOpenPanels ] = useState( {
		mastodon:   targetNetwork === 'mastodon',
		bluesky:    targetNetwork === 'bluesky',
		pixelfed:   targetNetwork === 'pixelfed',
		tumblr:     targetNetwork === 'tumblr',
		letterboxd: targetNetwork === 'letterboxd',
		swarm:      targetNetwork === 'swarm',
	} );

	const toggle = ( slug ) => ( val ) =>
		setOpenPanels( ( prev ) => ( { ...prev, [ slug ]: val } ) );

	const setSyndicator = ( slug ) => ( val ) =>
		setSettings( { ...settings, syndicators: { ...syndicators, [ slug ]: val } } );

	const setService = ( slug ) => ( val ) =>
		setSettings( { ...settings, services: { ...services, [ slug ]: val } } );

	return (
		<div className="nop-tab-content nop-networks-tab">
			<SyndicationHealth />
			<MastodonPanel
				value={ syndicators.mastodon   ?? {} }
				onChange={ setSyndicator( 'mastodon' ) }
				networkStatus={ networkStatus.mastodon }
				opened={ openPanels.mastodon }
				onToggle={ toggle( 'mastodon' ) }
			/>
			<BlueskyPanel
				value={ syndicators.bluesky    ?? {} }
				onChange={ setSyndicator( 'bluesky' ) }
				networkStatus={ networkStatus.bluesky }
				opened={ openPanels.bluesky }
				onToggle={ toggle( 'bluesky' ) }
			/>
			<PixelfedPanel
				value={ syndicators.pixelfed   ?? {} }
				onChange={ setSyndicator( 'pixelfed' ) }
				networkStatus={ networkStatus.pixelfed }
				opened={ openPanels.pixelfed }
				onToggle={ toggle( 'pixelfed' ) }
			/>
			<TumblrPanel
				value={ syndicators.tumblr     ?? {} }
				onChange={ setSyndicator( 'tumblr' ) }
				networkStatus={ networkStatus.tumblr }
				opened={ openPanels.tumblr }
				onToggle={ toggle( 'tumblr' ) }
			/>
			<LetterboxdPanel
				value={ services.letterboxd    ?? {} }
				onChange={ setService( 'letterboxd' ) }
				networkStatus={ networkStatus.letterboxd }
				opened={ openPanels.letterboxd }
				onToggle={ toggle( 'letterboxd' ) }
			/>
			<SwarmPanel
				value={ services.swarm         ?? {} }
				onChange={ setService( 'swarm' ) }
				networkStatus={ networkStatus.swarm }
				opened={ openPanels.swarm }
				onToggle={ toggle( 'swarm' ) }
			/>
		</div>
	);
}
