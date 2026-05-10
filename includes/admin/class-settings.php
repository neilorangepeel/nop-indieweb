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

	private const TABS = [
		'general'   => 'General',
		'indieauth' => 'IndieAuth',
		'swarm'     => 'Swarm',
		'mastodon'  => 'Mastodon',
		'bluesky'   => 'Bluesky',
		'semantic'  => 'Microformats',
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

		// — Mastodon ——————————————————————————————————————————————————————————————
		$clean['syndicators']['mastodon']['enabled']      = ! empty( $input['syndicators']['mastodon']['enabled'] );
		$clean['syndicators']['mastodon']['instance']     = esc_url_raw( $input['syndicators']['mastodon']['instance'] ?? '' );
		$clean['syndicators']['mastodon']['access_token'] = sanitize_text_field( $input['syndicators']['mastodon']['access_token'] ?? '' );

		// — Bluesky ———————————————————————————————————————————————————————————————
		$clean['syndicators']['bluesky']['enabled']      = ! empty( $input['syndicators']['bluesky']['enabled'] );
		$clean['syndicators']['bluesky']['handle']       = sanitize_text_field( $input['syndicators']['bluesky']['handle'] ?? '' );
		$clean['syndicators']['bluesky']['app_password'] = sanitize_text_field( $input['syndicators']['bluesky']['app_password'] ?? '' );

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
				<?php foreach ( self::TABS as $slug => $label ) : ?>
					<?php
					$tab_enabled = true;
					if ( 'swarm' === $slug ) {
						$tab_enabled = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['swarm']['enabled'] ?? true;
					}
					?>
					<a href="#nop-tab-<?php echo esc_attr( $slug ); ?>"
					   class="nav-tab<?php echo ! $tab_enabled ? ' nop-tab--inactive' : ''; ?>"
					   data-tab="<?php echo esc_attr( $slug ); ?>"
					   <?php if ( ! $tab_enabled ) : ?>title="<?php echo esc_attr( ucfirst( $slug ) ); ?> is disabled"<?php endif; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
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

				<div id="nop-tab-swarm" class="nop-tab-panel" hidden>
					<?php $this->render_tab_swarm(); ?>
				</div>

				<div id="nop-tab-mastodon" class="nop-tab-panel" hidden>
					<?php $this->render_tab_mastodon(); ?>
				</div>

				<div id="nop-tab-bluesky" class="nop-tab-panel" hidden>
					<?php $this->render_tab_bluesky(); ?>
				</div>

				<div id="nop-tab-semantic" class="nop-tab-panel" hidden>
					<?php $this->render_tab_semantic(); ?>
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
		$swarm   = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['swarm'] ?? [];
		$is_live = ( $swarm['post_status'] ?? 'draft' ) === 'publish';

		$has_checkin = ! empty( get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [ [ 'key' => 'nop_indieweb_service', 'value' => 'swarm' ] ],
		] ) );

		if ( $has_checkin && $is_live ) {
			return;
		}

		$micropub_url = esc_url( \NOP\IndieWeb\nop_indieweb_endpoint_url() );
		$debug_url    = esc_url( admin_url( 'options-general.php?page=nop-indieweb-debug' ) );

		$steps = [
			[
				'done'  => $has_checkin,
				'label' => 'Connect OwnYourSwarm',
				'body'  => 'Visit <a href="https://ownyourswarm.p3k.io" target="_blank" rel="noopener">ownyourswarm.p3k.io</a>, enter your site URL — it will discover your Micropub endpoint automatically via IndieAuth and walk you through authorising. Your endpoint is <code>' . esc_html( $micropub_url ) . '</code>.',
				'link'  => [ 'href' => $debug_url, 'text' => 'Test with the Debug panel' ],
			],
			[
				'done'  => $is_live,
				'label' => 'Go live',
				'body'  => 'Once you\'re happy with the setup, change <strong>Post Status</strong> from Draft to <strong>Published</strong> in the Swarm tab.',
				'link'  => [ 'href' => '#nop-tab-swarm', 'text' => 'Open Swarm tab' ],
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
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['swarm'] ?? [];
		$prefix   = self::OPTION_KEY . '[services][swarm]';

		$theme_formats = get_theme_support( 'post-formats' );
		$formats       = is_array( $theme_formats )
			? array_merge( [ 'standard' ], $theme_formats[0] ?? [] )
			: [ 'standard' ];
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

		<h3 class="nop-section-heading">Post Defaults</h3>
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
					<?php
					$category_value = $settings['post_category'] ?? 'Checkin';
					$all_categories = get_categories( [ 'hide_empty' => false, 'orderby' => 'name' ] );
					$category_names = array_values( array_map( fn( $c ) => $c->name, $all_categories ) );
					?>
					<div class="nop-token-field"
					     data-input-id="nop-swarm-category-input"
					     data-suggestions="<?php echo esc_attr( wp_json_encode( $category_names ) ); ?>">
						<div class="nop-token-field__tokens" aria-live="polite"></div>
						<input type="text" id="nop-swarm-category-input"
						       class="nop-token-field__input"
						       placeholder="Add category…"
						       autocomplete="off"
						       aria-label="Category name">
						<ul class="nop-token-field__suggestions" role="listbox" hidden></ul>
						<input type="hidden"
						       name="<?php echo "{$prefix}[post_category]"; ?>"
						       value="<?php echo esc_attr( $category_value ); ?>">
					</div>
					<p class="description">Created automatically if it doesn't exist.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="nop-swarm-tags-input">Tags</label>
				</th>
				<td>
					<?php
					$tags_value = $settings['post_tags'] ?? 'Swarm';
					$all_tags   = get_tags( [ 'hide_empty' => false, 'orderby' => 'name' ] );
					$tag_names  = array_values( array_map( fn( $t ) => $t->name, $all_tags ) );
					?>
					<div class="nop-token-field"
					     data-input-id="nop-swarm-tags-input"
					     data-suggestions="<?php echo esc_attr( wp_json_encode( $tag_names ) ); ?>">
						<div class="nop-token-field__tokens" aria-live="polite"></div>
						<input type="text" id="nop-swarm-tags-input"
						       class="nop-token-field__input"
						       placeholder="Add tags…"
						       autocomplete="off"
						       aria-label="Tag name">
						<ul class="nop-token-field__suggestions" role="listbox" hidden></ul>
						<input type="hidden"
						       name="<?php echo "{$prefix}[post_tags]"; ?>"
						       value="<?php echo esc_attr( $tags_value ); ?>">
					</div>
					<p class="description">New tags are created automatically.</p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading">Media</h3>
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

	// ——— Tab: Semantic Web ——————————————————————————————————————————————————

	private function render_tab_mastodon(): void {
		$prefix   = self::OPTION_KEY . '[syndicators][mastodon]';
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['mastodon'] ?? [];
		?>
		<h2>Mastodon</h2>
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
					<p class="description">From your Mastodon instance: Preferences → Development → New Application. Needs <code>write:statuses</code> scope.</p>
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
		<?php
	}

	private function render_tab_bluesky(): void {
		$prefix   = self::OPTION_KEY . '[syndicators][bluesky]';
		$settings = \NOP\IndieWeb\nop_indieweb_get_option( 'syndicators', [] )['bluesky'] ?? [];
		?>
		<h2>Bluesky</h2>
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

		<h3 class="nop-section-heading">Microformats2</h3>
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

	// ——— Assets ——————————————————————————————————————————————————————————————

	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'nop-indieweb-admin', NOP_INDIEWEB_URL . 'assets/css/admin.css', [], NOP_INDIEWEB_VERSION );
		wp_enqueue_script( 'nop-indieweb-admin', NOP_INDIEWEB_URL . 'assets/js/admin.js', [], NOP_INDIEWEB_VERSION, true );
	}
}
