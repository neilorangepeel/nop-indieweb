<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Syndication_Manager {

	private const CRON_HOOK = 'nop_indieweb_syndicate_target';

	private const MAX_ATTEMPTS = 4;

	/** Per-post journal of each platform's delivery state. */
	public const STATUS_META = 'nop_indieweb_syndication_status';

	/** Cheap indexed flag: present (=1) while any target on this post is failed. */
	public const FAILED_FLAG_META = 'nop_indieweb_syndication_failed';

	/** Short-lived cache of failure_summary(); busted whenever a status changes. */
	private const SUMMARY_TRANSIENT = 'nop_indieweb_synd_failure_summary';

	/** Seconds to wait before each retry, keyed by the attempt number being scheduled. */
	private const RETRY_DELAYS = [
		2 => 5 * MINUTE_IN_SECONDS,
		3 => 30 * MINUTE_IN_SECONDS,
		4 => 2 * HOUR_IN_SECONDS,
	];

	/** @var Syndicator_Base[] */
	private array $syndicators;

	public function __construct() {
		$this->syndicators = apply_filters( 'nop_indieweb_register_syndicators', [
			new Syndicator_Mastodon(),
			new Syndicator_Bluesky(),
			new Syndicator_Pixelfed(),
			new Syndicator_Tumblr(),
		] );
	}

	public function register(): void {
		// Editor-created posts: fires after all meta is committed.
		add_action( 'wp_after_insert_post', [ $this, 'maybe_syndicate_editor_post' ], 10, 4 );

		// Micropub-created posts: fired explicitly by the endpoint after all meta is set.
		add_action( 'nop_indieweb_post_created', [ $this, 'syndicate' ], 10, 1 );

		// Scheduled (future→publish, via WP cron) and draft→publish posts — the create-time
		// triggers above only see the initial insert, so they miss the later publish. The
		// publish-only + idempotency guards in syndicate() keep this from double-firing.
		add_action( 'transition_post_status', [ $this, 'maybe_syndicate_on_publish' ], 10, 3 );

		// Per-platform async worker — one cron event per (post, platform, attempt).
		add_action( self::CRON_HOOK, [ $this, 'run_target' ], 10, 3 );

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	public function maybe_syndicate_editor_post( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Only on first publish — not on updates to already-published posts.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( $post_before && 'publish' === $post_before->post_status ) {
			return;
		}

		// Skip posts created by micropub — nop_indieweb_post_created handles those.
		if ( get_post_meta( $post_id, 'nop_indieweb_service', true ) ) {
			return;
		}

		$this->syndicate( $post_id );
	}

	/**
	 * A post becoming published from a non-published state — the scheduled
	 * (future→publish, fired by WP cron) and draft/pending→publish cases the create-time
	 * triggers can't see. An immediate publish enters as new→publish (old not in the list)
	 * and is left to nop_indieweb_post_created / wp_after_insert_post, so this never doubles
	 * it; syndicate()'s idempotency guard covers any overlap regardless.
	 */
	public function maybe_syndicate_on_publish( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type ) {
			return;
		}
		if ( 'publish' === $new_status && in_array( $old_status, [ 'future', 'draft', 'pending' ], true ) ) {
			$this->syndicate( $post->ID );
		}
	}

	/**
	 * Queues async syndication to every resolved target. Each platform gets its
	 * own cron event so one platform's failure or retry never blocks another —
	 * and the publish request never waits on remote APIs.
	 */
	public function syndicate( int $post_id ): void {
		// Only published posts POSSE — a draft or a scheduled (future) post must not
		// syndicate until it actually publishes; the transition hook re-fires it then.
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		// Idempotent: once syndication has kicked off, this post carries a status journal,
		// so the several publish triggers (nop_indieweb_post_created / wp_after_insert_post /
		// transition_post_status) can never double-queue — the first to fire wins. Manual
		// retry goes through queue() directly, so it's unaffected.
		if ( get_post_meta( $post_id, self::STATUS_META, true ) ) {
			return;
		}

		// Hard skip for posts flagged as local-only (e.g. private Swarm checkins).
		if ( get_post_meta( $post_id, 'nop_indieweb_skip_syndication', true ) ) {
			return;
		}

		$targets = $this->resolve_targets( $post_id );

		// Don't POSSE backdated posts: a historical import/backfill (e.g. re-syndicated
		// old check-ins via micropub) must not flood networks as if it were happening now.
		// A live post publishes at ~now; a backfill carries an old published date. The
		// /post composer doesn't send `published`, so offline-queue replays land at ~now
		// and are unaffected. Filterable for the rare "syndicate this old post on purpose".
		//
		// Journalled rather than returning silently: a skip that leaves no trace is
		// indistinguishable from a dropped cron job or a post that never triggered at
		// all. 'skipped' is not 'failed', so it never sets the failure flag or reddens
		// the health summary — it just makes the decision visible on the post.
		$published = (int) get_post_time( 'U', true, $post_id );
		$max_age   = (int) apply_filters( 'nop_indieweb_syndicate_max_age', DAY_IN_SECONDS, $post_id );
		if ( $published && ( time() - $published ) > $max_age ) {
			foreach ( $this->syndicators as $syndicator ) {
				if ( ! in_array( $syndicator->slug(), $targets, true ) ) {
					continue;
				}
				$this->update_status( $post_id, $syndicator->slug(), [
					'state'   => 'skipped',
					'reason'  => 'backdated',
					'error'   => sprintf(
						/* translators: %s: human-readable age, e.g. "3 days" */
						__( 'Skipped — published %s ago, past the backdate cutoff.', 'nop-indieweb' ),
						human_time_diff( $published )
					),
					'updated' => time(),
				] );
			}
			return;
		}

		foreach ( $this->syndicators as $syndicator ) {
			if ( in_array( $syndicator->slug(), $targets, true ) ) {
				$this->queue( $post_id, $syndicator->slug(), 1 );
			}
		}
	}

	/**
	 * Runs one platform's syndication attempt and handles retry/failure. Reached
	 * from cron and, while the author is watching the delivery poll, directly —
	 * so it takes a lock first. The syndicator's own dedup only kicks in once a
	 * URL is stored, which is too late to stop two concurrent runs both posting.
	 */
	public function run_target( int $post_id, string $slug, int $attempt ): void {
		if ( ! $this->claim_target( $post_id, $slug ) ) {
			return;
		}

		try {
			$this->run_target_unguarded( $post_id, $slug, $attempt );
		} finally {
			$this->release_target( $post_id, $slug );
		}
	}

	/**
	 * The closest thing WP offers to an atomic test-and-set without assuming a
	 * persistent object cache: add_option() fails when the row already exists.
	 * A lock older than the longest plausible run (a fatal mid-syndication)
	 * is taken over rather than blocking the platform forever.
	 */
	private function claim_target( int $post_id, string $slug ): bool {
		$key = 'nop_syn_run_' . $post_id . '_' . $slug;

		if ( add_option( $key, (string) time(), '', false ) ) {
			return true;
		}

		if ( ( time() - (int) get_option( $key, 0 ) ) < 10 * MINUTE_IN_SECONDS ) {
			return false;
		}

		update_option( $key, (string) time(), false );
		return true;
	}

	private function release_target( int $post_id, string $slug ): void {
		delete_option( 'nop_syn_run_' . $post_id . '_' . $slug );
	}

	private function run_target_unguarded( int $post_id, string $slug, int $attempt ): void {
		$syndicator = $this->get( $slug );
		if ( ! $syndicator ) {
			$this->update_status( $post_id, $slug, null );
			return;
		}

		$result = $syndicator->syndicate( $post_id );

		if ( is_wp_error( $result ) ) {
			// A permanent client error (4xx, bar 408/429) won't change on retry —
			// fail it now instead of burning the remaining attempts. Errors with
			// no status attached keep the old retry behaviour.
			$status    = (int) ( ( (array) $result->get_error_data() )['status'] ?? 0 );
			$permanent = $status >= 400 && $status < 500 && ! in_array( $status, [ 408, 429 ], true );

			if ( ! $permanent && $attempt < self::MAX_ATTEMPTS ) {
				$next = $attempt + 1;
				$this->queue( $post_id, $slug, $next, self::RETRY_DELAYS[ $next ], $result->get_error_message() );
			} else {
				$this->update_status( $post_id, $slug, [
					'state'    => 'failed',
					'error'    => $result->get_error_message(),
					'attempts' => $attempt,
					'updated'  => time(),
				] );
			}
			return;
		}

		// Empty string = platform doesn't apply to this post — leave no trace.
		$this->update_status( $post_id, $slug, '' === $result ? null : [
			'state'    => 'sent',
			'url'      => $result,
			'attempts' => $attempt,
			'updated'  => time(),
		] );
	}

	/**
	 * Runs this post's already-due syndication events here and now, instead of
	 * waiting for cron to be spawned.
	 *
	 * WP-cron only runs when a request spawns it, and it refuses to spawn twice
	 * inside WP_CRON_LOCK_TIMEOUT (60s). The publish request itself takes that
	 * lock *before* these events exist, so delivery reliably sits a minute or
	 * two behind publish. The author watching the success screen is already
	 * making authenticated requests every second or so — running the work there
	 * skips the spawn dance entirely and lands delivery in a poll or two.
	 *
	 * Bounded on purpose: it stops starting new platforms once the budget is
	 * spent (a dead network must not hang the poll), and it leaves posts
	 * carrying video to cron, where a single-threaded ffmpeg re-encode can take
	 * as long as it needs. Whatever isn't run here stays scheduled.
	 *
	 * @return int How many platforms were run.
	 */
	public function run_due_targets( int $post_id, int $budget_seconds = 8 ): int {
		if ( ! apply_filters( 'nop_indieweb_inline_syndication', true, $post_id ) ) {
			return 0;
		}

		// A transcode belongs on cron, not on the end of somebody's poll.
		if ( $this->has_video( $post_id ) ) {
			return 0;
		}

		$started = time();
		$ran     = 0;

		foreach ( $this->due_events( $post_id ) as $event ) {
			if ( ( time() - $started ) >= $budget_seconds ) {
				break;
			}

			// Claim it by unscheduling first: whoever removes the event owns the
			// run, and run_target()'s lock catches anything that slips through.
			wp_unschedule_event( $event['timestamp'], self::CRON_HOOK, $event['args'] );

			$this->run_target(
				(int) $event['args'][0],
				(string) $event['args'][1],
				(int) $event['args'][2]
			);
			++$ran;
		}

		return $ran;
	}

	/**
	 * This post's scheduled syndication events that are already due.
	 *
	 * @return array<int,array{timestamp:int,args:array<int,mixed>}>
	 */
	private function due_events( int $post_id ): array {
		$cron = _get_cron_array();
		if ( ! $cron ) {
			return [];
		}

		$now = time();
		$out = [];
		foreach ( $cron as $timestamp => $hooks ) {
			if ( (int) $timestamp > $now || ! isset( $hooks[ self::CRON_HOOK ] ) ) {
				continue;
			}
			foreach ( (array) $hooks[ self::CRON_HOOK ] as $event ) {
				$args = isset( $event['args'] ) ? array_values( (array) $event['args'] ) : [];
				if ( 3 !== count( $args ) || (int) $args[0] !== $post_id ) {
					continue;
				}
				$out[] = [
					'timestamp' => (int) $timestamp,
					'args'      => $args,
				];
			}
		}

		return $out;
	}

	/** Whether this post carries a clip, which makes syndication slow enough to leave on cron. */
	private function has_video( int $post_id ): bool {
		$urls = get_post_meta( $post_id, 'nop_indieweb_videos', true );
		if ( is_array( $urls ) && $urls ) {
			return true;
		}

		$post = get_post( $post_id );
		return $post instanceof \WP_Post
			&& null !== \NOP\IndieWeb\nop_indieweb_block_video( (string) $post->post_content );
	}

	private function queue( int $post_id, string $slug, int $attempt, int $delay = 0, string $last_error = '' ): void {
		$this->update_status( $post_id, $slug, [
			'state'    => 'pending',
			'error'    => $last_error,
			'attempts' => $attempt - 1,
			'updated'  => time(),
		] );
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK, [ $post_id, $slug, $attempt ] );
	}

	/** Writes one platform's entry in the status journal meta; null removes it. */
	private function update_status( int $post_id, string $slug, ?array $entry ): void {
		$status = get_post_meta( $post_id, 'nop_indieweb_syndication_status', true );
		$status = is_array( $status ) ? $status : [];

		if ( null === $entry ) {
			unset( $status[ $slug ] );
		} else {
			$status[ $slug ] = $entry;
		}

		if ( $status ) {
			update_post_meta( $post_id, self::STATUS_META, $status );
		} else {
			delete_post_meta( $post_id, self::STATUS_META );
		}

		$this->update_failure_flag( $post_id, $status );
	}

	/**
	 * Maintains the indexed "this post has a failed target" flag so the admin
	 * notice + Networks health can query failures cheaply (no serialized scan),
	 * and busts the cached summary. Cleared the moment the last failure resolves.
	 *
	 * @param array<string,mixed> $status The post's full status journal.
	 */
	private function update_failure_flag( int $post_id, array $status ): void {
		$has_failure = false;
		foreach ( $status as $entry ) {
			if ( is_array( $entry ) && 'failed' === ( $entry['state'] ?? '' ) ) {
				$has_failure = true;
				break;
			}
		}

		if ( $has_failure ) {
			update_post_meta( $post_id, self::FAILED_FLAG_META, 1 );
		} else {
			delete_post_meta( $post_id, self::FAILED_FLAG_META );
		}

		$this->flush_summary_cache();
	}

	/** Discards the cached failure_summary() so the next read recomputes. */
	public function flush_summary_cache(): void {
		delete_transient( self::SUMMARY_TRANSIENT );
	}

	/**
	 * Aggregate of currently-failing syndications across all posts — feeds the
	 * admin notice and the Networks-tab health view so a dead token is visible
	 * without opening individual posts. Cheap (indexed flag query) + cached.
	 *
	 * @return array{total_failed_posts:int, networks:array<int,array<string,mixed>>, failed_posts:array<int,array<string,mixed>>}
	 */
	public function failure_summary(): array {
		$cached = get_transient( self::SUMMARY_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Per-network skeleton — configured/enabled comes from the syndicators.
		$networks = [];
		foreach ( $this->syndicators as $s ) {
			$networks[ $s->slug() ] = [
				'slug'         => $s->slug(),
				'label'        => $s->label(),
				'enabled'      => $s->enabled(),
				'state'        => $s->enabled() ? 'ok' : 'off',
				'failed_count' => 0,
				'last_error'   => null,
			];
		}

		$failed_posts = [];

		$ids = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'fields'         => 'ids',
			'meta_key'       => self::FAILED_FLAG_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- indexed flag, bounded + cached.
			'meta_value'     => '1',                    // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );

		foreach ( $ids as $pid ) {
			$journal = get_post_meta( (int) $pid, self::STATUS_META, true );
			if ( ! is_array( $journal ) ) {
				continue;
			}

			$targets = [];
			foreach ( $journal as $slug => $entry ) {
				if ( ! is_array( $entry ) || 'failed' !== ( $entry['state'] ?? '' ) ) {
					continue;
				}
				$error     = (string) ( $entry['error'] ?? '' );
				$targets[] = [
					'slug'     => (string) $slug,
					'error'    => $error,
					'attempts' => (int) ( $entry['attempts'] ?? 0 ),
					'updated'  => (int) ( $entry['updated'] ?? 0 ),
				];
				if ( isset( $networks[ $slug ] ) ) {
					$networks[ $slug ]['state'] = 'failing';
					$networks[ $slug ]['failed_count']++;
					$networks[ $slug ]['last_error'] = $error;
				}
			}

			if ( $targets ) {
				$failed_posts[] = [
					'post_id'  => (int) $pid,
					'title'    => get_the_title( (int) $pid ),
					'edit_url' => get_edit_post_link( (int) $pid, 'raw' ),
					'targets'  => $targets,
				];
			}
		}

		$summary = [
			'total_failed_posts' => count( $failed_posts ),
			'networks'           => array_values( $networks ),
			'failed_posts'       => $failed_posts,
		];

		set_transient( self::SUMMARY_TRANSIENT, $summary, 5 * MINUTE_IN_SECONDS );
		return $summary;
	}

	public function register_rest_routes(): void {
		register_rest_route( 'nop-indieweb/v1', '/syndication/retry', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_retry' ],
			'permission_callback' => fn( \WP_REST_Request $request ) => current_user_can( 'edit_post', (int) $request['post_id'] ),
			'args'                => [
				'post_id' => [ 'type' => 'integer', 'required' => true ],
				'target'  => [ 'type' => 'string', 'required' => true ],
			],
		] );

		// Editor-side preview: how the post will read on each target network.
		register_rest_route( 'nop-indieweb/v1', '/syndication/preview', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => fn( \WP_REST_Request $r ) => new \WP_REST_Response( $this->editor_previews( (int) $r['post_id'] ), 200 ),
			'permission_callback' => fn( \WP_REST_Request $r ) => current_user_can( 'edit_post', (int) $r['post_id'] ),
			'args'                => [ 'post_id' => [ 'type' => 'integer', 'required' => true ] ],
		] );

		// Per-post delivery receipts for the /post success view — the status
		// journal as-is, so the client can show pending → sent/failed live.
		// The poll also *drives* delivery: see handle_status().
		register_rest_route( 'nop-indieweb/v1', '/syndication/status', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_status' ],
			'permission_callback' => fn( \WP_REST_Request $r ) => current_user_can( 'edit_post', (int) $r['post_id'] ),
			'args'                => [ 'post_id' => [ 'type' => 'integer', 'required' => true ] ],
		] );

		// The /post composer's Sent view: recent posts with their delivery journal,
		// so status outlives the success screen the post was made on.
		register_rest_route( 'nop-indieweb/v1', '/syndication/sent', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => fn() => new \WP_REST_Response( $this->recent_deliveries(), 200 ),
			'permission_callback' => fn() => current_user_can( 'edit_posts' ),
		] );

		// Read-only aggregate of failing syndications for the Networks-tab health view.
		register_rest_route( 'nop-indieweb/v1', '/syndication/health', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => fn() => new \WP_REST_Response( $this->failure_summary(), 200 ),
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
		] );
	}

	/**
	 * The delivery poll, which does double duty: it runs whatever is already due
	 * for this post before reporting, so watching the success screen is what
	 * makes delivery happen rather than something that waits on cron.
	 */
	public function handle_status( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request['post_id'];
		$this->run_due_targets( $post_id );
		return new \WP_REST_Response( $this->delivery_status( $post_id ), 200 );
	}

	/** @return array<int,array{slug:string,label:string,state:string,url:string,error:string}> */
	private function delivery_status( int $post_id ): array {
		$journal = get_post_meta( $post_id, self::STATUS_META, true );
		$out     = [];
		foreach ( is_array( $journal ) ? $journal : [] as $slug => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$syndicator = $this->get( (string) $slug );
			$out[]      = [
				'slug'  => (string) $slug,
				'label' => $syndicator ? $syndicator->label() : (string) $slug,
				'state' => (string) ( $entry['state'] ?? '' ),
				'url'   => (string) ( $entry['url'] ?? '' ),
				'error' => (string) ( $entry['error'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Recent posts that carry a delivery journal, newest first — the Sent view's
	 * list. Bounded and indexed on the journal's own meta key, so it costs one
	 * meta read per row and never scans the whole archive.
	 *
	 * The route's edit_posts check only says "an author"; syndication URLs and
	 * error strings are per-post, so each row is gated on edit_post as well —
	 * a contributor never reads someone else's delivery.
	 *
	 * @return array<int,array{id:int,url:string,title:string,kind:string,published_gmt:string,targets:array<int,array<string,string>>}>
	 */
	private function recent_deliveries( int $limit = 20 ): array {
		$ids = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_key'       => self::STATUS_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- indexed key, bounded by $limit.
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );

		$out = [];
		foreach ( $ids as $pid ) {
			$pid  = (int) $pid;
			$post = get_post( $pid );
			if ( ! $post || ! current_user_can( 'edit_post', $pid ) ) {
				continue;
			}

			$kind = (string) get_post_meta( $pid, 'nop_indieweb_post_kind', true );
			if ( '' === $kind ) {
				$terms = wp_get_object_terms( $pid, \NOP\IndieWeb\Kind\Kind_Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
				$kind  = ( ! is_wp_error( $terms ) && $terms ) ? (string) $terms[0] : '';
			}

			// A note's title is generated from its own opening words, so pairing the
			// two would stutter. The body is what was actually written; the title
			// only stands in where there is no body (check-ins, likes, photos).
			$label = trim( wp_strip_all_tags( $post->post_content ) );
			if ( '' === $label ) {
				// get_the_title() texturizes, and the client escapes what it renders,
				// so decode here or a curly apostrophe reaches the list as "&#8217;".
				$label = html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
			}

			$out[] = [
				'id'            => $pid,
				'url'           => (string) get_permalink( $post ),
				'title'         => wp_trim_words( $label, 14, '…' ),
				'kind'          => $kind,
				'published_gmt' => (string) $post->post_date_gmt,
				'targets'       => $this->delivery_status( $pid ),
			];
		}

		return $out;
	}

	public function handle_retry( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request['post_id'];
		$slug    = sanitize_key( (string) $request['target'] );

		if ( ! $this->get( $slug ) ) {
			return new \WP_Error( 'nop_unknown_target', __( 'Unknown syndication target.', 'nop-indieweb' ), [ 'status' => 404 ] );
		}

		if ( 'publish' !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'nop_not_published', __( 'Only published posts can be syndicated.', 'nop-indieweb' ), [ 'status' => 400 ] );
		}

		$this->queue( $post_id, $slug, 1 );

		return new \WP_REST_Response( [ 'queued' => true ], 202 );
	}

	/**
	 * Returns the list of platform slugs to syndicate to.
	 * Uses the explicit editor selection if set, otherwise defaults to all enabled syndicators.
	 */
	private function resolve_targets( int $post_id ): array {
		$selected = get_post_meta( $post_id, 'nop_indieweb_syndicate_to', true );

		if ( is_array( $selected ) && $selected ) {
			return $selected;
		}

		// No explicit selection — default to all enabled and configured syndicators.
		return array_map(
			fn( $s ) => $s->slug(),
			array_filter( $this->syndicators, fn( $s ) => $s->enabled() )
		);
	}

	/**
	 * Resolves a syndication URL to the syndicator that owns it.
	 * Returns [ 'slug' => ..., 'label' => ... ] or null when unknown.
	 */
	public function resolve_url( string $url ): ?array {
		foreach ( $this->syndicators as $syndicator ) {
			if ( $syndicator->matches_url( $url ) ) {
				return [
					'slug'  => $syndicator->slug(),
					'label' => $syndicator->label(),
				];
			}
		}
		return null;
	}

	/** Returns the syndicator with the given slug, or null if unknown. */
	/**
	 * Per-network editor previews for the networks this post will actually go to
	 * (respecting the syndicate_to selection). Syndicators with no text preview
	 * (Tumblr) or that don't apply return null and are dropped.
	 *
	 * @return array<int,array{slug:string,label:string,text:string,count:int,limit:int,badge:string}>
	 */
	public function editor_previews( int $post_id ): array {
		$out = [];
		foreach ( $this->resolve_targets( $post_id ) as $slug ) {
			$syndicator = $this->get( $slug );
			$preview    = $syndicator ? $syndicator->editor_preview( $post_id ) : null;
			if ( null !== $preview ) {
				$out[] = $preview;
			}
		}
		return $out;
	}

	public function get( string $slug ): ?Syndicator_Base {
		foreach ( $this->syndicators as $syndicator ) {
			if ( $syndicator->slug() === $slug ) {
				return $syndicator;
			}
		}
		return null;
	}

	/** Returns syndicator definitions for the editor panel. */
	public function get_panel_data(): array {
		return array_values( array_map(
			fn( $s ) => [ 'slug' => $s->slug(), 'label' => $s->label() ],
			array_filter( $this->syndicators, fn( $s ) => $s->enabled() )
		) );
	}
}
