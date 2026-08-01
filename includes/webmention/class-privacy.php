<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Webmention;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires webmention comments into WordPress's personal-data export and erasure
 * flows.
 *
 * Core already exports and anonymises the wp_comments columns, but it knows
 * nothing about this plugin's comment meta — so an erasure request would
 * anonymise the comment while leaving the author's avatar URL and source
 * permalink sitting in wp_commentmeta. These callbacks close that gap.
 *
 * Both match on email, because that is the only identifier core's request flow
 * accepts. Webmentions from Mastodon/Bluesky usually carry no email, so those
 * are reachable only by deleting the comment itself — see the plugin README.
 */
class Privacy {

	private const PER_PAGE = 100;

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	public function register_exporter( array $exporters ): array {
		$exporters['nop-indieweb-webmentions'] = [
			'exporter_friendly_name' => __( 'Webmentions and likes', 'nop-indieweb' ),
			'callback'               => [ $this, 'export' ],
		];
		return $exporters;
	}

	public function register_eraser( array $erasers ): array {
		$erasers['nop-indieweb-webmentions'] = [
			'eraser_friendly_name' => __( 'Webmentions and likes', 'nop-indieweb' ),
			'callback'             => [ $this, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$comments = $this->get_comments( $email_address, $page );
		$data     = [];

		foreach ( $comments as $comment ) {
			$fields = [];
			foreach ( $this->exportable_fields() as $key => $label ) {
				$value = (string) get_comment_meta( $comment->comment_ID, $key, true );
				if ( '' !== $value ) {
					$fields[] = [ 'name' => $label, 'value' => $value ];
				}
			}

			if ( ! $fields ) {
				continue;
			}

			$data[] = [
				'group_id'    => 'nop-indieweb-webmentions',
				'group_label' => __( 'Webmentions and likes', 'nop-indieweb' ),
				'item_id'     => 'webmention-' . $comment->comment_ID,
				'data'        => $fields,
			];
		}

		return [
			'data' => $data,
			'done' => count( $comments ) < self::PER_PAGE,
		];
	}

	/**
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$comments = $this->get_comments( $email_address, $page );
		$removed  = false;

		foreach ( $comments as $comment ) {
			foreach ( $this->erasable_keys() as $key ) {
				if ( '' !== (string) get_comment_meta( $comment->comment_ID, $key, true ) ) {
					delete_comment_meta( $comment->comment_ID, $key );
					$removed = true;
				}
			}
		}

		return [
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => [],
			'done'           => count( $comments ) < self::PER_PAGE,
		];
	}

	/**
	 * @return array<int, \WP_Comment>
	 */
	private function get_comments( string $email_address, int $page ): array {
		if ( ! is_email( $email_address ) ) {
			return [];
		}

		return get_comments( [
			'type'         => 'webmention',
			'author_email' => $email_address,
			'number'       => self::PER_PAGE,
			'paged'        => max( 1, $page ),
			'orderby'      => 'comment_ID',
			'order'        => 'ASC',
			'status'       => 'all',
		] );
	}

	/**
	 * @return array<string, string>
	 */
	private function exportable_fields(): array {
		return [
			'webmention_author_photo' => __( 'Author avatar URL', 'nop-indieweb' ),
			'webmention_original_url' => __( 'Source permalink', 'nop-indieweb' ),
			'webmention_platform'     => __( 'Source platform', 'nop-indieweb' ),
		];
	}

	/**
	 * Platform ("mastodon", "bluesky") is not identifying, so it survives an
	 * erasure — the avatar URL, the source permalink and the hashed IP do not.
	 *
	 * @return array<int, string>
	 */
	private function erasable_keys(): array {
		return [ 'webmention_author_photo', 'webmention_original_url', 'webmention_ip_hash' ];
	}
}
