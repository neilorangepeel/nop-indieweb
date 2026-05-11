<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Admin;

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

	private const TAB_GROUPS = [
		'Site' => [
			'general'      => 'General',
			'indieauth'    => 'IndieAuth',
			'semantic'     => 'Microformats',
			'webmentions'  => 'Webmentions',
		],
		'Services' => [
			'entries'     => 'Entries',
			'swarm'       => 'Swarm',
			'mastodon'    => 'Mastodon',
			'bluesky'     => 'Bluesky',
			'pixelfed'    => 'Pixelfed',
			'letterboxd'  => 'Letterboxd',
		],
	];

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
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$service = sanitize_key( $_POST['service'] ?? '' );

		if ( 'mastodon' === $service ) {
			$instance = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.mastodon.instance', '' );
			$token    = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.mastodon.access_token', '' );

			if ( ! $instance || ! $token ) {
				wp_send_json_error( 'Not configured.' );
			}

			$response = wp_remote_get( rtrim( $instance, '/' ) . '/api/v1/accounts/verify_credentials', [
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => 10,
			] );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( $response->get_error_message() );
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code && isset( $body['acct'] ) ) {
				if ( ! empty( $body['url'] ) ) {
					update_option( 'nop_indieweb_mastodon_profile_url', esc_url_raw( $body['url'] ) );
				}
				wp_send_json_success( 'Connected as @' . $body['acct'] );
			} else {
				wp_send_json_error( 'Error ' . $code . ': ' . ( $body['error'] ?? 'Unknown error' ) );
			}
		} elseif ( 'pixelfed' === $service ) {
			$instance = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.pixelfed.instance', '' );
			$token    = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.pixelfed.access_token', '' );

			if ( ! $instance || ! $token ) {
				wp_send_json_error( 'Not configured.' );
			}

			$response = wp_remote_get( rtrim( $instance, '/' ) . '/api/v1/accounts/verify_credentials', [
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => 10,
			] );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( $response->get_error_message() );
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code && isset( $body['acct'] ) ) {
				if ( ! empty( $body['url'] ) ) {
					update_option( 'nop_indieweb_pixelfed_profile_url', esc_url_raw( $body['url'] ) );
				}
				wp_send_json_success( 'Connected as @' . $body['acct'] );
			} else {
				wp_send_json_error( 'Error ' . $code . ': ' . ( $body['error'] ?? 'Unknown error' ) );
			}
		} elseif ( 'bluesky' === $service ) {
			$handle   = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.bluesky.handle', '' );
			$password = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators.bluesky.app_password', '' );

			if ( ! $handle || ! $password ) {
				wp_send_json_error( 'Not configured.' );
			}

			$response = wp_remote_post( 'https://bsky.social/xrpc/com.atproto.server.createSession', [
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'identifier' => $handle, 'password' => $password ] ),
				'timeout' => 10,
			] );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( $response->get_error_message() );
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $code && isset( $body['handle'] ) ) {
				wp_send_json_success( 'Connected as @' . $body['handle'] );
			} else {
				wp_send_json_error( 'Error ' . $code . ': ' . ( $body['message'] ?? 'Unknown error' ) );
			}
		} else {
			wp_send_json_error( 'Unknown service.' );
		}
	}

	public function add_page(): void {
		add_options_page(
			'IndieWeb Settings',
			'IndieWeb',
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
			wp_die( 'Unauthorized.' );
		}

		Token_Store::delete_by_id( $token_id );

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '#nop-tab-indieauth' ) );
		exit;
	}

	// ——— Settings sanitize ————————————————————————————————————————————————————

	public function sanitize( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return get_option( self::OPTION_KEY, [] );
		}

		$clean = get_option( self::OPTION_KEY, [] );

		// Ensure the legacy static token is gone.
		unset( $clean['secret_token'] );

		// — General ———————————————————————————————————————————————————————————
		$clean['debug_mode']  = ! empty( $input['debug_mode'] );

		// — Semantic Web ——————————————————————————————————————————————————————
		$clean['mf2_enabled'] = ! empty( $input['mf2_enabled'] );

		// — Swarm —————————————————————————————————————————————————————————————
		$valid_statuses = [ 'publish', 'draft', 'private' ];

		$clean['services']['swarm']['enabled']         = ! empty( $input['services']['swarm']['enabled'] );
		$clean['services']['swarm']['post_status']     = in_array( $input['services']['swarm']['post_status'] ?? '', $valid_statuses, true )
			? $input['services']['swarm']['post_status']
			: 'publish';
		$clean['services']['swarm']['post_format']     = sanitize_key( $input['services']['swarm']['post_format'] ?? 'status' );
		$clean['services']['swarm']['post_category']   = sanitize_text_field( $input['services']['swarm']['post_category'] ?? 'Checkin' );
		$clean['services']['swarm']['post_tags']       = sanitize_text_field( $input['services']['swarm']['post_tags'] ?? 'Swarm' );
		$clean['services']['swarm']['sideload_photos'] = ! empty( $input['services']['swarm']['sideload_photos'] );

		// — Entries ———————————————————————————————————————————————————————————————
		$clean['services']['entries']['enabled']         = ! empty( $input['services']['entries']['enabled'] );
		$clean['services']['entries']['post_status']     = in_array( $input['services']['entries']['post_status'] ?? '', $valid_statuses, true )
			? $input['services']['entries']['post_status']
			: 'publish';
		$clean['services']['entries']['post_format']     = sanitize_key( $input['services']['entries']['post_format'] ?? 'status' );
		$clean['services']['entries']['post_category']   = sanitize_text_field( $input['services']['entries']['post_category'] ?? 'Notes' );
		$clean['services']['entries']['post_tags']       = sanitize_text_field( $input['services']['entries']['post_tags'] ?? '' );
		$clean['services']['entries']['sideload_photos'] = ! empty( $input['services']['entries']['sideload_photos'] );

		// — Mastodon ——————————————————————————————————————————————————————————————
		$clean['syndicators']['mastodon']['enabled']      = ! empty( $input['syndicators']['mastodon']['enabled'] );
		$clean['syndicators']['mastodon']['instance']     = esc_url_raw( $input['syndicators']['mastodon']['instance'] ?? '' );
		$clean['syndicators']['mastodon']['access_token'] = sanitize_text_field( $input['syndicators']['mastodon']['access_token'] ?? '' );
		// Inbound defaults (posts received from Mastodon via Bridgy)
		$clean['syndicators']['mastodon']['post_status']     = in_array( $input['syndicators']['mastodon']['post_status'] ?? '', $valid_statuses, true )
			? $input['syndicators']['mastodon']['post_status']
			: 'publish';
		$clean['syndicators']['mastodon']['post_format']     = sanitize_key( $input['syndicators']['mastodon']['post_format'] ?? 'status' );
		$clean['syndicators']['mastodon']['post_category']   = sanitize_text_field( $input['syndicators']['mastodon']['post_category'] ?? 'Notes' );
		$clean['syndicators']['mastodon']['post_tags']       = sanitize_text_field( $input['syndicators']['mastodon']['post_tags'] ?? '' );
		$clean['syndicators']['mastodon']['sideload_photos']  = ! empty( $input['syndicators']['mastodon']['sideload_photos'] );
		$clean['syndicators']['mastodon']['import_enabled']   = ! empty( $input['syndicators']['mastodon']['import_enabled'] );

		// — Bluesky ———————————————————————————————————————————————————————————————
		$clean['syndicators']['bluesky']['enabled']      = ! empty( $input['syndicators']['bluesky']['enabled'] );
		$clean['syndicators']['bluesky']['handle']       = sanitize_text_field( $input['syndicators']['bluesky']['handle'] ?? '' );
		$clean['syndicators']['bluesky']['app_password'] = sanitize_text_field( $input['syndicators']['bluesky']['app_password'] ?? '' );
		// Inbound defaults (posts received from Bluesky via Bridgy)
		$clean['syndicators']['bluesky']['post_status']     = in_array( $input['syndicators']['bluesky']['post_status'] ?? '', $valid_statuses, true )
			? $input['syndicators']['bluesky']['post_status']
			: 'publish';
		$clean['syndicators']['bluesky']['post_format']     = sanitize_key( $input['syndicators']['bluesky']['post_format'] ?? 'status' );
		$clean['syndicators']['bluesky']['post_category']   = sanitize_text_field( $input['syndicators']['bluesky']['post_category'] ?? 'Notes' );
		$clean['syndicators']['bluesky']['post_tags']       = sanitize_text_field( $input['syndicators']['bluesky']['post_tags'] ?? '' );
		$clean['syndicators']['bluesky']['sideload_photos']  = ! empty( $input['syndicators']['bluesky']['sideload_photos'] );
		$clean['syndicators']['bluesky']['import_enabled']   = ! empty( $input['syndicators']['bluesky']['import_enabled'] );

		// — Pixelfed ——————————————————————————————————————————————————————————————
		$clean['syndicators']['pixelfed']['enabled']      = ! empty( $input['syndicators']['pixelfed']['enabled'] );
		$clean['syndicators']['pixelfed']['instance']     = esc_url_raw( $input['syndicators']['pixelfed']['instance'] ?? '' );
		$clean['syndicators']['pixelfed']['access_token'] = sanitize_text_field( $input['syndicators']['pixelfed']['access_token'] ?? '' );
		$clean['syndicators']['pixelfed']['post_status']     = in_array( $input['syndicators']['pixelfed']['post_status'] ?? '', $valid_statuses, true )
			? $input['syndicators']['pixelfed']['post_status']
			: 'publish';
		$clean['syndicators']['pixelfed']['post_format']     = sanitize_key( $input['syndicators']['pixelfed']['post_format'] ?? 'status' );
		$clean['syndicators']['pixelfed']['post_category']   = sanitize_text_field( $input['syndicators']['pixelfed']['post_category'] ?? 'Notes' );
		$clean['syndicators']['pixelfed']['post_tags']       = sanitize_text_field( $input['syndicators']['pixelfed']['post_tags'] ?? '' );
		$clean['syndicators']['pixelfed']['sideload_photos'] = ! empty( $input['syndicators']['pixelfed']['sideload_photos'] );
		$clean['syndicators']['pixelfed']['import_enabled']  = ! empty( $input['syndicators']['pixelfed']['import_enabled'] );

		// — Webmentions ——————————————————————————————————————————————————————————
		$valid_approval = [ 'bridgy_only', 'auto_all', 'manual_all' ];

		$clean['webmentions']['receive_enabled'] = ! empty( $input['webmentions']['receive_enabled'] );
		$clean['webmentions']['approval']        = in_array( $input['webmentions']['approval'] ?? '', $valid_approval, true )
			? $input['webmentions']['approval']
			: 'bridgy_only';

		// — Letterboxd ————————————————————————————————————————————————————————————
		$clean['services']['letterboxd']['import_enabled']  = ! empty( $input['services']['letterboxd']['import_enabled'] );
		$clean['services']['letterboxd']['username']        = sanitize_text_field( $input['services']['letterboxd']['username'] ?? '' );
		$clean['services']['letterboxd']['post_status']     = in_array( $input['services']['letterboxd']['post_status'] ?? '', $valid_statuses, true )
			? $input['services']['letterboxd']['post_status']
			: 'publish';
		$clean['services']['letterboxd']['post_category']   = sanitize_text_field( $input['services']['letterboxd']['post_category'] ?? 'Films' );
		$clean['services']['letterboxd']['post_tags']       = sanitize_text_field( $input['services']['letterboxd']['post_tags'] ?? '' );
		$clean['services']['letterboxd']['sideload_poster'] = ! empty( $input['services']['letterboxd']['sideload_poster'] );

		return $clean;
	}

	// ——— Page render ——————————————————————————————————————————————————————————

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap nop-indieweb-settings">

			<h1>IndieWeb</h1>

			<?php $this->render_setup_guide(); ?>

			<nav class="nav-tab-wrapper nop-nav-tabs" aria-label="Settings sections">
				<?php foreach ( self::TAB_GROUPS as $tabs ) : ?>
					<?php foreach ( $tabs as $slug => $label ) : ?>
						<?php $tab_enabled = $this->is_tab_enabled( $slug ); ?>
						<a href="#nop-tab-<?php echo esc_attr( $slug ); ?>"
						   class="nav-tab<?php echo ! $tab_enabled ? ' nop-tab--inactive' : ''; ?>"
						   data-tab="<?php echo esc_attr( $slug ); ?>"
						   <?php if ( ! $tab_enabled ) : ?>title="<?php echo esc_attr( $label ); ?> is disabled"<?php endif; ?>>
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php" class="nop-settings-form">
				<?php settings_fields( self::OPTION_KEY ); ?>

				<div id="nop-tab-general" class="nop-tab-panel">
					<?php $this->render_tab_general(); ?>
				</div>

				<div id="nop-tab-indieauth" class="nop-tab-panel" hidden>
					<?php $this->render_tab_indieauth(); ?>
				</div>

				<div id="nop-tab-semantic" class="nop-tab-panel" hidden>
					<?php $this->render_tab_semantic(); ?>
				</div>

				<div id="nop-tab-entries" class="nop-tab-panel" hidden>
					<?php $this->render_tab_entries(); ?>
				</div>

				<div id="nop-tab-swarm" class="nop-tab-panel" hidden>
					<?php $this->render_tab_swarm(); ?>
				</div>

				<div id="nop-tab-mastodon" class="nop-tab-panel" hidden>
					<?php $this->render_tab_mastodon(); ?>
				</div>

				<div id="nop-tab-bluesky" class="nop-tab-panel" hidden>
					<?php $this->render_tab_bluesky(); ?>
				</div>

				<div id="nop-tab-pixelfed" class="nop-tab-panel" hidden>
					<?php $this->render_tab_pixelfed(); ?>
				</div>

				<div id="nop-tab-letterboxd" class="nop-tab-panel" hidden>
					<?php $this->render_tab_letterboxd(); ?>
				</div>

				<div id="nop-tab-webmentions" class="nop-tab-panel" hidden>
					<?php $this->render_tab_webmentions(); ?>
				</div>

				<div class="nop-settings-footer">
					<?php submit_button( 'Save Changes', 'primary', 'submit', false ); ?>
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
				'label' => 'Publish your first IndieWeb post',
				'body'  => 'Connect <a href="https://ownyourswarm.p3k.io" target="_blank" rel="noopener">OwnYourSwarm</a> to post location checkins, or enable Mastodon or Bluesky in their tabs to start syndicating. Your Micropub endpoint is <code>' . esc_html( $micropub_url ) . '</code>.',
				'link'  => [ 'href' => $debug_url, 'text' => 'Test with the Debug panel' ],
			],
			[
				'done'  => $is_live,
				'label' => 'Go live',
				'body'  => 'Services default to Draft while you get set up. Switch <strong>Post Status</strong> to Published in the Swarm tab, or enable Mastodon or Bluesky to start syndicating.',
				'link'  => [ 'href' => '#nop-tab-swarm', 'text' => 'Open Swarm tab' ],
			],
			[
				'done'  => $has_syndication,
				'label' => 'Connect Mastodon or Bluesky',
				'body'  => 'Add your credentials in the Mastodon or Bluesky tabs to syndicate posts outward automatically. Once connected, you can set up <a href="https://brid.gy" target="_blank" rel="noopener">Bridgy</a> to receive posts back to your site too.',
				'link'  => [ 'href' => '#nop-tab-mastodon', 'text' => 'Open Mastodon tab' ],
			],
		];
		?>
		<div class="nop-setup-guide">
			<p class="nop-setup-guide__heading">Quick setup</p>
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
		return match( $slug ) {
			'swarm'    => (bool) ( \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['swarm']['enabled'] ?? true ),
			'entries'  => (bool) ( \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['entries']['enabled'] ?? true ),
			'mastodon' => (bool) ( \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['mastodon']['enabled'] ?? false ),
			'bluesky'  => (bool) ( \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['bluesky']['enabled'] ?? false ),
			'pixelfed'   => (bool) ( \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['pixelfed']['enabled'] ?? false ),
			'letterboxd' => (bool) ( \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['letterboxd']['import_enabled'] ?? false ),
			default      => true,
		};
	}

	// ——— Tab: General ————————————————————————————————————————————————————————

	private function render_tab_general(): void {
		$endpoint = esc_url( \NOP\IndieWeb\nop_indieweb_endpoint_url() );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Micropub Endpoint</th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo $endpoint; ?>" target="_blank" rel="noopener"><?php echo $endpoint; ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $endpoint ); ?>">Copy</button>
					</div>
					<p class="description">
						Protected via IndieAuth. See the <a href="#nop-tab-indieauth" class="nop-setup-link">IndieAuth tab</a> to manage active sessions.
					</p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading">Advanced</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Debug Mode</th>
				<td>
					<?php
					$checked = checked( \NOP\IndieWeb\nop_indieweb_get_option( 'debug_mode', false ), true, false );
					$name    = self::OPTION_KEY . '[debug_mode]';
					?>
					<label>
						<input type="checkbox" name="<?php echo $name; ?>" value="1" <?php echo $checked; ?>>
						Log all Micropub requests to the WordPress error log
					</label>
					<p class="description">Disable in production — payloads are logged in full and may contain personal data.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ——— Tab: IndieAuth ——————————————————————————————————————————————————————

	private function render_tab_indieauth(): void {
		$auth_url  = esc_url( Auth_Endpoint::url() );
		$token_url = esc_url( rest_url( 'nop-indieweb/v1/token' ) );
		$sessions  = Token_Store::get_by_user( get_current_user_id() );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Authorization Endpoint</th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo $auth_url; ?>" target="_blank" rel="noopener"><?php echo $auth_url; ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $auth_url ); ?>">Copy</button>
					</div>
					<p class="description">Micropub clients redirect here so you can approve their access.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Token Endpoint</th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo $token_url; ?>" target="_blank" rel="noopener"><?php echo $token_url; ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $token_url ); ?>">Copy</button>
					</div>
					<p class="description">Clients exchange their authorization code here for a Bearer token.</p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading">Active Sessions</h3>

		<?php if ( empty( $sessions ) ) : ?>
			<p class="nop-sessions-empty">No applications authorized yet. Connect a Micropub client to see sessions here.</p>
		<?php else : ?>
			<table class="nop-sessions-table">
				<thead>
					<tr>
						<th>Application</th>
						<th>Permissions</th>
						<th>Authorized</th>
						<th>Last used</th>
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
								   onclick="return confirm('Revoke access for <?php echo esc_js( $session['client_name'] ?: $session['client_id'] ); ?>?')">
									Revoke
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	// ——— Tab: Swarm ——————————————————————————————————————————————————————————

	private function render_tab_swarm(): void {
		$settings       = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['swarm'] ?? [];
		$prefix         = self::OPTION_KEY . '[services][swarm]';
		$formats        = $this->get_formats();
		$category_names = array_values( array_map( fn( $c ) => $c->name, get_categories( [ 'hide_empty' => false, 'orderby' => 'name' ] ) ) );
		$tag_names      = array_values( array_map( fn( $t ) => $t->name, get_tags( [ 'hide_empty' => false, 'orderby' => 'name' ] ) ) );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Service</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[enabled]"; ?>" value="1"
						       <?php checked( $settings['enabled'] ?? true ); ?>>
						Accept checkins from <a href="https://ownyourswarm.p3k.io" target="_blank" rel="noopener">OwnYourSwarm</a>
					</label>
				</td>
			</tr>
		</table>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="nop_swarm_post_status">Status</label>
				</th>
				<td>
					<select id="nop_swarm_post_status" name="<?php echo "{$prefix}[post_status]"; ?>">
						<?php
						foreach ( [ 'publish' => 'Published', 'draft' => 'Draft', 'private' => 'Private' ] as $value => $label ) {
							printf(
								'<option value="%s" %s>%s</option>',
								esc_attr( $value ),
								selected( $settings['post_status'] ?? 'publish', $value, false ),
								esc_html( $label )
							);
						}
						?>
					</select>
					<p class="description">Use <strong>Draft</strong> while testing — switch to Published when you're ready to go live.</p>
				</td>
			</tr>
			<?php if ( count( $formats ) > 1 ) : ?>
			<tr>
				<th scope="row">
					<label for="nop_swarm_post_format">Format</label>
				</th>
				<td>
					<select id="nop_swarm_post_format" name="<?php echo "{$prefix}[post_format]"; ?>">
						<?php
						foreach ( $formats as $format ) {
							printf(
								'<option value="%s" %s>%s</option>',
								esc_attr( $format ),
								selected( $settings['post_format'] ?? 'status', $format, false ),
								esc_html( ucfirst( $format ) )
							);
						}
						?>
					</select>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th scope="row">
					<label for="nop-swarm-category-input">Category</label>
				</th>
				<td>
					<?php $this->render_token_field(
						'nop-swarm-category-input',
						"{$prefix}[post_category]",
						$settings['post_category'] ?? 'Checkin',
						$category_names,
						'Add category…',
						'Category name',
						'Created automatically if it doesn\'t exist.'
					); ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="nop-swarm-tags-input">Tags</label>
				</th>
				<td>
					<?php $this->render_token_field(
						'nop-swarm-tags-input',
						"{$prefix}[post_tags]",
						$settings['post_tags'] ?? 'Swarm',
						$tag_names,
						'Add tags…',
						'Tag name',
						'New tags are created automatically.'
					); ?>
				</td>
			</tr>
		</table>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Photos</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[sideload_photos]"; ?>" value="1"
						       <?php checked( $settings['sideload_photos'] ?? true ); ?>>
						Save Swarm photos to your media library
					</label>
					<p class="description">
						Protects against Swarm CDN changes or account deletion.
						Disable if you see timeouts on slow hosting.
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ——— Tab: Notes ——————————————————————————————————————————————————————————

	private function render_tab_entries(): void {
		$settings       = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['entries'] ?? [];
		$prefix         = self::OPTION_KEY . '[services][entries]';
		$formats        = $this->get_formats();
		$category_names = array_values( array_map( fn( $c ) => $c->name, get_categories( [ 'hide_empty' => false, 'orderby' => 'name' ] ) ) );
		$tag_names      = array_values( array_map( fn( $t ) => $t->name, get_tags( [ 'hide_empty' => false, 'orderby' => 'name' ] ) ) );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Service</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[enabled]"; ?>" value="1"
						       <?php checked( $settings['enabled'] ?? true ); ?>>
						Accept generic Micropub posts — catch-all for any h-entry not handled by a named service
					</label>
				</td>
			</tr>
		</table>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="nop_note_post_status">Status</label>
				</th>
				<td>
					<select id="nop_note_post_status" name="<?php echo "{$prefix}[post_status]"; ?>">
						<?php
						foreach ( [ 'publish' => 'Published', 'draft' => 'Draft', 'private' => 'Private' ] as $value => $label ) {
							printf(
								'<option value="%s" %s>%s</option>',
								esc_attr( $value ),
								selected( $settings['post_status'] ?? 'publish', $value, false ),
								esc_html( $label )
							);
						}
						?>
					</select>
					<p class="description">Use <strong>Draft</strong> while testing — switch to Published when you're ready to go live.</p>
				</td>
			</tr>
			<?php if ( count( $formats ) > 1 ) : ?>
			<tr>
				<th scope="row">
					<label for="nop_note_post_format">Format</label>
				</th>
				<td>
					<select id="nop_note_post_format" name="<?php echo "{$prefix}[post_format]"; ?>">
						<?php
						foreach ( $formats as $format ) {
							printf(
								'<option value="%s" %s>%s</option>',
								esc_attr( $format ),
								selected( $settings['post_format'] ?? 'status', $format, false ),
								esc_html( ucfirst( $format ) )
							);
						}
						?>
					</select>
					<p class="description">Mastodon and Bluesky posts are short-form — <strong>Status</strong> is recommended.</p>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th scope="row">
					<label for="nop-note-category-input">Category</label>
				</th>
				<td>
					<?php $this->render_token_field(
						'nop-note-category-input',
						"{$prefix}[post_category]",
						$settings['post_category'] ?? 'Notes',
						$category_names,
						'Add category…',
						'Category name',
						'Created automatically if it doesn\'t exist.'
					); ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="nop-note-tags-input">Tags</label>
				</th>
				<td>
					<?php $this->render_token_field(
						'nop-note-tags-input',
						"{$prefix}[post_tags]",
						$settings['post_tags'] ?? '',
						$tag_names,
						'Add tags…',
						'Tag name',
						'New tags are created automatically.'
					); ?>
				</td>
			</tr>
		</table>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Photos</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[sideload_photos]"; ?>" value="1"
						       <?php checked( $settings['sideload_photos'] ?? true ); ?>>
						Save photos to your media library
					</label>
					<p class="description">
						Protects against remote CDN changes. Disable if you see timeouts on slow hosting.
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ——— Tab: Mastodon ———————————————————————————————————————————————————————

	private function render_tab_mastodon(): void {
		$prefix   = self::OPTION_KEY . '[syndicators][mastodon]';
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['mastodon'] ?? [];
		?>
		<p>Syndicates posts to your Mastodon account when you publish. Requires an access token from your instance.</p>
		<p><a href="https://docs.joinmastodon.org/client/token/" target="_blank" rel="noopener">How to create an access token →</a></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Enable</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[enabled]"; ?>" value="1"
						       <?php checked( $settings['enabled'] ?? false ); ?>>
						Syndicate posts to Mastodon on publish
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="mastodon-instance">Instance URL</label></th>
				<td>
					<input type="url" id="mastodon-instance" name="<?php echo "{$prefix}[instance]"; ?>"
					       value="<?php echo esc_attr( $settings['instance'] ?? '' ); ?>"
					       class="regular-text" placeholder="https://mastodon.social">
					<p class="description">Your Mastodon instance URL (e.g. https://mastodon.social).</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="mastodon-token">Access Token</label></th>
				<td>
					<input type="password" id="mastodon-token" name="<?php echo "{$prefix}[access_token]"; ?>"
					       value="<?php echo esc_attr( $settings['access_token'] ?? '' ); ?>"
					       class="regular-text" autocomplete="off">
					<p class="description">From your Mastodon instance: Preferences → Development → New Application. Needs <code>write:statuses read:statuses</code> scopes.</p>
				</td>
			</tr>
		</table>

		<?php if ( ! empty( $settings['access_token'] ) && ! empty( $settings['instance'] ) ) : ?>
		<p>
			<button type="button" class="button nop-test-connection" data-service="mastodon"
			        data-nonce="<?php echo esc_attr( wp_create_nonce( 'nop_test_connection' ) ); ?>">
				Test connection
			</button>
			<span class="nop-test-result" style="margin-left:8px;"></span>
		</p>
		<?php endif; ?>

		<?php $this->render_inbound_defaults( 'mastodon', $prefix, $settings ); ?>

		<h3 class="nop-section-heading">Import</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Import posts</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[import_enabled]"; ?>" value="1"
						       <?php checked( $settings['import_enabled'] ?? false ); ?>>
						Automatically import your Mastodon posts as WordPress entries (hourly)
					</label>
					<p class="description">Replies and boosts are excluded. Posts already published from WordPress are skipped.</p>
				</td>
			</tr>
			<?php if ( ! empty( $settings['import_enabled'] ) ) : ?>
			<tr>
				<th scope="row">Sync now</th>
				<td>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'nop_indieweb_sync', 'mastodon', admin_url( 'options-general.php?page=nop-indieweb-settings' ) ), 'nop_indieweb_sync_mastodon' ) ); ?>"
					   class="button">Import from Mastodon now</a>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	private function render_tab_bluesky(): void {
		$prefix   = self::OPTION_KEY . '[syndicators][bluesky]';
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['bluesky'] ?? [];
		?>
		<p>Syndicates posts to your Bluesky account when you publish. Uses an app password — never your main password.</p>
		<p><a href="https://bsky.app/settings/app-passwords" target="_blank" rel="noopener">Create an app password →</a></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Enable</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[enabled]"; ?>" value="1"
						       <?php checked( $settings['enabled'] ?? false ); ?>>
						Syndicate posts to Bluesky on publish
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bluesky-handle">Handle</label></th>
				<td>
					<input type="text" id="bluesky-handle" name="<?php echo "{$prefix}[handle]"; ?>"
					       value="<?php echo esc_attr( $settings['handle'] ?? '' ); ?>"
					       class="regular-text" placeholder="neilorangepeel.com">
					<p class="description">Your Bluesky handle — either <code>you.bsky.social</code> or your custom domain handle.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bluesky-password">App Password</label></th>
				<td>
					<input type="password" id="bluesky-password" name="<?php echo "{$prefix}[app_password]"; ?>"
					       value="<?php echo esc_attr( $settings['app_password'] ?? '' ); ?>"
					       class="regular-text" autocomplete="off">
					<p class="description">From Bluesky: Settings → Privacy and Security → App Passwords.</p>
				</td>
			</tr>
		</table>

		<?php if ( ! empty( $settings['app_password'] ) && ! empty( $settings['handle'] ) ) : ?>
		<p>
			<button type="button" class="button nop-test-connection" data-service="bluesky"
			        data-nonce="<?php echo esc_attr( wp_create_nonce( 'nop_test_connection' ) ); ?>">
				Test connection
			</button>
			<span class="nop-test-result" style="margin-left:8px;"></span>
		</p>
		<?php endif; ?>

		<?php $this->render_inbound_defaults( 'bluesky', $prefix, $settings ); ?>

		<h3 class="nop-section-heading">Import</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Import posts</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[import_enabled]"; ?>" value="1"
						       <?php checked( $settings['import_enabled'] ?? false ); ?>>
						Automatically import your Bluesky posts as WordPress entries (hourly)
					</label>
					<p class="description">Replies and reposts are excluded. Posts already published from WordPress are skipped.</p>
				</td>
			</tr>
			<?php if ( ! empty( $settings['import_enabled'] ) ) : ?>
			<tr>
				<th scope="row">Sync now</th>
				<td>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'nop_indieweb_sync', 'bluesky', admin_url( 'options-general.php?page=nop-indieweb-settings' ) ), 'nop_indieweb_sync_bluesky' ) ); ?>"
					   class="button">Import from Bluesky now</a>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	private function render_tab_pixelfed(): void {
		$prefix   = self::OPTION_KEY . '[syndicators][pixelfed]';
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['pixelfed'] ?? [];
		?>
		<p>Syndicates posts to your Pixelfed account when you publish. Pixelfed uses the same API as Mastodon — create an access token from your instance's developer settings.</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Enable</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[enabled]"; ?>" value="1"
						       <?php checked( $settings['enabled'] ?? false ); ?>>
						Syndicate posts to Pixelfed on publish
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pixelfed-instance">Instance URL</label></th>
				<td>
					<input type="url" id="pixelfed-instance" name="<?php echo "{$prefix}[instance]"; ?>"
					       value="<?php echo esc_attr( $settings['instance'] ?? '' ); ?>"
					       class="regular-text" placeholder="https://pixelfed.social">
					<p class="description">Your Pixelfed instance URL (e.g. https://pixelfed.social).</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pixelfed-token">Access Token</label></th>
				<td>
					<input type="password" id="pixelfed-token" name="<?php echo "{$prefix}[access_token]"; ?>"
					       value="<?php echo esc_attr( $settings['access_token'] ?? '' ); ?>"
					       class="regular-text" autocomplete="off">
					<p class="description">From your Pixelfed instance: Settings → Applications → Create App. Needs <code>read write</code> scopes.</p>
				</td>
			</tr>
		</table>

		<?php if ( ! empty( $settings['access_token'] ) && ! empty( $settings['instance'] ) ) : ?>
		<p>
			<button type="button" class="button nop-test-connection" data-service="pixelfed"
			        data-nonce="<?php echo esc_attr( wp_create_nonce( 'nop_test_connection' ) ); ?>">
				Test connection
			</button>
			<span class="nop-test-result" style="margin-left:8px;"></span>
		</p>
		<?php endif; ?>

		<?php $this->render_inbound_defaults( 'pixelfed', $prefix, $settings ); ?>

		<h3 class="nop-section-heading">Import</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Import posts</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[import_enabled]"; ?>" value="1"
						       <?php checked( $settings['import_enabled'] ?? false ); ?>>
						Automatically import your Pixelfed posts as WordPress entries (hourly)
					</label>
					<p class="description">Replies and boosts are excluded. Posts already published from WordPress are skipped.</p>
				</td>
			</tr>
			<?php if ( ! empty( $settings['import_enabled'] ) ) : ?>
			<tr>
				<th scope="row">Sync now</th>
				<td>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'nop_indieweb_sync', 'pixelfed', admin_url( 'options-general.php?page=nop-indieweb-settings' ) ), 'nop_indieweb_sync_pixelfed' ) ); ?>"
					   class="button">Import from Pixelfed now</a>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	private function render_tab_letterboxd(): void {
		$prefix   = self::OPTION_KEY . '[services][letterboxd]';
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['letterboxd'] ?? [];
		$formats  = $this->get_formats();

		$username = $settings['username'] ?? '';
		?>
		<p>Imports your Letterboxd diary as WordPress posts — one post per film watched. No API key required; Letterboxd diary feeds are public.</p>

		<h3 class="nop-section-heading">Import</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Import posts</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[import_enabled]"; ?>" value="1"
						       <?php checked( $settings['import_enabled'] ?? false ); ?>>
						Automatically import your Letterboxd diary as WordPress posts (hourly)
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="letterboxd-username">Username</label></th>
				<td>
					<input type="text" id="letterboxd-username" name="<?php echo "{$prefix}[username]"; ?>"
					       value="<?php echo esc_attr( $username ); ?>"
					       class="regular-text" placeholder="your-letterboxd-username">
					<?php if ( $username ) : ?>
					<p class="description">
						Feed: <a href="https://letterboxd.com/<?php echo esc_attr( $username ); ?>/rss/" target="_blank" rel="noopener">
							letterboxd.com/<?php echo esc_html( $username ); ?>/rss/
						</a>
					</p>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( ! empty( $settings['import_enabled'] ) ) : ?>
			<tr>
				<th scope="row">Sync now</th>
				<td>
					<a href="<?php echo esc_url( wp_nonce_url(
						add_query_arg( 'nop_indieweb_sync', 'letterboxd', admin_url( 'options-general.php?page=nop-indieweb-settings' ) ),
						'nop_indieweb_sync_letterboxd'
					) ); ?>" class="button">Import from Letterboxd now</a>
				</td>
			</tr>
			<?php endif; ?>
		</table>

		<h3 class="nop-section-heading">Inbound Defaults</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Post status</th>
				<td>
					<select name="<?php echo "{$prefix}[post_status]"; ?>">
						<?php foreach ( [ 'publish', 'draft', 'private' ] as $status ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>"
							        <?php selected( $settings['post_status'] ?? 'publish', $status ); ?>>
								<?php echo esc_html( ucfirst( $status ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="letterboxd-in-category">Category</label></th>
				<td>
					<?php
					$category_names = array_map( fn( $t ) => $t->name, get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] ) );
					$this->render_token_field(
						'letterboxd-in-category',
						"{$prefix}[post_category]",
						$settings['post_category'] ?? 'Films',
						$category_names,
						'Add category…',
						'Category name',
						'Created automatically if it doesn\'t exist.'
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="letterboxd-in-tags">Tags</label></th>
				<td>
					<?php
					$tag_names = array_map( fn( $t ) => $t->name, get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false ] ) );
					$this->render_token_field(
						'letterboxd-in-tags',
						"{$prefix}[post_tags]",
						$settings['post_tags'] ?? '',
						$tag_names,
						'Add tags…',
						'Tag name'
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row">Poster image</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[sideload_poster]"; ?>" value="1"
						       <?php checked( $settings['sideload_poster'] ?? true ); ?>>
						Save film poster to your media library and set as featured image
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	// ——— Tab: Webmentions ———————————————————————————————————————————————————

	private function render_tab_webmentions(): void {
		$settings        = \NOP\IndieWeb\nop_indieweb_get_option( 'webmentions', [] );
		$prefix          = self::OPTION_KEY . '[webmentions]';
		$receive_enabled = $settings['receive_enabled'] ?? true;
		$approval        = $settings['approval'] ?? 'bridgy_only';
		$endpoint_url    = esc_url( rest_url( 'nop-indieweb/v1/webmention' ) );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Endpoint</th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo $endpoint_url; ?>" target="_blank" rel="noopener"><?php echo $endpoint_url; ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $endpoint_url ); ?>">Copy</button>
					</div>
					<p class="description">Advertised via <code>&lt;link rel="webmention"&gt;</code> and <code>Link</code> header on every page.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Receive</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[receive_enabled]"; ?>" value="1"
						       <?php checked( $receive_enabled ); ?>>
						Accept incoming webmentions
					</label>
					<p class="description">Uncheck to stop accepting new webmentions. Existing ones are kept.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nop-webmention-approval">Approval</label></th>
				<td>
					<select id="nop-webmention-approval" name="<?php echo "{$prefix}[approval]"; ?>">
						<option value="bridgy_only" <?php selected( $approval, 'bridgy_only' ); ?>>Auto-approve Bridgy, hold everything else</option>
						<option value="auto_all"    <?php selected( $approval, 'auto_all' ); ?>>Auto-approve all</option>
						<option value="manual_all"  <?php selected( $approval, 'manual_all' ); ?>>Hold all for manual review</option>
					</select>
					<p class="description">Held webmentions appear in <a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_type=webmention' ) ); ?>">Comments → Webmentions</a> awaiting approval.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_tab_semantic(): void {
		$enabled = \NOP\IndieWeb\nop_indieweb_get_option( 'mf2_enabled', true );
		$name    = self::OPTION_KEY . '[mf2_enabled]';

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

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Markup</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo $name; ?>" value="1" <?php checked( $enabled ); ?>>
						Add microformats2 markup to IndieWeb posts
					</label>
					<p class="description">
						Injected at render time — nothing is stored in your database.
						Deactivating the plugin removes it automatically.
					</p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading">What gets added</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">HTML layer</th>
				<td>
					<ul class="nop-mf2-list">
						<li><code>h-entry</code> — added to <code>&lt;body&gt;</code> on IndieWeb posts</li>
						<li><code>dt-published</code> — added to the <code>&lt;time&gt;</code> element in the post date block</li>
						<li><code>e-content</code> — added to the post content block wrapper</li>
						<li><code>p-checkin h-card</code> — venue details rendered by the checkin-meta block</li>
						<li><code>u-syndication</code> — checkin links in the checkin-meta block</li>
						<li><code>u-url</code> — post permalink in the checkin-meta block</li>
					</ul>
					<p class="description">These allow basic parsers like webmention.io to read your post structure from HTML.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">JSON layer</th>
				<td>
					<p>
						Structured mf2 JSON is generated directly from post meta — no HTML parsing — and served at:
					</p>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo $mf2_url; ?>" target="_blank" rel="noopener"><?php echo $mf2_url; ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $mf2_url ); ?>">Copy</button>
					</div>
					<p class="description">
						Advertised via <code>&lt;link rel="alternate" type="application/mf2+json"&gt;</code> in <code>&lt;head&gt;</code>.
						Services like <a href="https://brid.gy" target="_blank" rel="noopener">Bridgy</a> and
						<a href="https://xray.p3k.io" target="_blank" rel="noopener">XRay</a> that support <code>rel=alternate</code>
						use this for richer, more accurate data.
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ——— Shared rendering helpers ————————————————————————————————————————————

	private function get_formats(): array {
		$theme_formats = get_theme_support( 'post-formats' );
		return is_array( $theme_formats )
			? array_merge( [ 'standard' ], $theme_formats[0] ?? [] )
			: [ 'standard' ];
	}

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

	private function render_inbound_defaults( string $slug, string $name_prefix, array $settings ): void {
		$formats        = $this->get_formats();
		$category_names = array_values( array_map( fn( $c ) => $c->name, get_categories( [ 'hide_empty' => false, 'orderby' => 'name' ] ) ) );
		$tag_names      = array_values( array_map( fn( $t ) => $t->name, get_tags( [ 'hide_empty' => false, 'orderby' => 'name' ] ) ) );
		?>
		<h3 class="nop-section-heading">Inbound Defaults</h3>
		<p class="description" style="margin: 6px 0 16px;">Applied to posts received via <a href="https://brid.gy" target="_blank" rel="noopener">Bridgy</a> from this platform.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nop-<?php echo esc_attr( $slug ); ?>-in-status">Status</label></th>
				<td>
					<select id="nop-<?php echo esc_attr( $slug ); ?>-in-status"
					        name="<?php echo esc_attr( "{$name_prefix}[post_status]" ); ?>">
						<?php foreach ( [ 'publish' => 'Published', 'draft' => 'Draft', 'private' => 'Private' ] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"
							        <?php selected( $settings['post_status'] ?? 'publish', $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php if ( count( $formats ) > 1 ) : ?>
			<tr>
				<th scope="row"><label for="nop-<?php echo esc_attr( $slug ); ?>-in-format">Format</label></th>
				<td>
					<select id="nop-<?php echo esc_attr( $slug ); ?>-in-format"
					        name="<?php echo esc_attr( "{$name_prefix}[post_format]" ); ?>">
						<?php foreach ( $formats as $format ) : ?>
							<option value="<?php echo esc_attr( $format ); ?>"
							        <?php selected( $settings['post_format'] ?? 'status', $format ); ?>>
								<?php echo esc_html( ucfirst( $format ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><label for="nop-<?php echo esc_attr( $slug ); ?>-in-category">Category</label></th>
				<td>
					<?php $this->render_token_field(
						"nop-{$slug}-in-category",
						"{$name_prefix}[post_category]",
						$settings['post_category'] ?? 'Notes',
						$category_names,
						'Add category…',
						'Category name',
						'Created automatically if it doesn\'t exist.'
					); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nop-<?php echo esc_attr( $slug ); ?>-in-tags">Tags</label></th>
				<td>
					<?php $this->render_token_field(
						"nop-{$slug}-in-tags",
						"{$name_prefix}[post_tags]",
						$settings['post_tags'] ?? '',
						$tag_names,
						'Add tags…',
						'Tag name'
					); ?>
				</td>
			</tr>
			<tr>
				<th scope="row">Photos</th>
				<td>
					<label>
						<input type="checkbox"
						       name="<?php echo esc_attr( "{$name_prefix}[sideload_photos]" ); ?>"
						       value="1"
						       <?php checked( $settings['sideload_photos'] ?? true ); ?>>
						Save photos to your media library
					</label>
				</td>
			</tr>
		</table>
		<?php
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
