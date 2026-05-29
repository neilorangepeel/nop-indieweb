<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Admin;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\IndieAuth\Token_Store;
use NOP\IndieWeb\IndieAuth\Auth_Endpoint;

/**
 * Settings page at Settings > IndieWeb.
 *
 * Tabs are switched via JS (no page reload) so all fields are always present
 * in the form — no data loss when moving between tabs before saving.
 *
 * Adding a new service tab:
 *   1. Add an entry to TABS.
 *   2. Add a render_tab_{slug}() method.
 *   3. Add its fields to sanitize().
 */
class Settings {

	private const OPTION_KEY = 'nop_indieweb_settings';
	private const PAGE_SLUG  = 'nop-indieweb-settings';

	private const VALID_STATUS_KEYS = [ 'publish', 'draft', 'private' ];

	/**
	 * Post-status slug → translated label. A method rather than a const because
	 * __() can't run in a constant expression (and the .pot scanner only picks
	 * up string literals passed to the translation functions).
	 */
	private static function post_statuses(): array {
		return [
			'publish' => __( 'Published', 'nop-indieweb' ),
			'draft'   => __( 'Draft', 'nop-indieweb' ),
			'private' => __( 'Private', 'nop-indieweb' ),
		];
	}

	/**
	 * Tab groups: group key → [ slug => translated label ]. Method (not a const)
	 * for the same i18n reason as post_statuses(). The empty-string and group-name
	 * keys are internal routing values, not shown verbatim, so they stay literal.
	 */
	private static function tab_groups(): array {
		return [
			''         => [ 'overview' => __( 'Overview', 'nop-indieweb' ) ],
			'Networks' => [
				'mastodon'   => __( 'Mastodon', 'nop-indieweb' ),
				'bluesky'    => __( 'Bluesky', 'nop-indieweb' ),
				'pixelfed'   => __( 'Pixelfed', 'nop-indieweb' ),
				'letterboxd' => __( 'Letterboxd', 'nop-indieweb' ),
				'swarm'      => __( 'Swarm', 'nop-indieweb' ),
			],
			'Content'  => [
				'publishing' => __( 'Publishing', 'nop-indieweb' ),
				'reactions'  => __( 'Reactions', 'nop-indieweb' ),
				'lookups'    => __( 'Lookups', 'nop-indieweb' ),
			],
			'Advanced' => [ 'advanced' => __( 'Advanced', 'nop-indieweb' ) ],
		];
	}

	public function register(): void {
		add_action( 'admin_menu',            [ $this, 'add_page' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_init',            [ $this, 'maybe_handle_revoke' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nop_test_connection', [ $this, 'ajax_test_connection' ] );
	}

	public function ajax_test_connection(): void {
		check_ajax_referer( 'nop_test_connection' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'nop-indieweb' ), 403 );
		}

		$slug      = sanitize_key( $_POST['service'] ?? '' );
		$manager   = \NOP\IndieWeb\Plugin::get_instance()->syndication_manager();
		$syndicator = $manager ? $manager->get( $slug ) : null;

		if ( ! $syndicator ) {
			wp_send_json_error( __( 'Unknown service.', 'nop-indieweb' ) );
		}

