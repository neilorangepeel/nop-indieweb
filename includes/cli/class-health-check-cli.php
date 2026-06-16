<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Cli;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use NOP\IndieWeb\Health_Check;

/**
 * On-demand health-check companion to the daily WP-Cron event.
 *
 *   wp nop-indieweb health-check         # run all checks now, print summary
 *   wp nop-indieweb health-check --json  # machine-readable output
 *   wp nop-indieweb health-check status  # print last-stored status without re-running
 */
class Health_Check_CLI {

	/**
	 * Run every configured provider check and write the result to the
	 * health-status option (same path the daily cron uses).
	 *
	 * ## OPTIONS
	 *
	 * [<subcommand>]
	 * : 'status' prints the last-stored result without making any requests.
	 *   Default (omitted) runs every check now.
	 *
	 * [--json]
	 * : Machine-readable JSON output instead of the table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nop-indieweb health-check
	 *     wp nop-indieweb health-check status
	 *     wp nop-indieweb health-check --json
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc ): void {
		$subcommand = $args[0] ?? '';
		$as_json    = ! empty( $assoc['json'] );

		if ( 'status' === $subcommand ) {
			$status = (array) get_option( Health_Check::OPTION_KEY, [] );
			$this->report( (array) ( $status['providers'] ?? [] ), $as_json );
			return;
		}

		\WP_CLI::log( 'Running health checks…' );
		$health    = new Health_Check();
		$providers = $health->run();
		$this->report( $providers, $as_json );

		$failing = array_filter( $providers, static fn( $p ) => 'error' === ( $p['status'] ?? '' ) );
		if ( $failing ) {
			\WP_CLI::error( sprintf( '%d provider(s) failing.', count( $failing ) ) );
		}
	}

	private function report( array $providers, bool $as_json ): void {
		if ( $as_json ) {
			\WP_CLI::log( wp_json_encode( $providers, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( ! $providers ) {
			\WP_CLI::log( '(no health-check data yet — run without "status" to populate)' );
			return;
		}

		$rows = [];
		foreach ( $providers as $slug => $info ) {
			$rows[] = [
				'provider' => $slug,
				'status'   => $info['status']         ?? '?',
				'http'     => $info['last_http_code'] ?? '',
				'message'  => $info['last_message']   ?? '',
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'provider', 'status', 'http', 'message' ] );
	}
}
