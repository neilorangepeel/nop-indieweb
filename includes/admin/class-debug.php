<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Admin;

use NOP\IndieWeb\Services\Service_Base;

/**
 * Debug panel at Settings > IndieWeb Debug.
 *
 * Shows the last received Micropub payload and lets you fire a test Swarm
 * checkin through the full service pipeline without OwnYourSwarm being live.
 * A real post is created on each test fire — delete it when done.
 */
class Debug {

	private array $services;

	public function __construct( array $services ) {
		$this->services = $services;
	}

	public function register(): void {
		add_action( 'admin_menu',            [ $this, 'add_page' ] );
		add_action( 'admin_post_nop_indieweb_test_payload', [ $this, 'handle_test_post' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_page(): void {
		add_submenu_page(
			'options-general.php',
			'IndieWeb Debug',
			'IndieWeb Debug',
			'manage_options',
			'nop-indieweb-debug',
			[ $this, 'render_page' ]
		);
	}

	// Fires a fake Swarm payload through the service pipeline and redirects back.
	public function handle_test_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}
		check_admin_referer( 'nop_indieweb_test_payload' );

		$payload = $this->build_test_payload();
		$post_id = null;

		foreach ( $this->services as $service ) {
			if ( $service instanceof Service_Base && $service->can_handle( $payload ) ) {
				$result  = $service->handle( $payload );
				$post_id = is_wp_error( $result ) ? null : $result;
				break;
			}
		}

		wp_safe_redirect( add_query_arg( [
			'page'         => 'nop-indieweb-debug',
			'test_result'  => $post_id ? 'success' : 'error',
			'test_post_id' => $post_id,
		], admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$last_payload = get_transient( 'nop_indieweb_last_payload' );
		$last_post_id = get_transient( 'nop_indieweb_last_post_id' );
		$test_result  = sanitize_key( $_GET['test_result'] ?? '' );
		$test_post_id = absint( $_GET['test_post_id'] ?? 0 );
		$endpoint     = esc_url( \NOP\IndieWeb\nop_indieweb_endpoint_url() );
		?>
		<div class="wrap nop-indieweb-debug">
			<h1>IndieWeb Debug</h1>

			<?php if ( 'success' === $test_result && $test_post_id ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						Test payload created post #<?php echo $test_post_id; ?> —
						<a href="<?php echo esc_url( get_permalink( $test_post_id ) ); ?>">View</a> &nbsp;|&nbsp;
						<a href="<?php echo esc_url( get_edit_post_link( $test_post_id ) ); ?>">Edit</a>
					</p>
				</div>
			<?php elseif ( 'error' === $test_result ) : ?>
				<div class="notice notice-error is-dismissible">
					<p>Test payload failed — check the WordPress error log (enable Debug Mode in <a href="options-general.php?page=nop-indieweb-settings">settings</a> to see details).</p>
				</div>
			<?php endif; ?>

			<h2>Endpoint</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">URL</th>
					<td><code><?php echo $endpoint; ?></code></td>
				</tr>
				<tr>
					<th scope="row">Config check</th>
					<td>
						<code>curl "<?php echo $endpoint; ?>?q=config"</code>
						<p class="description">Run in your terminal — should return a JSON object.</p>
					</td>
				</tr>
			</table>

			<h2>Fire a Test Payload</h2>
			<p>Sends a fake Swarm checkin through the full pipeline. A real post will be created — delete it when done.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'nop_indieweb_test_payload' ); ?>
				<input type="hidden" name="action" value="nop_indieweb_test_payload">
				<?php submit_button( 'Fire Test Swarm Checkin', 'secondary', 'submit', false ); ?>
			</form>

			<h2>cURL Example</h2>
			<p>
				Test the endpoint directly from your terminal. Replace <code>YOUR_TOKEN</code> with a token
				issued from the <a href="options-general.php?page=nop-indieweb-settings#nop-tab-indieauth">IndieAuth tab</a>.
			</p>
			<pre class="nop-payload-dump"><?php echo esc_html( $this->build_curl_example( $endpoint ) ); ?></pre>

			<h2>Last Received Payload</h2>
			<?php if ( $last_payload ) : ?>
				<p class="description">Stored for 24 hours after last request.</p>
				<pre class="nop-payload-dump"><?php echo esc_html( wp_json_encode( $last_payload, JSON_PRETTY_PRINT ) ); ?></pre>
				<?php if ( $last_post_id ) : ?>
					<p>
						Created post #<?php echo $last_post_id; ?> —
						<a href="<?php echo esc_url( get_permalink( $last_post_id ) ); ?>">View</a> &nbsp;|&nbsp;
						<a href="<?php echo esc_url( get_edit_post_link( $last_post_id ) ); ?>">Edit</a>
					</p>
				<?php endif; ?>
			<?php else : ?>
				<p class="description">No payload received yet. Fire the test above or wait for a real OwnYourSwarm checkin.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function build_test_payload(): array {
		return [
			'type'       => [ 'h-entry' ],
			'properties' => [
				'published'   => [ current_time( 'c' ) ],
				'content'     => [ 'Test checkin fired from the NOP IndieWeb debug panel.' ],
				'checkin'     => [
					[
						'type'       => [ 'h-card' ],
						'properties' => [
							'name'      => [ 'The Crown Bar' ],
							'url'       => [ 'https://foursquare.com/v/test-venue-id' ],
							'latitude'  => [ '54.5955' ],
							'longitude' => [ '-5.9321' ],
						],
					],
				],
				'syndication' => [ 'https://www.swarmapp.com/checkin/test123' ],
				'photo'       => [],
			],
		];
	}

	private function build_curl_example( string $endpoint ): string {
		return <<<EOT
curl -X POST "{$endpoint}" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "type": ["h-entry"],
    "properties": {
      "published": ["2026-05-10T12:00:00+01:00"],
      "content": ["Checked in at The Crown Bar"],
      "checkin": [{
        "type": ["h-card"],
        "properties": {
          "name": ["The Crown Bar"],
          "url": ["https://foursquare.com/v/example"],
          "latitude": ["54.5955"],
          "longitude": ["-5.9321"]
        }
      }],
      "syndication": ["https://www.swarmapp.com/checkin/example"]
    }
  }'
EOT;
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_nop-indieweb-debug' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'nop-indieweb-admin', NOP_INDIEWEB_URL . 'assets/css/admin.css', [], NOP_INDIEWEB_VERSION );
	}
}
