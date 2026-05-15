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

	private const POST_STATUSES = [
		'publish' => 'Published',
		'draft'   => 'Draft',
		'private' => 'Private',
	];
	private const VALID_STATUS_KEYS = [ 'publish', 'draft', 'private' ];

	private const TAB_GROUPS = [
		''         => [ 'overview'    => 'Overview' ],
		'Networks' => [
			'mastodon'   => 'Mastodon',
			'bluesky'    => 'Bluesky',
			'pixelfed'   => 'Pixelfed',
			'letterboxd' => 'Letterboxd',
			'swarm'      => 'Swarm',
		],
		'Content'  => [
			'publishing' => 'Publishing',
			'reactions'  => 'Reactions',
		],
		'Advanced' => [ 'advanced' => 'Advanced' ],
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

			<h1>IndieWeb</h1>

			<div class="nav-tab-wrapper nop-nav-tabs" role="tablist" aria-label="Settings sections">
				<?php foreach ( self::TAB_GROUPS as $tabs ) : ?>
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
						   <?php if ( ! $tab_enabled ) : ?>title="<?php echo esc_attr( $label ); ?> is not enabled"<?php endif; ?>>
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

				<div id="nop-tab-advanced" class="nop-tab-panel" role="tabpanel" aria-labelledby="nop-tablabel-advanced" tabindex="0" hidden>
					<?php $this->render_tab_advanced(); ?>
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
				'body'  => 'Services default to Draft while you get set up. Switch <strong>Post Status</strong> to Published in the Posts tab, or enable Mastodon or Bluesky to start syndicating.',
				'link'  => [ 'href' => '#nop-tab-post-kinds', 'text' => 'Open Posts tab' ],
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
				<th scope="row">Micropub</th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo $micropub_url; ?>" target="_blank" rel="noopener"><?php echo $micropub_url; ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $micropub_url ); ?>">Copy</button>
					</div>
					<p class="description">Your publishing endpoint — point Micropub clients (Quill, Ulysses, iA Writer) here.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Webmention</th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo $webmention_url; ?>" target="_blank" rel="noopener"><?php echo $webmention_url; ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $webmention_url ); ?>">Copy</button>
					</div>
					<p class="description">Advertised automatically via <code>&lt;link rel="webmention"&gt;</code> — other sites discover it without any setup.</p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading">Networks</h3>
		<div class="nop-network-cards">
			<?php foreach ( $networks as $network ) : ?>
			<div class="nop-network-card <?php echo $network['active'] ? 'nop-network-card--active' : 'nop-network-card--inactive'; ?>"
			     style="--nop-card-accent: <?php echo esc_attr( $network['active'] ? $network['color'] : '#c3c4c7' ); ?>">
				<div class="nop-network-card__header">
					<span class="nop-network-card__dot"></span>
					<strong class="nop-network-card__name"><?php echo esc_html( $network['label'] ); ?></strong>
				</div>
				<p class="nop-network-card__status">
					<?php echo esc_html( $network['active'] ? 'Active' : 'Not configured' ); ?>
				</p>
				<?php if ( $network['active'] && $network['actions'] ) : ?>
				<p class="nop-network-card__actions"><?php echo esc_html( implode( ' · ', $network['actions'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( $network['last_label'] ) : ?>
				<p class="nop-network-card__last"><?php echo esc_html( $network['last_label'] ); ?></p>
				<?php endif; ?>
				<a href="#nop-tab-<?php echo esc_attr( $network['tab'] ); ?>"
				   class="nop-setup-link nop-network-card__link">
					<?php echo $network['active'] ? 'Configure' : 'Set up'; ?> →
				</a>
			</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $approved_wm > 0 || $pending_wm > 0 ) : ?>
		<h3 class="nop-section-heading">Reactions</h3>
		<p class="nop-overview-stat">
			<?php if ( $approved_wm > 0 ) : ?>
				<strong><?php echo esc_html( number_format_i18n( $approved_wm ) ); ?></strong>
				<?php echo esc_html( $approved_wm === 1 ? 'reaction received' : 'reactions received' ); ?>
				<?php if ( $pending_wm > 0 ) : ?>
					— <a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_type=webmention&comment_status=moderated' ) ); ?>">
						<?php echo esc_html( $pending_wm ); ?> pending approval
					</a>
				<?php endif; ?>
			<?php else : ?>
				<?php echo esc_html( $pending_wm ); ?> reaction<?php echo $pending_wm === 1 ? '' : 's'; ?> pending approval —
				<a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_type=webmention&comment_status=moderated' ) ); ?>">review them</a>
			<?php endif; ?>
		</p>
		<?php endif; ?>

		<h3 class="nop-section-heading">Identity</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nop-me-urls">Profile URLs</label></th>
				<td>
					<textarea id="nop-me-urls"
					          name="<?php echo self::OPTION_KEY; ?>[me_urls]"
					          rows="4"
					          class="large-text code"
					          placeholder="https://github.com/yourusername&#10;https://linkedin.com/in/yourusername"><?php echo esc_textarea( \NOP\IndieWeb\nop_indieweb_get_option( 'me_urls', '' ) ); ?></textarea>
					<p class="description">One URL per line. Output as <code>&lt;link rel="me"&gt;</code> — used by IndieAuth and profile verification. Your configured social networks are added automatically.</p>
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

		return [
			'mastodon'   => [
				'label'      => 'Mastodon',
				'color'      => '#6364FF',
				'active'     => $mastodon_ok,
				'actions'    => $mastodon_ok ? [ 'Post out', 'Import' ] : [],
				'last_label' => $mastodon_ok ? $this->human_time_diff( $mastodon['import_last_at'] ?? null, 'Synced' ) : null,
				'tab'        => 'mastodon',
			],
			'bluesky'    => [
				'label'      => 'Bluesky',
				'color'      => '#0085FF',
				'active'     => $bluesky_ok,
				'actions'    => $bluesky_ok ? [ 'Post out', 'Import' ] : [],
				'last_label' => $bluesky_ok ? $this->human_time_diff( $bluesky['import_last_at'] ?? null, 'Synced' ) : null,
				'tab'        => 'bluesky',
			],
			'pixelfed'   => [
				'label'      => 'Pixelfed',
				'color'      => '#1A9C5B',
				'active'     => $pixelfed_ok,
				'actions'    => $pixelfed_ok ? [ 'Post out', 'Import' ] : [],
				'last_label' => $pixelfed_ok ? $this->human_time_diff( $pixelfed['import_last_at'] ?? null, 'Synced' ) : null,
				'tab'        => 'pixelfed',
			],
			'letterboxd' => [
				'label'      => 'Letterboxd',
				'color'      => '#00C030',
				'active'     => $lboxd_ok,
				'actions'    => $lboxd_ok ? [ 'Import' ] : [],
				'last_label' => $lboxd_ok ? $this->human_time_diff( $lboxd['import_last_at'] ?? null, 'Synced' ) : null,
				'tab'        => 'letterboxd',
			],
			'swarm'      => [
				'label'      => 'Swarm',
				'color'      => '#FC8D1D',
				'active'     => $swarm_ok,
				'actions'    => $swarm_ok ? [ 'Receive check-ins' ] : [],
				'last_label' => $swarm_ok ? $this->human_time_diff( $swarm_last_at, 'Last check-in' ) : null,
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
		$diff = $prefix ? "{$prefix} " . human_time_diff( $ts ) . ' ago' : human_time_diff( $ts ) . ' ago';
		return $diff;
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

		<h3 class="nop-section-heading nop-section-heading--first">IndieAuth</h3>
		<p class="description nop-section-intro">These endpoints are advertised automatically in your site's <code>&lt;head&gt;</code> — Micropub clients discover them without any setup.</p>
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
								   data-confirm="<?php echo esc_attr( sprintf( 'Revoke access for %s?', $session['client_name'] ?: $session['client_id'] ) ); ?>">
									Revoke
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3 class="nop-section-heading">Microformats</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Markup</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[mf2_enabled]" value="1"
						       <?php checked( $mf2_enabled ); ?>>
						Add microformats2 markup to IndieWeb posts
					</label>
					<p class="description">
						Injected at render time — nothing is stored in your database.
						Deactivating the plugin removes it automatically.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">JSON endpoint</th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo $mf2_url; ?>" target="_blank" rel="noopener"><?php echo $mf2_url; ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $mf2_url ); ?>">Copy</button>
					</div>
					<p class="description">
						Served at <code>rel="alternate" type="application/mf2+json"</code>. Services like
						<a href="https://brid.gy" target="_blank" rel="noopener">Bridgy</a> and
						<a href="https://xray.p3k.io" target="_blank" rel="noopener">XRay</a> use this for richer data.
					</p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading">Developer</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Debug mode</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[debug_mode]" value="1"
						       <?php checked( $debug_mode ); ?>>
						Enable debug logging
					</label>
					<p class="description">Writes plugin activity to <code>wp-content/debug.log</code> when WP_DEBUG_LOG is enabled.</p>
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
				$last_sync = $this->human_time_diff( $settings['import_last_at'] ?? null, 'Last imported' );
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
		<p class="description nop-section-intro">Applied to posts imported from Letterboxd.</p>
		<?php
		$this->render_defaults_table( [
			'slug'         => 'letterboxd',
			'prefix'       => $prefix,
			'settings'     => $settings,
			'last_label'   => 'Poster',
			'last_field'   => 'sideload_poster',
			'last_aria'    => 'Save film poster to media library',
		] );
	}

	// ——— Tab: Publishing ————————————————————————————————————————————————————

	private function render_tab_publishing(): void {
		$category_names = array_values( array_map( fn( $c ) => $c->name, get_categories( [ 'hide_empty' => false, 'orderby' => 'name' ] ) ) );
		$tag_names      = array_values( array_map( fn( $t ) => $t->name, get_tags( [ 'hide_empty' => false, 'orderby' => 'name' ] ) ) );
		$entries_settings = \NOP\IndieWeb\nop_indieweb_get_option( 'services', [] )['entries'] ?? [];
		$entries_prefix   = self::OPTION_KEY . '[services][entries]';
		?>
		<h3 class="nop-section-heading nop-section-heading--first">Notes</h3>
		<p class="description nop-section-intro">Short posts sent via any Micropub client (Quill, iA Writer, etc.).</p>
		<table class="nop-kinds-table">
			<thead>
				<tr>
					<th scope="col">Enable</th>
					<th scope="col">Status</th>
					<th scope="col">Category</th>
					<th scope="col">Tags</th>
					<th scope="col" class="nop-kinds-table__enable">Photos</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td class="nop-kinds-table__enable">
						<input type="checkbox"
						       name="<?php echo esc_attr( "{$entries_prefix}[enabled]" ); ?>"
						       value="1"
						       aria-label="Enable notes"
						       <?php checked( $entries_settings['enabled'] ?? true ); ?>>
					</td>
					<td>
						<select name="<?php echo esc_attr( "{$entries_prefix}[post_status]" ); ?>">
							<?php foreach ( self::POST_STATUSES as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $entries_settings['post_status'] ?? 'publish', $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<?php $this->render_token_field( 'nop-entries-svc-category', "{$entries_prefix}[post_category]", $entries_settings['post_category'] ?? '', $category_names, 'Add category…', 'Category name' ); ?>
					</td>
					<td>
						<?php $this->render_token_field( 'nop-entries-svc-tags', "{$entries_prefix}[post_tags]", $entries_settings['post_tags'] ?? '', $tag_names, 'Add tags…', 'Tag name' ); ?>
					</td>
					<td class="nop-kinds-table__enable">
						<input type="checkbox" name="<?php echo esc_attr( "{$entries_prefix}[sideload_photos]" ); ?>" value="1"
						       title="Save photos to your media library."
						       aria-label="Save photos: notes"
						       <?php checked( $entries_settings['sideload_photos'] ?? true ); ?>>
					</td>
				</tr>
			</tbody>
		</table>

		<h3 class="nop-section-heading">Interaction posts</h3>
		<p class="description nop-section-intro">Likes, bookmarks, replies, and reposts sent via Micropub. Categories are created automatically if they don't exist.</p>
		<?php
		$kinds = [
			'bookmark' => [ 'label' => 'Bookmark', 'micropub' => 'bookmark-of'        ],
			'reply'    => [ 'label' => 'Reply',     'micropub' => 'in-reply-to'        ],
			'like'     => [ 'label' => 'Like',      'micropub' => 'like-of'            ],
			'repost'   => [ 'label' => 'Repost',    'micropub' => 'repost-of'          ],
			'rsvp'     => [ 'label' => 'RSVP',      'micropub' => 'in-reply-to + rsvp' ],
		];
		?>
		<table class="nop-kinds-table">
			<thead>
				<tr>
					<th scope="col">Kind</th>
					<th scope="col">Enable</th>
					<th scope="col">Status</th>
					<th scope="col">Category</th>
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
						       aria-label="<?php echo esc_attr( sprintf( 'Accept %s posts via Micropub', strtolower( $kind['label'] ) ) ); ?>"
						       <?php checked( $settings['enabled'] ?? true ); ?>>
					</td>
					<td>
						<select name="<?php echo esc_attr( "{$prefix}[post_status]" ); ?>">
							<?php foreach ( self::POST_STATUSES as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['post_status'] ?? 'publish', $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<?php $this->render_token_field( "nop-{$slug}-category", "{$prefix}[post_category]", $settings['post_category'] ?? '', $category_names, 'Add category…', 'Category name' ); ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3 class="nop-section-heading">Twitter Archive</h3>
		<p class="description nop-section-intro">Posts imported from a static <a href="https://github.com/timhutton/twitter-archive-parser" target="_blank" rel="noopener">Twitter archive</a> show an "Archived Tweet" label that can link out to the source.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="twitter-archive-url">Archive URL</label></th>
				<td>
					<input type="url" id="twitter-archive-url"
					       name="<?php echo self::OPTION_KEY; ?>[twitter_archive_url]"
					       value="<?php echo esc_attr( \NOP\IndieWeb\nop_indieweb_get_option( 'twitter_archive_url', '' ) ); ?>"
					       class="regular-text"
					       placeholder="https://yoursite.com/twitter-archive/">
					<p class="description">Optional link displayed on archived tweet posts. Leave blank to show the label without a link.</p>
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
		<p>Reactions are likes, reposts, and replies sent to your posts from other sites via the <a href="https://webmention.net" target="_blank" rel="noopener">Webmention</a> standard. <a href="https://brid.gy" target="_blank" rel="noopener">Bridgy</a> can backfeed reactions from Mastodon and Bluesky automatically.</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Accept reactions</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[receive_enabled]"; ?>" value="1"
						       <?php checked( $receive_enabled ); ?>>
						Accept incoming webmentions from other sites
					</label>
					<p class="description">Uncheck to stop accepting new reactions. Existing ones are kept.</p>
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
					<p class="description">Held reactions appear in <a href="<?php echo esc_url( admin_url( 'edit-comments.php?comment_type=webmention' ) ); ?>">Comments → Webmentions</a> awaiting your approval.</p>
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
		<p>Swarm by Foursquare lets you check in to places. Connect <a href="https://ownyourswarm.p3k.io" target="_blank" rel="noopener">OwnYourSwarm</a> and every check-in automatically becomes a post on your site.</p>

		<h3 class="nop-section-heading">Enable</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Accept check-ins</th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo "{$prefix}[enabled]"; ?>" value="1"
						       <?php checked( $settings['enabled'] ?? false ); ?>>
						Accept check-ins from OwnYourSwarm
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row">Your Micropub endpoint</th>
				<td>
					<div class="nop-url-copy-row">
						<code class="nop-url-display">
							<a href="<?php echo $endpoint; ?>" target="_blank" rel="noopener"><?php echo $endpoint; ?></a>
						</code>
						<button type="button" class="button button-secondary nop-copy-btn"
						        data-copy="<?php echo esc_attr( $endpoint ); ?>">Copy</button>
					</div>
					<p class="description">Paste this into OwnYourSwarm as your Micropub endpoint. It will ask you to sign in to your site to authorize — that's normal.</p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading">Map</h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nop-geoapify-key">Geoapify API key</label></th>
				<td>
					<?php $key = \NOP\IndieWeb\nop_indieweb_get_option( 'maps.geoapify_api_key', '' ); ?>
					<input type="text" id="nop-geoapify-key"
					       name="<?php echo self::OPTION_KEY; ?>[maps][geoapify_api_key]"
					       value="<?php echo esc_attr( $key ); ?>"
					       class="regular-text code" autocomplete="off"
					       placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
					<p class="description">
						Used to generate static map images for check-in posts.
						Get a free key (3 000 req/day) at
						<a href="https://www.geoapify.com/" target="_blank" rel="noopener">geoapify.com</a>.
						Leave blank to use the built-in tile fallback instead.
					</p>
				</td>
			</tr>
		</table>

		<h3 class="nop-section-heading">Inbound Defaults</h3>
		<p class="description nop-section-intro">Applied to posts created from Swarm check-ins.</p>
		<?php
		$this->render_defaults_table( [
			'slug'        => 'swarm',
			'prefix'      => $prefix,
			'settings'    => $settings,
			'tag_default' => 'Swarm',
			'last_aria'   => 'Save Swarm photos to media library',
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
		$last_label  = $args['last_label']  ?? 'Photos';
		$last_field  = $args['last_field']  ?? 'sideload_photos';
		$last_def    = $args['last_default'] ?? true;
		$last_aria   = $args['last_aria']   ?? 'Save photos to media library';
		$tag_default = $args['tag_default'] ?? '';
		?>
		<table class="nop-kinds-table">
			<thead>
				<tr>
					<th scope="col" class="nop-kinds-table__status">Status</th>
					<th scope="col">Category</th>
					<th scope="col">Tags</th>
					<th scope="col" class="nop-kinds-table__enable"><?php echo esc_html( $last_label ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td class="nop-kinds-table__status">
						<select name="<?php echo esc_attr( "{$prefix}[post_status]" ); ?>">
							<?php foreach ( self::POST_STATUSES as $value => $label ) : ?>
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
							'Add category…',
							'Category name'
						); ?>
					</td>
					<td>
						<?php $this->render_token_field(
							"nop-{$slug}-tags",
							"{$prefix}[post_tags]",
							$settings['post_tags'] ?? $tag_default,
							$tag_names,
							'Add tags…',
							'Tag name'
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
		<h3 class="nop-section-heading">Inbound Defaults</h3>
		<p class="description nop-section-intro">Applied to posts received via <a href="https://brid.gy" target="_blank" rel="noopener">Bridgy</a> from this platform.</p>
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