		$result = $syndicator->test_connection();
		if ( $result['ok'] ) {
			wp_send_json_success( $result['message'] );
		}
		wp_send_json_error( $result['message'] );
	}

	public function add_page(): void {
		add_options_page(
			__( 'IndieWeb Settings', 'nop-indieweb' ),
			__( 'IndieWeb', 'nop-indieweb' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( self::OPTION_KEY, self::OPTION_KEY, [
			'sanitize_callback' => [ $this, 'sanitize' ],
		] );
	}

	// ——— Token revocation (GET link + nonce, no nested form needed) ———————————

	public function maybe_handle_revoke(): void {
		if ( ! isset( $_GET['nop_revoke_token'] ) ) {
			return;
		}

		$token_id = absint( $_GET['nop_revoke_token'] );
		check_admin_referer( 'nop_revoke_token_' . $token_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'nop-indieweb' ), '', [ 'response' => 403 ] );
		}

		Token_Store::delete_by_id( $token_id );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '#nop-tab-advanced' ) );
		exit;
	}

	// ——— Settings sanitize ————————————————————————————————————————————————————

	private function sanitize_status( string $value, string $fallback = 'publish' ): string {
		return in_array( $value, self::VALID_STATUS_KEYS, true ) ? $value : $fallback;
	}

	/**
	 * Sanitizes the common service/syndicator block: enabled, status, category, tags, sideload.
	 * $defaults supplies text fallbacks when a field is missing from $in.
	 */
	private function sanitize_service_defaults( array $in, array $defaults = [] ): array {
		return [
			'enabled'         => ! empty( $in['enabled'] ),
			'post_status'     => $this->sanitize_status( $in['post_status'] ?? '' ),
			'post_category'   => sanitize_text_field( $in['post_category'] ?? ( $defaults['post_category'] ?? '' ) ),
			'post_tags'       => sanitize_text_field( $in['post_tags'] ?? ( $defaults['post_tags'] ?? '' ) ),
			'sideload_photos' => ! empty( $in['sideload_photos'] ),
		];
	}

	public function sanitize( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return get_option( self::OPTION_KEY, [] );
		}

		$clean = get_option( self::OPTION_KEY, [] );

		// Ensure the legacy static token is gone.
		unset( $clean['secret_token'] );

		// — General ———————————————————————————————————————————————————————————
		$clean['debug_mode']  = ! empty( $input['debug_mode'] );
		$clean['me_urls']     = sanitize_textarea_field( $input['me_urls'] ?? '' );

		// — Semantic Web ——————————————————————————————————————————————————————
		$clean['mf2_enabled'] = ! empty( $input['mf2_enabled'] );

		// — Maps ——————————————————————————————————————————————————————————————
		$clean['maps']['geoapify_api_key'] = sanitize_text_field( $input['maps']['geoapify_api_key'] ?? '' );

		// — Weather ———————————————————————————————————————————————————————————
		$clean['weather']['pirate_weather_api_key'] = sanitize_text_field( $input['weather']['pirate_weather_api_key'] ?? '' );

		// — Venue ————————————————————————————————————————————————————————————
		$clean['venue']['foursquare_api_key'] = sanitize_text_field( $input['venue']['foursquare_api_key'] ?? '' );

		// — Swarm —————————————————————————————————————————————————————————————
		$clean['services']['swarm'] = $this->sanitize_service_defaults( $input['services']['swarm'] ?? [], [
			'enabled'         => false,
			'post_tags'       => 'Swarm',
			'sideload_photos' => true,
		] );

		// — Entries ———————————————————————————————————————————————————————————————
		$clean['services']['entries'] = $this->sanitize_service_defaults( $input['services']['entries'] ?? [], [
			'enabled'         => false,
			'sideload_photos' => true,
		] );

		// — Syndicators ——————————————————————————————————————————————————————————
		foreach ( self::get_syndicator_config() as $slug => $config ) {
			$in  = $input['syndicators'][ $slug ] ?? [];
			$out = $this->sanitize_service_defaults( $in, [ 'sideload_photos' => false ] );
			$out['import_enabled'] = ! empty( $in['import_enabled'] );

			foreach ( $config['fields'] as $key => $field ) {
				$value      = (string) ( $in[ $key ] ?? '' );
				$out[ $key ] = ( ( $field['type'] ?? '' ) === 'url' )
					? esc_url_raw( $value )
					: sanitize_text_field( $value );
			}
			// Merge, don't replace — preserves keys (e.g. last_synced_at) written
			// outside this sanitize path by background jobs.
			$clean['syndicators'][ $slug ] = array_merge( $clean['syndicators'][ $slug ] ?? [], $out );
		}

		// — Post Kinds ————————————————————————————————————————————————————————————
		foreach ( [ 'bookmark', 'reply', 'like', 'repost', 'rsvp' ] as $kind ) {
			$clean['services'][ $kind ]['enabled']       = ! empty( $input['services'][ $kind ]['enabled'] );
			$clean['services'][ $kind ]['post_status']   = $this->sanitize_status( $input['services'][ $kind ]['post_status'] ?? '' );
			$clean['services'][ $kind ]['post_category'] = sanitize_text_field( $input['services'][ $kind ]['post_category'] ?? '' );
		}

		// — Webmentions ——————————————————————————————————————————————————————————
		$valid_approval = [ 'bridgy_only', 'auto_all', 'manual_all' ];

		$clean['webmentions']['receive_enabled'] = ! empty( $input['webmentions']['receive_enabled'] );
		$clean['webmentions']['approval']        = in_array( $input['webmentions']['approval'] ?? '', $valid_approval, true )
			? $input['webmentions']['approval']
			: 'bridgy_only';

		// — Letterboxd ————————————————————————————————————————————————————————————
		$lb = $input['services']['letterboxd'] ?? [];
		$clean['services']['letterboxd'] = [
			'import_enabled'  => ! empty( $lb['import_enabled'] ),
			'username'        => sanitize_text_field( $lb['username'] ?? '' ),
			'post_status'     => $this->sanitize_status( $lb['post_status'] ?? '' ),
			'post_category'   => sanitize_text_field( $lb['post_category'] ?? 'Films' ),
			'post_tags'       => sanitize_text_field( $lb['post_tags'] ?? '' ),
			'sideload_poster' => ! empty( $lb['sideload_poster'] ),
		];

		// — Lookups ———————————————————————————————————————————————————————————————
		$clean['lookups']['tmdb_api_key'] = sanitize_text_field( $input['lookups']['tmdb_api_key'] ?? '' );

		// — Twitter Archive ——————————————————————————————————————————————————————
		$clean['twitter_archive_url'] = esc_url_raw( $input['twitter_archive_url'] ?? '' );

		return $clean;
	}

	// ——— Page render ——————————————————————————————————————————————————————————

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap nop-indieweb-settings">

			<h1><?php esc_html_e( 'IndieWeb', 'nop-indieweb' ); ?></h1>

			<div class="nav-tab-wrapper nop-nav-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'nop-indieweb' ); ?>">
				<?php foreach ( self::tab_groups() as $tabs ) : ?>
					<?php foreach ( $tabs as $slug => $label ) : ?>
						<?php $tab_enabled = $this->is_tab_enabled( $slug ); ?>
						<a href="#nop-tab-<?php echo esc_attr( $slug ); ?>"
						   id="nop-tablabel-<?php echo esc_attr( $slug ); ?>"
						   role="tab"
						   aria-controls="nop-tab-<?php echo esc_attr( $slug ); ?>"
						   aria-selected="false"
						   tabindex="-1"
						   class="nav-tab<?php
								echo ! $tab_enabled ? ' nop-tab--inactive' : '';
								echo 'advanced' === $slug ? ' nop-tab--advanced' : '';
								echo 'publishing' === $slug ? ' nop-tab--group-start' : '';
						   ?>"
						   <?php if ( ! $tab_enabled ) : ?>title="<?php
						   	/* translators: %s: settings tab name, e.g. Mastodon */
						   	echo esc_attr( sprintf( __( '%s is not enabled', 'nop-indieweb' ), $label ) );
						   ?>"<?php endif; ?>>
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</div>

			<form method="post" action="options.php" class="nop-settings-form">
				<?php settings_fields( self::OPTION_KEY ); ?>

				<div id="nop-tab-overview" class="nop-tab-panel" role="tabpanel" aria-labelledby="nop-tablabel-overview" tabindex="0">
					<?php $this->render_tab_overview(); ?>
				</div>

				<div id="nop-tab-mastodon" class="nop-tab-panel" role="tabpanel" aria-labelledby="nop-tablabel-mastodon" tabindex="0" hidden>
					<?php $this->render_tab_mastodon(); ?>
				</div>

				<div id="nop-tab-bluesky" class="nop-tab-panel" role="tabpanel" aria-labelledby="nop-tablabel-bluesky" tabindex="0" hidden>
					<?php $this->render_tab_bluesky(); ?>
				</div>

				<div id="nop-tab-pixelfed" class="nop-tab-panel" role="tabpanel" aria-labelledby="nop-tablabel-pixelfed" tabindex="0" hidden>
					<?php $this->render_tab_pixelfed(); ?>
				</div>

				<div id="nop-tab-letterboxd" class="nop-tab-panel" role="tabpanel" aria-labelledby="nop-tablabel-letterboxd" tabindex="0" hidden>
					<?php $this->render_tab_letterboxd(); ?>
				</div>

				<div id="nop-tab-swarm" class="nop-tab-panel" role="tabpanel" aria-labelledby="nop-tablabel-swarm" tabindex="0" hidden>
					<?php $this->render_tab_swarm(); ?>
				</div>

				<div id="nop-tab-publishing" class="nop-tab-panel" role="tabpanel" aria-labelledby="nop-tablabel-publishing" tabindex="0" hidden>
					<?php $this->render_tab_publishing(); ?>
				</div>

				<div id="nop-tab-reactions" class="nop-tab-panel" role="tabpanel" aria-labelledby="nop-tablabel-reactions" tabindex="0" hidden>
					<?php $this->render_tab_reactions(); ?>
				</div>

				<div id="nop-tab-lookups" class="nop-tab-panel" role="tabpanel" aria-labelledby="nop-tablabel-lookups" tabindex="0" hidden>
					<?php $this->render_tab_lookups(); ?>
				</div>

				<div id="nop-tab-advanced" class="nop-tab-panel" role="tabpanel" aria-labelledby="nop-tablabel-advanced" tabindex="0" hidden>
					<?php $this->render_tab_advanced(); ?>
				</div>

				<div class="nop-settings-footer">
					<?php submit_button( __( 'Save Changes', 'nop-indieweb' ), 'primary', 'submit', false ); ?>
				</div>
			</form>

		</div>
		<?php
	}

	// ——— Setup guide —————————————————————————————————————————————————————————

	private function render_setup_guide(): void {
		$swarm     = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['swarm'] ?? [];
		$mastodon  = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['mastodon'] ?? [];
		$bluesky   = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['bluesky'] ?? [];

		$swarm_configured    = ! empty( $swarm['enabled'] );
		$mastodon_configured = ! empty( $mastodon['enabled'] ) && ! empty( $mastodon['instance'] ) && ! empty( $mastodon['access_token'] );
		$bluesky_configured  = ! empty( $bluesky['enabled'] ) && ! empty( $bluesky['handle'] ) && ! empty( $bluesky['app_password'] );

		// Step 1: any IndieWeb post exists
		$has_post = ! empty( get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ [ 'key' => 'nop_indieweb_service', 'compare' => 'EXISTS' ] ],
		] ) );

		// Step 2: any service is publishing live
		$swarm_live = $swarm_configured && ( $swarm['post_status'] ?? 'draft' ) === 'publish';
		$is_live    = $swarm_live || $mastodon_configured || $bluesky_configured;

		// Step 3: at least one syndicator connected (outbound)
		$has_syndication = $mastodon_configured || $bluesky_configured;

		// Hide guide once the essentials are done
		if ( $has_post && $is_live && $has_syndication ) {
			return;
		}

		$micropub_url = esc_url( \NOP\IndieWeb\nop_indieweb_endpoint_url() );
		$debug_url    = esc_url( admin_url( 'options-general.php?page=nop-indieweb-debug' ) );

		$steps = [
			[
				'done'  => $has_post,
				'label' => __( 'Publish your first IndieWeb post', 'nop-indieweb' ),
				/* translators: %s: Micropub endpoint URL, wrapped in a <code> tag */
				'body'  => sprintf(
					wp_kses(
						__( 'Connect <a href="https://ownyourswarm.p3k.io" target="_blank" rel="noopener">OwnYourSwarm</a> to post location checkins, or enable Mastodon or Bluesky in their tabs to start syndicating. Your Micropub endpoint is <code>%s</code>.', 'nop-indieweb' ),
						[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ], 'code' => [] ]
					),
					esc_html( $micropub_url )
				),
				'link'  => [ 'href' => $debug_url, 'text' => __( 'Test with the Debug panel', 'nop-indieweb' ) ],
			],
			[
				'done'  => $is_live,
				'label' => __( 'Go live', 'nop-indieweb' ),
				'body'  => wp_kses(
					__( 'Services default to Draft while you get set up. Switch <strong>Post Status</strong> to Published in the Posts tab, or enable Mastodon or Bluesky to start syndicating.', 'nop-indieweb' ),
					[ 'strong' => [] ]
				),
				'link'  => [ 'href' => '#nop-tab-post-kinds', 'text' => __( 'Open Posts tab', 'nop-indieweb' ) ],
			],
			[
				'done'  => $has_syndication,
				'label' => __( 'Connect Mastodon or Bluesky', 'nop-indieweb' ),
				'body'  => wp_kses(
					__( 'Add your credentials in the Mastodon or Bluesky tabs to syndicate posts outward automatically. Once connected, you can set up <a href="https://brid.gy" target="_blank" rel="noopener">Bridgy</a> to receive posts back to your site too.', 'nop-indieweb' ),
					[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
				),
				'link'  => [ 'href' => '#nop-tab-mastodon', 'text' => __( 'Open Mastodon tab', 'nop-indieweb' ) ],
			],
		];
		?>
		<div class="nop-setup-guide">
			<p class="nop-setup-guide__heading"><?php esc_html_e( 'Quick setup', 'nop-indieweb' ); ?></p>
			<ol class="nop-setup-guide__list">
				<?php foreach ( $steps as $step ) : ?>
					<li class="nop-setup-step <?php echo $step['done'] ? 'is-done' : ''; ?>">
						<span class="nop-setup-step__icon" aria-hidden="true"></span>
						<div class="nop-setup-step__content">
							<span class="nop-setup-step__label"><?php echo esc_html( $step['label'] ); ?></span>
							<?php if ( ! $step['done'] && $step['body'] ) : ?>
								<p class="nop-setup-step__body"><?php echo wp_kses_post( $step['body'] ); ?></p>
								<?php if ( $step['link'] ) : ?>
									<a href="<?php echo esc_url( $step['link']['href'] ); ?>" class="nop-setup-link">
										<?php echo esc_html( $step['link']['text'] ); ?> →
									</a>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	private function is_tab_enabled( string $slug ): bool {
		$syndicators = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] );
		$services    = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] );
		return match( $slug ) {
			'mastodon'   => (bool) ( $syndicators['mastodon']['enabled']         ?? false ),
			'bluesky'    => (bool) ( $syndicators['bluesky']['enabled']          ?? false ),
			'pixelfed'   => (bool) ( $syndicators['pixelfed']['enabled']         ?? false ),
			'letterboxd' => (bool) ( $services['letterboxd']['import_enabled']   ?? false ),
			'swarm'      => (bool) ( $services['swarm']['enabled']               ?? false ),
			default      => true,
		};
	}

	// ——— Tab: Overview ————————————————————————————————————————————————————————

	private function render_tab_overview(): void {
		$micropub_url   = esc_url( \NOP\IndieWeb\nop_indieweb_endpoint_url() );
		$webmention_url = esc_url( rest_url( 'nop-indieweb/v1/webmention' ) );
		$networks       = $this->get_network_status();
		$approved_wm    = (int) get_comments( [ 'type' => 'webmention', 'count' => true, 'status' => 'approve' ] );
		$pending_wm     = (int) get_comments( [ 'type' => 'webmention', 'count' => true, 'status' => 'hold' ] );
		?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Micropub', 'nop-indieweb' ); ?></th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo esc_url( $micropub_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $micropub_url ); ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $micropub_url ); ?>"><?php esc_html_e( 'Copy', 'nop-indieweb' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'Your publishing endpoint — point Micropub clients (Quill, Ulysses, iA Writer) here.', 'nop-indieweb' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Webmention', 'nop-indieweb' ); ?></th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo esc_url( $webmention_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $webmention_url ); ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $webmention_url ); ?>"><?php esc_html_e( 'Copy', 'nop-indieweb' ); ?></button>
					</div>
					<p class="description"><?php echo wp_kses( __( 'Advertised automatically via <code>&lt;link rel="webmention"&gt;</code> — other sites discover it without any setup.', 'nop-indieweb' ), [ 'code' => [] ] ); ?></p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Networks', 'nop-indieweb' ); ?></h3>
		<div class="nop-network-cards">
			<?php foreach ( $networks as $network ) : ?>
			<div class="nop-network-card <?php echo $network['active'] ? 'nop-network-card--active' : 'nop-network-card--inactive'; ?>"
			     style="--nop-card-accent: <?php echo esc_attr( $network['active'] ? $network['color'] : '#c3c4c7' ); ?>">
				<div class="nop-network-card__header">
					<span class="nop-network-card__dot"></span>
					<strong class="nop-network-card__name"><?php echo esc_html( $network['label'] ); ?></strong>
				</div>
				<p class="nop-network-card__status">
					<?php echo esc_html( $network['active'] ? __( 'Active', 'nop-indieweb' ) : __( 'Not configured', 'nop-indieweb' ) ); ?>
				</p>
				<?php if ( $network['active'] && $network['actions'] ) : ?>
				<p class="nop-network-card__actions"><?php echo esc_html( implode( ' · ', $network['actions'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( $network['last_label'] ) : ?>
				<p class="nop-network-card__last"><?php echo esc_html( $network['last_label'] ); ?></p>
				<?php endif; ?>
				<a href="#nop-tab-<?php echo esc_attr( $network['tab'] ); ?>"
				   class="nop-setup-link nop-network-card__link">
					<?php echo esc_html( $network['active'] ? __( 'Configure', 'nop-indieweb' ) : __( 'Set up', 'nop-indieweb' ) ); ?> →
				</a>
			</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $approved_wm > 0 || $pending_wm > 0 ) :
			$moderated_url = esc_url( admin_url( 'edit-comments.php?comment_type=webmention&comment_status=moderated' ) );
		?>
		<h3 class="nop-section-heading"><?php esc_html_e( 'Reactions', 'nop-indieweb' ); ?></h3>
		<p class="nop-overview-stat">
			<?php if ( $approved_wm > 0 ) : ?>
				<strong><?php echo esc_html( number_format_i18n( $approved_wm ) ); ?></strong>
				<?php echo esc_html( _n( 'reaction received', 'reactions received', $approved_wm, 'nop-indieweb' ) ); ?>
				<?php if ( $pending_wm > 0 ) : ?>
					— <a href="<?php echo esc_url( $moderated_url ); ?>">
						<?php
						/* translators: %s: number of pending reactions */
						echo esc_html( sprintf( _n( '%s pending approval', '%s pending approval', $pending_wm, 'nop-indieweb' ), number_format_i18n( $pending_wm ) ) );
						?>
					</a>
				<?php endif; ?>
			<?php else : ?>
				<?php
				/* translators: %s: number of pending reactions */
				echo esc_html( sprintf( _n( '%s reaction pending approval', '%s reactions pending approval', $pending_wm, 'nop-indieweb' ), number_format_i18n( $pending_wm ) ) );
				?>
				— <a href="<?php echo esc_url( $moderated_url ); ?>"><?php esc_html_e( 'review them', 'nop-indieweb' ); ?></a>
			<?php endif; ?>
		</p>
		<?php endif; ?>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Identity', 'nop-indieweb' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nop-me-urls"><?php esc_html_e( 'Profile URLs', 'nop-indieweb' ); ?></label></th>
				<td>
					<textarea id="nop-me-urls"
					          name="<?php echo esc_attr( self::OPTION_KEY ); ?>[me_urls]"
					          rows="4"
					          class="large-text code"
					          placeholder="https://github.com/yourusername&#10;https://linkedin.com/in/yourusername"><?php echo esc_textarea( \NOP\IndieWeb\nop_indieweb_get_option( 'me_urls', '' ) ); ?></textarea>
					<p class="description"><?php echo wp_kses( __( 'One URL per line. Output as <code>&lt;link rel="me"&gt;</code> — used by IndieAuth and profile verification. Your configured social networks are added automatically.', 'nop-indieweb' ), [ 'code' => [] ] ); ?></p>
				</td>
			</tr>
		</table>

		<?php $this->render_setup_guide(); ?>
		<?php
	}

	private function get_network_status(): array {
		$syndicators = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] );
		$services    = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] );

		$mastodon = $syndicators['mastodon'] ?? [];
		$bluesky  = $syndicators['bluesky']  ?? [];
		$pixelfed = $syndicators['pixelfed'] ?? [];
		$lboxd    = $services['letterboxd']  ?? [];
		$swarm    = $services['swarm']       ?? [];

		$mastodon_ok = ! empty( $mastodon['enabled'] ) && ! empty( $mastodon['instance'] ) && ! empty( $mastodon['access_token'] );
		$bluesky_ok  = ! empty( $bluesky['enabled'] ) && ! empty( $bluesky['handle'] ) && ! empty( $bluesky['app_password'] );
		$pixelfed_ok = ! empty( $pixelfed['enabled'] ) && ! empty( $pixelfed['instance'] ) && ! empty( $pixelfed['access_token'] );
		$lboxd_ok    = ! empty( $lboxd['import_enabled'] ) && ! empty( $lboxd['username'] );
		$swarm_ok    = ! empty( $swarm['enabled'] );

		// Swarm last activity: most recent post from the swarm service.
		$swarm_last_at = null;
		if ( $swarm_ok ) {
			$last = get_posts( [
				'post_type'      => 'post',
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => [ [ 'key' => 'nop_indieweb_service', 'value' => 'swarm' ] ],
			] );
			if ( $last ) {
				$swarm_last_at = get_post_field( 'post_date_gmt', $last[0] );
			}
		}

		$post_out = __( 'Post out', 'nop-indieweb' );
		$import   = __( 'Import', 'nop-indieweb' );
		$synced   = __( 'Synced', 'nop-indieweb' );

		return [
			'mastodon'   => [
				'label'      => 'Mastodon',
				'color'      => '#6364FF',
				'active'     => $mastodon_ok,
				'actions'    => $mastodon_ok ? [ $post_out, $import ] : [],
				'last_label' => $mastodon_ok ? $this->human_time_diff( $mastodon['import_last_at'] ?? null, $synced ) : null,
				'tab'        => 'mastodon',
			],
			'bluesky'    => [
				'label'      => 'Bluesky',
				'color'      => '#0085FF',
				'active'     => $bluesky_ok,
				'actions'    => $bluesky_ok ? [ $post_out, $import ] : [],
				'last_label' => $bluesky_ok ? $this->human_time_diff( $bluesky['import_last_at'] ?? null, $synced ) : null,
				'tab'        => 'bluesky',
			],
			'pixelfed'   => [
				'label'      => 'Pixelfed',
				'color'      => '#1A9C5B',
				'active'     => $pixelfed_ok,
				'actions'    => $pixelfed_ok ? [ $post_out, $import ] : [],
				'last_label' => $pixelfed_ok ? $this->human_time_diff( $pixelfed['import_last_at'] ?? null, $synced ) : null,
				'tab'        => 'pixelfed',
			],
			'letterboxd' => [
				'label'      => 'Letterboxd',
				'color'      => '#00C030',
				'active'     => $lboxd_ok,
				'actions'    => $lboxd_ok ? [ $import ] : [],
				'last_label' => $lboxd_ok ? $this->human_time_diff( $lboxd['import_last_at'] ?? null, $synced ) : null,
				'tab'        => 'letterboxd',
			],
			'swarm'      => [
				'label'      => 'Swarm',
				'color'      => '#FC8D1D',
				'active'     => $swarm_ok,
				'actions'    => $swarm_ok ? [ __( 'Receive check-ins', 'nop-indieweb' ) ] : [],
				'last_label' => $swarm_ok ? $this->human_time_diff( $swarm_last_at, __( 'Last check-in', 'nop-indieweb' ) ) : null,
				'tab'        => 'swarm',
			],
		];
	}

	private function human_time_diff( ?string $iso_date, string $prefix = '' ): ?string {
		if ( ! $iso_date ) {
			return null;
		}
		$ts = strtotime( $iso_date );
		if ( ! $ts ) {
			return null;
		}
		if ( $prefix ) {
			/* translators: 1: label e.g. "Synced", 2: human time difference e.g. "3 hours" */
			return sprintf( __( '%1$s %2$s ago', 'nop-indieweb' ), $prefix, human_time_diff( $ts ) );
		}
		/* translators: %s: human time difference e.g. "3 hours" */
		return sprintf( __( '%s ago', 'nop-indieweb' ), human_time_diff( $ts ) );
	}

	// ——— Tab: Advanced ——————————————————————————————————————————————————————

	private function render_tab_advanced(): void {
		$auth_url  = esc_url( Auth_Endpoint::url() );
		$token_url = esc_url( rest_url( 'nop-indieweb/v1/token' ) );
		$sessions  = Token_Store::get_by_user( get_current_user_id() );

		$mf2_enabled  = \NOP\IndieWeb\nop_indieweb_get_option( 'mf2_enabled', true );
		$debug_mode   = \NOP\IndieWeb\nop_indieweb_get_option( 'debug_mode', false );

		$example_post = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => 'nop_indieweb_service',
		] );
		$mf2_url = $example_post
			? esc_url( rest_url( 'nop-indieweb/v1/mf2/' . $example_post[0] ) )
			: esc_url( rest_url( 'nop-indieweb/v1/mf2/{post_id}' ) );
		?>

		<h3 class="nop-section-heading nop-section-heading--first"><?php esc_html_e( 'IndieAuth', 'nop-indieweb' ); ?></h3>
		<p class="description nop-section-intro"><?php echo wp_kses( __( 'These endpoints are advertised automatically in your site\'s <code>&lt;head&gt;</code> — Micropub clients discover them without any setup.', 'nop-indieweb' ), [ 'code' => [] ] ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Authorization Endpoint', 'nop-indieweb' ); ?></th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo esc_url( $auth_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $auth_url ); ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $auth_url ); ?>"><?php esc_html_e( 'Copy', 'nop-indieweb' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'Micropub clients redirect here so you can approve their access.', 'nop-indieweb' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Token Endpoint', 'nop-indieweb' ); ?></th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo esc_url( $token_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $token_url ); ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $token_url ); ?>"><?php esc_html_e( 'Copy', 'nop-indieweb' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'Clients exchange their authorization code here for a Bearer token.', 'nop-indieweb' ); ?></p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Active Sessions', 'nop-indieweb' ); ?></h3>

		<?php if ( empty( $sessions ) ) : ?>
			<p class="nop-sessions-empty"><?php esc_html_e( 'No applications authorized yet. Connect a Micropub client to see sessions here.', 'nop-indieweb' ); ?></p>
		<?php else : ?>
			<table class="nop-sessions-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Application', 'nop-indieweb' ); ?></th>
						<th><?php esc_html_e( 'Permissions', 'nop-indieweb' ); ?></th>
						<th><?php esc_html_e( 'Authorized', 'nop-indieweb' ); ?></th>
						<th><?php esc_html_e( 'Last used', 'nop-indieweb' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sessions as $session ) : ?>
						<tr>
							<td><?php echo esc_html( $session['client_name'] ?: $session['client_id'] ); ?></td>
							<td><code><?php echo esc_html( $session['scope'] ); ?></code></td>
							<td><?php echo esc_html( wp_date( 'j M Y', strtotime( $session['issued_at'] ) ) ); ?></td>
							<td><?php echo $session['last_used_at'] ? esc_html( wp_date( 'j M Y', strtotime( $session['last_used_at'] ) ) ) : '—'; ?></td>
							<td>
								<?php
								$revoke_url = add_query_arg( [
									'nop_revoke_token' => $session['id'],
									'_wpnonce'         => wp_create_nonce( 'nop_revoke_token_' . $session['id'] ),
								], admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
								?>
								<a href="<?php echo esc_url( $revoke_url ); ?>"
								   class="nop-revoke-link"
								   data-confirm="<?php
								   	/* translators: %s: authorized application name */
								   	echo esc_attr( sprintf( __( 'Revoke access for %s?', 'nop-indieweb' ), $session['client_name'] ?: $session['client_id'] ) );
								   ?>">
									<?php esc_html_e( 'Revoke', 'nop-indieweb' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Microformats', 'nop-indieweb' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Markup', 'nop-indieweb' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[mf2_enabled]" value="1"
						       <?php checked( $mf2_enabled ); ?>>
						<?php esc_html_e( 'Add microformats2 markup to IndieWeb posts', 'nop-indieweb' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Injected at render time — nothing is stored in your database. Deactivating the plugin removes it automatically.', 'nop-indieweb' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'JSON endpoint', 'nop-indieweb' ); ?></th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo esc_url( $mf2_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $mf2_url ); ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $mf2_url ); ?>"><?php esc_html_e( 'Copy', 'nop-indieweb' ); ?></button>
					</div>
					<p class="description">
						<?php echo wp_kses( __( 'Served at <code>rel="alternate" type="application/mf2+json"</code>. Services like <a href="https://brid.gy" target="_blank" rel="noopener">Bridgy</a> and <a href="https://xray.p3k.io" target="_blank" rel="noopener">XRay</a> use this for richer data.', 'nop-indieweb' ), [ 'code' => [], 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Developer', 'nop-indieweb' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Debug mode', 'nop-indieweb' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[debug_mode]" value="1"
						       <?php checked( $debug_mode ); ?>>
						<?php esc_html_e( 'Enable debug logging', 'nop-indieweb' ); ?>
					</label>
					<p class="description"><?php echo wp_kses( __( 'Writes plugin activity to <code>wp-content/debug.log</code> when WP_DEBUG_LOG is enabled.', 'nop-indieweb' ), [ 'code' => [] ] ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ——— Tab: Mastodon ———————————————————————————————————————————————————————

	private function render_tab_mastodon(): void { $this->render_syndicator_tab( 'mastodon' ); }
	private function render_tab_bluesky():  void { $this->render_syndicator_tab( 'bluesky'  ); }
	private function render_tab_pixelfed(): void { $this->render_syndicator_tab( 'pixelfed' ); }

	/**
	 * Per-syndicator shape — drives both render_syndicator_tab() and the
	 * sanitize() loop. Adding a new ActivityPub-style syndicator (Threads,
	 * Tumblr, micro.blog) means a new entry here plus the matching tab slug
	 * in TAB_GROUPS — no copy-pasted HTML or sanitize block.
	 */
	private static function get_syndicator_config(): array {
		return [
			'mastodon' => [
				'label'         => __( 'Mastodon', 'nop-indieweb' ),
				'intro'         => __( 'Syndicates posts to your Mastodon account when you publish. Requires an access token from your instance.', 'nop-indieweb' ),
				'doc_link'      => [ 'url' => 'https://docs.joinmastodon.org/client/token/', 'text' => __( 'How to create an access token →', 'nop-indieweb' ) ],
				'enable_text'   => __( 'Syndicate posts to Mastodon on publish', 'nop-indieweb' ),
				'fields'        => [
					'instance'     => [
						'label'       => __( 'Instance URL', 'nop-indieweb' ),
						'type'        => 'url',
						'placeholder' => 'https://mastodon.social',
						'description' => __( 'Your Mastodon instance URL (e.g. https://mastodon.social).', 'nop-indieweb' ),
					],
					'access_token' => [
						'label'       => __( 'Access Token', 'nop-indieweb' ),
						'type'        => 'password',
						'description' => __( 'From your Mastodon instance: Preferences → Development → New Application. Needs <code>write:statuses read:statuses</code> scopes.', 'nop-indieweb' ),
					],
				],
				'import'        => [
					'checkbox_label' => __( 'Automatically import your Mastodon posts as WordPress entries (hourly)', 'nop-indieweb' ),
					'help'           => __( 'Replies and boosts are excluded. Posts already published from WordPress are skipped.', 'nop-indieweb' ),
					'button_label'   => __( 'Import from Mastodon now', 'nop-indieweb' ),
				],
			],
			'bluesky' => [
				'label'         => __( 'Bluesky', 'nop-indieweb' ),
				'intro'         => __( 'Syndicates posts to your Bluesky account when you publish. Uses an app password — never your main password.', 'nop-indieweb' ),
				'doc_link'      => [ 'url' => 'https://bsky.app/settings/app-passwords', 'text' => __( 'Create an app password →', 'nop-indieweb' ) ],
				'enable_text'   => __( 'Syndicate posts to Bluesky on publish', 'nop-indieweb' ),
				'fields'        => [
					'handle'       => [
						'label'       => __( 'Handle', 'nop-indieweb' ),
						'type'        => 'text',
						'placeholder' => 'you.bsky.social',
						'description' => __( 'Your Bluesky handle — either <code>you.bsky.social</code> or your custom domain handle.', 'nop-indieweb' ),
					],
					'app_password' => [
						'label'       => __( 'App Password', 'nop-indieweb' ),
						'type'        => 'password',
						'description' => __( 'From Bluesky: Settings → Privacy and Security → App Passwords.', 'nop-indieweb' ),
					],
				],
				'import'        => [
					'checkbox_label' => __( 'Automatically import your Bluesky posts as WordPress entries (hourly)', 'nop-indieweb' ),
					'help'           => __( 'Replies and reposts are excluded. Posts already published from WordPress are skipped.', 'nop-indieweb' ),
					'button_label'   => __( 'Import from Bluesky now', 'nop-indieweb' ),
				],
			],
			'pixelfed' => [
				'label'         => __( 'Pixelfed', 'nop-indieweb' ),
				'intro'         => __( 'Syndicates posts to your Pixelfed account when you publish. Pixelfed uses the same API as Mastodon — create an access token from your instance\'s developer settings.', 'nop-indieweb' ),
				'doc_link'      => null,
				'enable_text'   => __( 'Syndicate posts to Pixelfed on publish', 'nop-indieweb' ),
				'fields'        => [
					'instance'     => [
						'label'       => __( 'Instance URL', 'nop-indieweb' ),
						'type'        => 'url',
						'placeholder' => 'https://pixelfed.social',
						'description' => __( 'Your Pixelfed instance URL (e.g. https://pixelfed.social).', 'nop-indieweb' ),
					],
					'access_token' => [
						'label'       => __( 'Access Token', 'nop-indieweb' ),
						'type'        => 'password',
						'description' => __( 'From your Pixelfed instance: Settings → Applications → Create New Token.', 'nop-indieweb' ),
					],
				],
				'import'        => [
					'checkbox_label' => __( 'Automatically import your Pixelfed posts as WordPress entries (hourly)', 'nop-indieweb' ),
					'help'           => __( 'Replies and boosts are excluded. Posts already published from WordPress are skipped.', 'nop-indieweb' ),
					'button_label'   => __( 'Import from Pixelfed now', 'nop-indieweb' ),
				],
			],
		];
	}

	private function render_syndicator_tab( string $slug ): void {
		$config = self::get_syndicator_config()[ $slug ] ?? null;
		if ( ! $config ) {
			return;
		}

		$prefix   = self::OPTION_KEY . "[syndicators][{$slug}]";
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )[ $slug ] ?? [];

		$all_credentials_filled = true;
		foreach ( $config['fields'] as $key => $field ) {
			if ( empty( $settings[ $key ] ) ) {
				$all_credentials_filled = false;
				break;
			}
		}
		?>
		<p><?php echo esc_html( $config['intro'] ); ?></p>
		<?php if ( $config['doc_link'] ) : ?>
		<p><a href="<?php echo esc_url( $config['doc_link']['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $config['doc_link']['text'] ); ?></a></p>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable', 'nop-indieweb' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( "{$prefix}[enabled]" ); ?>" value="1"
						       <?php checked( $settings['enabled'] ?? false ); ?>>
						<?php echo esc_html( $config['enable_text'] ); ?>
					</label>
					<?php if ( ! empty( $settings['enabled'] ) && ! $all_credentials_filled ) : ?>
					<p class="nop-credential-warning">
						<?php esc_html_e( 'Enabled but credentials are missing — syndication will silently fail until they are filled in below.', 'nop-indieweb' ); ?>
					</p>
					<?php endif; ?>
				</td>
			</tr>
			<?php foreach ( $config['fields'] as $key => $field ) : ?>
				<?php $this->render_credential_row( $slug, $key, $field, $prefix, $settings ); ?>
			<?php endforeach; ?>
		</table>

		<?php if ( $all_credentials_filled ) : ?>
		<p>
			<button type="button" class="button nop-test-connection" data-service="<?php echo esc_attr( $slug ); ?>"
			        data-nonce="<?php echo esc_attr( wp_create_nonce( 'nop_test_connection' ) ); ?>">
				<?php esc_html_e( 'Test connection', 'nop-indieweb' ); ?>
			</button>
			<span class="nop-test-result"></span>
		</p>
		<?php endif; ?>

		<?php $this->render_inbound_defaults( $slug, $prefix, $settings ); ?>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Import', 'nop-indieweb' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Import posts', 'nop-indieweb' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( "{$prefix}[import_enabled]" ); ?>" value="1"
						       <?php checked( $settings['import_enabled'] ?? false ); ?>>
						<?php echo esc_html( $config['import']['checkbox_label'] ); ?>
					</label>
					<p class="description"><?php echo esc_html( $config['import']['help'] ); ?></p>
				</td>
			</tr>
			<?php if ( ! empty( $settings['import_enabled'] ) ) :
				$last_sync = $this->human_time_diff( $settings['import_last_at'] ?? null, __( 'Last imported', 'nop-indieweb' ) );
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sync now', 'nop-indieweb' ); ?></th>
				<td>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'nop_indieweb_sync', $slug, admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ), "nop_indieweb_sync_{$slug}" ) ); ?>"
					   class="button"><?php echo esc_html( $config['import']['button_label'] ); ?></a>
					<?php if ( $last_sync ) : ?>
					<span class="nop-last-sync"><?php echo esc_html( $last_sync ); ?></span>
					<?php else : ?>
					<span class="nop-last-sync nop-last-sync--never"><?php esc_html_e( 'Not yet imported', 'nop-indieweb' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Renders a single credential input row. Password-type fields get a
	 * reveal toggle so the user can verify a paste without leaking the
	 * value into the rendered DOM.
	 */
	private function render_credential_row( string $slug, string $key, array $field, string $prefix, array $settings ): void {
		$id    = "nop-{$slug}-{$key}";
		$type  = $field['type'] ?? 'text';
		$value = (string) ( $settings[ $key ] ?? '' );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
			<td>
				<?php if ( 'password' === $type ) : ?>
				<span class="nop-secret-field">
					<input type="password" id="<?php echo esc_attr( $id ); ?>"
					       name="<?php echo esc_attr( "{$prefix}[{$key}]" ); ?>"
					       value="<?php echo esc_attr( $value ); ?>"
					       class="regular-text" autocomplete="off"<?php echo isset( $field['placeholder'] ) ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : ''; ?>>
					<button type="button" class="button button-secondary nop-secret-toggle"
					        data-target="<?php echo esc_attr( $id ); ?>"
					        aria-label="<?php esc_attr_e( 'Show or hide value', 'nop-indieweb' ); ?>">
						<?php esc_html_e( 'Show', 'nop-indieweb' ); ?>
					</button>
				</span>
				<?php else : ?>
				<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>"
				       name="<?php echo esc_attr( "{$prefix}[{$key}]" ); ?>"
				       value="<?php echo esc_attr( $value ); ?>"
				       class="regular-text"<?php echo isset( $field['placeholder'] ) ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : ''; ?>>
				<?php endif; ?>
				<?php if ( ! empty( $field['description'] ) ) : ?>
				<p class="description"><?php echo wp_kses_post( $field['description'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function render_tab_letterboxd(): void {
		$prefix   = self::OPTION_KEY . '[services][letterboxd]';
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['letterboxd'] ?? [];
		$username = $settings['username'] ?? '';
		?>
		<p><?php esc_html_e( 'Imports your Letterboxd diary as WordPress posts — one post per film watched. No API key required; Letterboxd diary feeds are public.', 'nop-indieweb' ); ?></p>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Import', 'nop-indieweb' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Import posts', 'nop-indieweb' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( "{$prefix}[import_enabled]" ); ?>" value="1"
						       <?php checked( $settings['import_enabled'] ?? false ); ?>>
						<?php esc_html_e( 'Automatically import your Letterboxd diary as WordPress posts (hourly)', 'nop-indieweb' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="letterboxd-username"><?php esc_html_e( 'Username', 'nop-indieweb' ); ?></label></th>
				<td>
					<input type="text" id="letterboxd-username" name="<?php echo esc_attr( "{$prefix}[username]" ); ?>"
					       value="<?php echo esc_attr( $username ); ?>"
					       class="regular-text" placeholder="your-letterboxd-username">
					<?php if ( $username ) : ?>
					<p class="description">
						<?php esc_html_e( 'Feed:', 'nop-indieweb' ); ?> <a href="https://letterboxd.com/<?php echo esc_attr( $username ); ?>/rss/" target="_blank" rel="noopener">
							letterboxd.com/<?php echo esc_html( $username ); ?>/rss/
						</a>
					</p>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( ! empty( $settings['import_enabled'] ) ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sync now', 'nop-indieweb' ); ?></th>
				<td>
					<a href="<?php echo esc_url( wp_nonce_url(
						add_query_arg( 'nop_indieweb_sync', 'letterboxd', admin_url( 'options-general.php?page=nop-indieweb-settings' ) ),
						'nop_indieweb_sync_letterboxd'
					) ); ?>" class="button"><?php esc_html_e( 'Import from Letterboxd now', 'nop-indieweb' ); ?></a>
				</td>
			</tr>
			<?php endif; ?>
		</table>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Inbound Defaults', 'nop-indieweb' ); ?></h3>
		<p class="description nop-section-intro"><?php esc_html_e( 'Applied to posts imported from Letterboxd.', 'nop-indieweb' ); ?></p>
		<?php
		$this->render_defaults_table( [
			'slug'         => 'letterboxd',
			'prefix'       => $prefix,
			'settings'     => $settings,
			'last_label'   => __( 'Poster', 'nop-indieweb' ),
			'last_field'   => 'sideload_poster',
			'last_aria'    => __( 'Save film poster to media library', 'nop-indieweb' ),
		] );
	}

	// ——— Tab: Publishing ————————————————————————————————————————————————————

	private function render_tab_publishing(): void {
		$category_names = array_values( array_map( fn( $c ) => $c->name, get_categories( [ 'hide_empty' => false, 'orderby' => 'name' ] ) ) );
		$tag_names      = array_values( array_map( fn( $t ) => $t->name, get_tags( [ 'hide_empty' => false, 'orderby' => 'name' ] ) ) );
		$entries_settings = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['entries'] ?? [];
		$entries_prefix   = self::OPTION_KEY . '[services][entries]';
		?>
		<h3 class="nop-section-heading nop-section-heading--first"><?php esc_html_e( 'Notes', 'nop-indieweb' ); ?></h3>
		<p class="description nop-section-intro"><?php esc_html_e( 'Short posts sent via any Micropub client (Quill, iA Writer, etc.).', 'nop-indieweb' ); ?></p>
		<table class="nop-kinds-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Enable', 'nop-indieweb' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'nop-indieweb' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Category', 'nop-indieweb' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tags', 'nop-indieweb' ); ?></th>
					<th scope="col" class="nop-kinds-table__enable"><?php esc_html_e( 'Photos', 'nop-indieweb' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td class="nop-kinds-table__enable">
						<input type="checkbox"
						       name="<?php echo esc_attr( "{$entries_prefix}[enabled]" ); ?>"
						       value="1"
						       aria-label="<?php esc_attr_e( 'Enable notes', 'nop-indieweb' ); ?>"
						       <?php checked( $entries_settings['enabled'] ?? true ); ?>>
					</td>
					<td>
						<select name="<?php echo esc_attr( "{$entries_prefix}[post_status]" ); ?>">
							<?php foreach ( self::post_statuses() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $entries_settings['post_status'] ?? 'publish', $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<?php $this->render_token_field( 'nop-entries-svc-category', "{$entries_prefix}[post_category]", $entries_settings['post_category'] ?? '', $category_names, __( 'Add category…', 'nop-indieweb' ), __( 'Category name', 'nop-indieweb' ) ); ?>
					</td>
					<td>
						<?php $this->render_token_field( 'nop-entries-svc-tags', "{$entries_prefix}[post_tags]", $entries_settings['post_tags'] ?? '', $tag_names, __( 'Add tags…', 'nop-indieweb' ), __( 'Tag name', 'nop-indieweb' ) ); ?>
					</td>
					<td class="nop-kinds-table__enable">
						<input type="checkbox" name="<?php echo esc_attr( "{$entries_prefix}[sideload_photos]" ); ?>" value="1"
						       title="<?php esc_attr_e( 'Save photos to your media library.', 'nop-indieweb' ); ?>"
						       aria-label="<?php esc_attr_e( 'Save photos: notes', 'nop-indieweb' ); ?>"
						       <?php checked( $entries_settings['sideload_photos'] ?? true ); ?>>
					</td>
				</tr>
			</tbody>
		</table>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Interaction posts', 'nop-indieweb' ); ?></h3>
		<p class="description nop-section-intro"><?php esc_html_e( 'Likes, bookmarks, replies, and reposts sent via Micropub. Categories are created automatically if they don\'t exist.', 'nop-indieweb' ); ?></p>
		<?php
		$kinds = [
			'bookmark' => [ 'label' => __( 'Bookmark', 'nop-indieweb' ), 'micropub' => 'bookmark-of'        ],
			'reply'    => [ 'label' => __( 'Reply', 'nop-indieweb' ),    'micropub' => 'in-reply-to'        ],
			'like'     => [ 'label' => __( 'Like', 'nop-indieweb' ),     'micropub' => 'like-of'            ],
			'repost'   => [ 'label' => __( 'Repost', 'nop-indieweb' ),   'micropub' => 'repost-of'          ],
			'rsvp'     => [ 'label' => __( 'RSVP', 'nop-indieweb' ),     'micropub' => 'in-reply-to + rsvp' ],
		];
		?>
		<table class="nop-kinds-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Kind', 'nop-indieweb' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Enable', 'nop-indieweb' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'nop-indieweb' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Category', 'nop-indieweb' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $kinds as $slug => $kind ) :
					$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )[ $slug ] ?? [];
					$prefix   = self::OPTION_KEY . "[services][{$slug}]";
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $kind['label'] ); ?></strong><br>
						<code class="nop-micropub-prop"><?php echo esc_html( $kind['micropub'] ); ?></code>
					</td>
					<td class="nop-kinds-table__enable">
						<input type="checkbox"
						       name="<?php echo esc_attr( "{$prefix}[enabled]" ); ?>"
						       value="1"
						       aria-label="<?php
					       	/* translators: %s: interaction kind name, e.g. Bookmark */
					       	echo esc_attr( sprintf( __( 'Accept %s posts via Micropub', 'nop-indieweb' ), $kind['label'] ) );
					       ?>"
						       <?php checked( $settings['enabled'] ?? true ); ?>>
					</td>
					<td>
						<select name="<?php echo esc_attr( "{$prefix}[post_status]" ); ?>">
							<?php foreach ( self::post_statuses() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['post_status'] ?? 'publish', $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<?php $this->render_token_field( "nop-{$slug}-category", "{$prefix}[post_category]", $settings['post_category'] ?? '', $category_names, __( 'Add category…', 'nop-indieweb' ), __( 'Category name', 'nop-indieweb' ) ); ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Twitter Archive', 'nop-indieweb' ); ?></h3>
		<p class="description nop-section-intro"><?php echo wp_kses( __( 'Posts imported from a static <a href="https://github.com/timhutton/twitter-archive-parser" target="_blank" rel="noopener">Twitter archive</a> show an "Archived Tweet" label that can link out to the source.', 'nop-indieweb' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="twitter-archive-url"><?php esc_html_e( 'Archive URL', 'nop-indieweb' ); ?></label></th>
				<td>
					<input type="url" id="twitter-archive-url"
					       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[twitter_archive_url]"
					       value="<?php echo esc_attr( \NOP\IndieWeb\nop_indieweb_get_option( 'twitter_archive_url', '' ) ); ?>"
					       class="regular-text"
					       placeholder="https://yoursite.com/twitter-archive/">
					<p class="description"><?php esc_html_e( 'Optional link displayed on archived tweet posts. Leave blank to show the label without a link.', 'nop-indieweb' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ——— Tab: Reactions ——————————————————————————————————————————————————————

	private function render_tab_reactions(): void {
		$settings        = \NOP\IndieWeb\nop_indieweb_get_option( 'webmentions', [] );
		$prefix          = self::OPTION_KEY . '[webmentions]';
		$receive_enabled = $settings['receive_enabled'] ?? true;
		$approval        = $settings['approval'] ?? 'bridgy_only';
		?>
		<p><?php echo wp_kses( __( 'Reactions are likes, reposts, and replies sent to your posts from other sites via the <a href="https://webmention.net" target="_blank" rel="noopener">Webmention</a> standard. <a href="https://brid.gy" target="_blank" rel="noopener">Bridgy</a> can backfeed reactions from Mastodon and Bluesky automatically.', 'nop-indieweb' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Accept reactions', 'nop-indieweb' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( "{$prefix}[receive_enabled]" ); ?>" value="1"
						       <?php checked( $receive_enabled ); ?>>
						<?php esc_html_e( 'Accept incoming webmentions from other sites', 'nop-indieweb' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Uncheck to stop accepting new reactions. Existing ones are kept.', 'nop-indieweb' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nop-webmention-approval"><?php esc_html_e( 'Approval', 'nop-indieweb' ); ?></label></th>
				<td>
					<select id="nop-webmention-approval" name="<?php echo esc_attr( "{$prefix}[approval]" ); ?>">
						<option value="bridgy_only" <?php selected( $approval, 'bridgy_only' ); ?>><?php esc_html_e( 'Auto-approve Bridgy, hold everything else', 'nop-indieweb' ); ?></option>
						<option value="auto_all"    <?php selected( $approval, 'auto_all' ); ?>><?php esc_html_e( 'Auto-approve all', 'nop-indieweb' ); ?></option>
						<option value="manual_all"  <?php selected( $approval, 'manual_all' ); ?>><?php esc_html_e( 'Hold all for manual review', 'nop-indieweb' ); ?></option>
					</select>
					<p class="description"><?php echo wp_kses( sprintf(
						/* translators: %s: link to the Comments → Webmentions admin screen */
						__( 'Held reactions appear in <a href="%s">Comments → Webmentions</a> awaiting your approval.', 'nop-indieweb' ),
						esc_url( admin_url( 'edit-comments.php?comment_type=webmention' ) )
					), [ 'a' => [ 'href' => [] ] ] ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ——— Tab: Lookups ————————————————————————————————————————————————————————

	private function render_tab_lookups(): void {
		?>
		<p><?php esc_html_e( 'API keys used for interactive in-editor lookups — searching for a film title, venue, or track without leaving the WordPress editor.', 'nop-indieweb' ); ?></p>

		<h3 class="nop-section-heading"><?php esc_html_e( 'TMDB (Films)', 'nop-indieweb' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nop-tmdb-key"><?php esc_html_e( 'API Key', 'nop-indieweb' ); ?></label></th>
				<td>
					<?php $key = \NOP\IndieWeb\nop_indieweb_get_option( 'lookups.tmdb_api_key', '' ); ?>
					<input type="text" id="nop-tmdb-key"
					       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[lookups][tmdb_api_key]"
					       value="<?php echo esc_attr( $key ); ?>"
					       class="regular-text code" autocomplete="off"
					       placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
					<p class="description">
						<?php echo wp_kses( __( 'Used by the Watch kind\'s Film lookup in the block editor. Get a free key at <a href="https://www.themoviedb.org/settings/api" target="_blank" rel="noopener">themoviedb.org</a>.', 'nop-indieweb' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ——— Tab: Swarm ——————————————————————————————————————————————————————————

	private function render_tab_swarm(): void {
		$prefix   = self::OPTION_KEY . '[services][swarm]';
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['swarm'] ?? [];
		$endpoint = esc_url( \NOP\IndieWeb\nop_indieweb_endpoint_url() );
		?>
		<p><?php echo wp_kses( __( 'Swarm by Foursquare lets you check in to places. Connect <a href="https://ownyourswarm.p3k.io" target="_blank" rel="noopener">OwnYourSwarm</a> and every check-in automatically becomes a post on your site.', 'nop-indieweb' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?></p>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Enable', 'nop-indieweb' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Accept check-ins', 'nop-indieweb' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( "{$prefix}[enabled]" ); ?>" value="1"
						       <?php checked( $settings['enabled'] ?? false ); ?>>
						<?php esc_html_e( 'Accept check-ins from OwnYourSwarm', 'nop-indieweb' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Your Micropub endpoint', 'nop-indieweb' ); ?></th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo esc_url( $endpoint ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $endpoint ); ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $endpoint ); ?>"><?php esc_html_e( 'Copy', 'nop-indieweb' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'Paste this into OwnYourSwarm as your Micropub endpoint. It will ask you to sign in to your site to authorize — that\'s normal.', 'nop-indieweb' ); ?></p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Map', 'nop-indieweb' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nop-geoapify-key"><?php esc_html_e( 'Geoapify API key', 'nop-indieweb' ); ?></label></th>
				<td>
					<?php $key = \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' ); ?>
					<input type="text" id="nop-geoapify-key"
					       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[maps][geoapify_api_key]"
					       value="<?php echo esc_attr( $key ); ?>"
					       class="regular-text code" autocomplete="off"
					       placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
					<p class="description">
						<?php echo wp_kses( __( 'Used to generate static map images for check-in posts. Get a free key (3 000 req/day) at <a href="https://www.geoapify.com/" target="_blank" rel="noopener">geoapify.com</a>. Leave blank to use the built-in tile fallback instead.', 'nop-indieweb' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Weather', 'nop-indieweb' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nop-pirate-weather-key"><?php esc_html_e( 'Pirate Weather API key', 'nop-indieweb' ); ?></label></th>
				<td>
					<?php $weather_key = \NOP\IndieWeb\nop_indieweb_get_option( 'weather.pirate_weather_api_key', '' ); ?>
					<input type="text" id="nop-pirate-weather-key"
					       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[weather][pirate_weather_api_key]"
					       value="<?php echo esc_attr( $weather_key ); ?>"
					       class="regular-text code" autocomplete="off"
					       placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
					<p class="description">
						<?php echo wp_kses( __( 'Snapshots the weather at each check-in\'s location and time, stored on the post. Get a free key (10 000 req/day) at <a href="https://pirateweather.net/" target="_blank" rel="noopener">pirateweather.net</a>. Leave blank to skip weather enrichment.', 'nop-indieweb' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Venue Categories', 'nop-indieweb' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nop-foursquare-key"><?php esc_html_e( 'Foursquare API key', 'nop-indieweb' ); ?></label></th>
				<td>
					<?php $fsq_key = \NOP\IndieWeb\nop_indieweb_get_option( 'venue.foursquare_api_key', '' ); ?>
					<input type="text" id="nop-foursquare-key"
					       name="<?php echo esc_attr( self::OPTION_KEY ); ?>[venue][foursquare_api_key]"
					       value="<?php echo esc_attr( $fsq_key ); ?>"
					       class="regular-text code" autocomplete="off"
					       placeholder="fsq3xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
					<p class="description">
						<?php echo wp_kses( __( 'Looks up Foursquare\'s venue categories (e.g. "Yoga Studio", "Park") on each check-in, since OwnYourSwarm doesn\'t forward them. Each venue\'s categories are cached for 30 days. Get a free Service API key at <a href="https://location.foursquare.com/developer/" target="_blank" rel="noopener">location.foursquare.com/developer</a>. Leave blank to skip venue category enrichment.', 'nop-indieweb' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading"><?php esc_html_e( 'Inbound Defaults', 'nop-indieweb' ); ?></h3>
		<p class="description nop-section-intro"><?php esc_html_e( 'Applied to posts created from Swarm check-ins.', 'nop-indieweb' ); ?></p>
		<?php
		$this->render_defaults_table( [
			'slug'        => 'swarm',
			'prefix'      => $prefix,
			'settings'    => $settings,
			'tag_default' => 'Swarm',
			'last_aria'   => __( 'Save Swarm photos to media library', 'nop-indieweb' ),
		] );
	}

	// ——— Shared rendering helpers ————————————————————————————————————————————

	private function render_token_field(
		string $id,
		string $name,
		string $value,
		array $suggestions,
		string $placeholder,
		string $aria_label,
		?string $description = null
	): void {
		?>
		<div class="nop-token-field"
		     data-input-id="<?php echo esc_attr( $id ); ?>"
		     data-suggestions="<?php echo esc_attr( wp_json_encode( $suggestions ) ); ?>">
			<div class="nop-token-field__tokens" aria-live="polite"></div>
			<input type="text" id="<?php echo esc_attr( $id ); ?>"
			       class="nop-token-field__input"
			       placeholder="<?php echo esc_attr( $placeholder ); ?>"
			       autocomplete="off"
			       aria-label="<?php echo esc_attr( $aria_label ); ?>">
			<ul class="nop-token-field__suggestions" role="listbox" hidden></ul>
			<input type="hidden"
			       name="<?php echo esc_attr( $name ); ?>"
			       value="<?php echo esc_attr( $value ); ?>">
		</div>
		<?php if ( $description ) : ?>
		<p class="description"><?php echo wp_kses_post( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the shared Status + Category + Tags + sideload-checkbox table used
	 * across Letterboxd, Swarm, and the per-syndicator inbound-defaults sections.
	 *
	 * $args keys:
	 *   slug         — identifier for token-field IDs
	 *   prefix       — form-name prefix, e.g. "nop_indieweb_settings[services][swarm]"
	 *   settings     — current values
	 *   last_label   — last-column heading ("Photos", "Poster")
	 *   last_field   — checkbox field name (e.g. "sideload_photos")
	 *   last_default — default for the checkbox when unset
	 *   last_aria    — aria-label for the checkbox
	 *   tag_default  — default tag value (e.g. "Swarm")
	 */
	private function render_defaults_table( array $args ): void {
		$category_names = array_values( array_map( fn( $t ) => $t->name, get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] ) ) );
		$tag_names      = array_values( array_map( fn( $t ) => $t->name, get_terms( [ 'taxonomy' => 'post_tag',  'hide_empty' => false ] ) ) );

		$slug        = $args['slug'];
		$prefix      = $args['prefix'];
		$settings    = $args['settings'];
		$last_label  = $args['last_label']  ?? __( 'Photos', 'nop-indieweb' );
		$last_field  = $args['last_field']  ?? 'sideload_photos';
		$last_def    = $args['last_default'] ?? true;
		$last_aria   = $args['last_aria']   ?? __( 'Save photos to media library', 'nop-indieweb' );
		$tag_default = $args['tag_default'] ?? '';
		?>
		<table class="nop-kinds-table">
			<thead>
				<tr>
					<th scope="col" class="nop-kinds-table__status"><?php esc_html_e( 'Status', 'nop-indieweb' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Category', 'nop-indieweb' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tags', 'nop-indieweb' ); ?></th>
					<th scope="col" class="nop-kinds-table__enable"><?php echo esc_html( $last_label ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td class="nop-kinds-table__status">
						<select name="<?php echo esc_attr( "{$prefix}[post_status]" ); ?>">
							<?php foreach ( self::post_statuses() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['post_status'] ?? 'publish', $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<?php $this->render_token_field(
							"nop-{$slug}-category",
							"{$prefix}[post_category]",
							$settings['post_category'] ?? '',
							$category_names,
							__( 'Add category…', 'nop-indieweb' ),
							__( 'Category name', 'nop-indieweb' )
						); ?>
					</td>
					<td>
						<?php $this->render_token_field(
							"nop-{$slug}-tags",
							"{$prefix}[post_tags]",
							$settings['post_tags'] ?? $tag_default,
							$tag_names,
							__( 'Add tags…', 'nop-indieweb' ),
							__( 'Tag name', 'nop-indieweb' )
						); ?>
					</td>
					<td class="nop-kinds-table__enable">
						<input type="checkbox" name="<?php echo esc_attr( "{$prefix}[{$last_field}]" ); ?>" value="1"
						       aria-label="<?php echo esc_attr( $last_aria ); ?>"
						       <?php checked( $settings[ $last_field ] ?? $last_def ); ?>>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	private function render_inbound_defaults( string $slug, string $name_prefix, array $settings ): void {
		?>
		<h3 class="nop-section-heading"><?php esc_html_e( 'Inbound Defaults', 'nop-indieweb' ); ?></h3>
		<p class="description nop-section-intro"><?php echo wp_kses( __( 'Applied to posts received via <a href="https://brid.gy" target="_blank" rel="noopener">Bridgy</a> from this platform.', 'nop-indieweb' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?></p>
		<?php
		$this->render_defaults_table( [
			'slug'     => "{$slug}-in",
			'prefix'   => $name_prefix,
			'settings' => $settings,
		] );
	}

	// ——— Assets ——————————————————————————————————————————————————————————————

	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'nop-indieweb-admin', NOP_INDIEWEB_URL . 'assets/css/admin.css', [], NOP_INDIEWEB_VERSION );
		wp_enqueue_script( 'nop-indieweb-admin', NOP_INDIEWEB_URL . 'assets/js/admin.js', [], NOP_INDIEWEB_VERSION, true );
	}
}
