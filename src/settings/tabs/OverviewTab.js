import { TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import NetworkStatusCard from '../components/NetworkStatusCard';
import EndpointRow from '../components/EndpointRow';

const NETWORKS = [
	{ slug: 'mastodon',   label: 'Mastodon' },
	{ slug: 'bluesky',    label: 'Bluesky' },
	{ slug: 'pixelfed',   label: 'Pixelfed' },
	{ slug: 'letterboxd', label: 'Letterboxd' },
	{ slug: 'swarm',      label: 'Swarm' },
];

const ENDPOINTS = [
	{ label: __( 'Micropub',      'nop-indieweb' ), key: 'micropub' },
	{ label: __( 'Webmention',    'nop-indieweb' ), key: 'webmention' },
	{ label: __( 'Authorization', 'nop-indieweb' ), key: 'authorization' },
	{ label: __( 'Token',         'nop-indieweb' ), key: 'token' },
	{ label: __( 'Microformats',  'nop-indieweb' ), key: 'mf2' },
];

export default function OverviewTab( { settings, setSettings, onTabSwitch } ) {
	const meta         = settings._meta ?? {};
	const networkStatus = meta.network_status ?? {};
	const endpoints    = meta.endpoints ?? {};
	const stats        = meta.reaction_stats ?? {};
	const pending      = stats.pending ?? 0;

	return (
		<div className="nop-tab-content">

			<h3 className="nop-section-heading nop-section-heading--first">
				{ __( 'Networks', 'nop-indieweb' ) }
			</h3>
			<div className="nop-network-cards">
				{ NETWORKS.map( ( n ) => (
					<NetworkStatusCard
						key={ n.slug }
						label={ n.label }
						status={ networkStatus[ n.slug ] }
						onConfigure={ () => onTabSwitch( 'networks' ) }
					/>
				) ) }
			</div>

			<h3 className="nop-section-heading">
				{ __( 'Reactions', 'nop-indieweb' ) }
			</h3>
			<div className="nop-reaction-stats">
				<div className="nop-reaction-stat">
					<span className="nop-reaction-stat__num">{ stats.likes ?? 0 }</span>
					<span className="nop-reaction-stat__label">{ __( 'Likes', 'nop-indieweb' ) }</span>
				</div>
				<div className="nop-reaction-stat">
					<span className="nop-reaction-stat__num">{ stats.comments ?? 0 }</span>
					<span className="nop-reaction-stat__label">{ __( 'Comments', 'nop-indieweb' ) }</span>
				</div>
				<div className="nop-reaction-stat">
					<span className="nop-reaction-stat__num">{ stats.reposts ?? 0 }</span>
					<span className="nop-reaction-stat__label">{ __( 'Reposts', 'nop-indieweb' ) }</span>
				</div>
				{ pending > 0 && (
					<div className="nop-reaction-stat nop-reaction-stat--pending">
						<span className="nop-reaction-stat__num">{ pending }</span>
						<span className="nop-reaction-stat__label">
							<a href={ ( window.nopIndieWebSettings?.adminUrl ?? '' ) + 'edit-comments.php?comment_status=moderated&comment_type=webmention' }
							>
								{ __( 'Pending', 'nop-indieweb' ) }
							</a>
						</span>
					</div>
				) }
			</div>

			<h3 className="nop-section-heading">
				{ __( 'Profile URLs', 'nop-indieweb' ) }
			</h3>
			<TextareaControl
				value={ settings.me_urls ?? '' }
				onChange={ ( val ) => setSettings( { ...settings, me_urls: val } ) }
				rows={ 4 }
				className="nop-me-urls"
				help={ __( 'One URL per line. Output as <link rel="me"> in your site head for IndieAuth and identity verification.', 'nop-indieweb' ) }
				__nextHasNoMarginBottom
			/>

			<h3 className="nop-section-heading">
				{ __( 'Endpoints', 'nop-indieweb' ) }
			</h3>
			<div className="nop-endpoint-rows">
				{ ENDPOINTS.map( ( e ) => (
					<EndpointRow
						key={ e.key }
						label={ e.label }
						url={ endpoints[ e.key ] ?? '' }
					/>
				) ) }
			</div>

		</div>
	);
}
