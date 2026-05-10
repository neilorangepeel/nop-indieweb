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
	];

	public function register(): void {
		add_action( 'admin_menu',            [ $this, 'add_page' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_init',            [ $this, 'maybe_handle_revoke' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
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
		$clean['debug_mode'] = ! empty( $input['debug_mode'] );

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
						<?php if ( 'swarm' === $slug ) : ?>
							<?php echo $this->swarm_bee_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php endif; ?>
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

	// ——— Swarm bee icon ——————————————————————————————————————————————————————

	private function swarm_bee_svg(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 90 80"'
			. ' class="nop-tab-icon"'
			. ' aria-hidden="true" focusable="false">'
			. '<path d="M37.0856 66.7424C34.8703 61.7752 33.6641 56.3909 33.5287 50.9822C29.9596 56.4891 27.252 62.0327 25.4551 66.1659C25.3689 66.3622 25.2951 66.5707 25.2212 66.7792C24.5689 68.3123 24.0397 69.6246 23.6582 70.6303C23.1782 71.8813 23.8305 73.3408 25.0858 73.8192C26.0951 74.2116 27.4366 74.7022 29.0119 75.2419C29.2088 75.3277 29.4058 75.4136 29.615 75.4749C33.861 76.8976 39.7317 78.5779 46.1562 79.6204C42.3532 75.9655 39.2517 71.5624 37.0856 66.7301Z"/>'
			. '<path d="M45.6517 26.637C33.0981 1.06495 5.2095 1.98481 0.803435 13.4033C-2.5688 22.1726 12.2124 43.0717 46.2302 27.9861C46.1317 27.7776 45.7379 26.8823 45.6517 26.6492Z"/>'
			. '<path d="M51.547 24.8829C44.6056 7.69996 56.7654 -2.1609 63.4483 0.402433C68.4205 2.30347 71.1035 17.1193 51.8916 25.6433C51.8424 25.5207 51.6208 25.0055 51.547 24.8829Z"/>'
			. '<path d="M87.2137 44.3591C84.3091 37.8465 78.9677 33.1736 72.7648 30.9537C72.1371 30.7329 71.4971 30.6226 70.8571 30.6226C67.6326 30.6226 64.5311 33.2963 64.2357 36.6323C63.7434 42.2005 64.8511 47.8668 67.288 53.3369C69.7002 58.7211 73.1094 63.2836 77.5031 66.6319C78.6477 67.5027 80.0385 67.9074 81.4415 67.9074C83.9522 67.9074 86.4629 66.5706 87.4598 64.1912C90.0198 58.0711 90.1429 50.9207 87.2014 44.3468Z"/>'
			. '<path d="M72.6295 72.9845C67.2511 68.888 62.9928 63.3689 59.9651 56.5987C56.9006 49.7305 55.6452 42.7764 56.2483 35.9449C56.4206 34.0439 56.9744 32.2041 57.8606 30.5361C57.036 30.7324 56.7406 30.8182 56.4329 30.9163C54.5868 31.4683 52.8022 32.2287 51.1161 33.1608C50.2915 33.6146 49.4792 34.1052 48.7038 34.6326C44.5808 37.4289 41.0486 42.3226 40.6548 47.4002C40.224 52.8703 41.1224 58.5243 43.5224 63.8963C45.8362 69.0843 49.3069 73.4137 53.4791 76.7129C56.0267 78.7244 60.6174 79.9999 64.3589 79.9999C68.5557 79.9999 72.8756 78.4668 76.4325 76.2714C76.814 76.0384 77.0725 75.8789 77.3309 75.7195C77.4786 75.6214 77.614 75.5355 77.7494 75.4374C75.891 74.9591 74.1433 74.1251 72.6295 72.9845Z"/>'
			. '</svg>';
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
