<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\AiPolicy;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opt the site out of AI model training.
 *
 * Two signals, both gated behind the `block_ai_training` setting:
 *
 *   1. robots.txt — appends `Disallow: /` blocks for known AI training and
 *      scraping crawlers. The well-behaved operators (OpenAI, Anthropic,
 *      Google, Apple, Common Crawl) honour these; bad actors ignore them, so
 *      this is a request, not a wall. It covers the commercial crawlers that
 *      actually harvest content for training.
 *   2. A site-wide `<meta name="robots" content="noai, noimageai">` tag — a
 *      newer, lower-adoption signal some crawlers respect. Free to emit.
 *
 * Two of the tokens below — Google-Extended and Applebot-Extended — are
 * AI-specific opt-out tokens, NOT crawlers. Disallowing them blocks Gemini /
 * Apple Intelligence training without affecting Google Search or Siri/Spotlight
 * indexing, which use the separate Googlebot / Applebot tokens we leave alone.
 *
 * Only ever appends to robots.txt — never rewrites the existing rules — so the
 * Sitemap line and the wp-admin block WordPress emits stay intact. The
 * `robots_txt` filter only fires for WordPress's virtual robots.txt, so a site
 * that later drops a static robots.txt on disk would silently lose the rules;
 * the meta tag still applies in that case.
 */
class AI_Policy {

	/**
	 * AI training / scraping user-agents and opt-out tokens to disallow.
	 *
	 * Curated from the major commercial operators rather than every entry on
	 * the long-tail blocklists — the goal is the crawlers that meaningfully
	 * harvest content for model training, not an exhaustive arms race.
	 */
	private const AI_AGENTS = [
		// OpenAI.
		'GPTBot',
		'OAI-SearchBot',
		'ChatGPT-User',
		// Anthropic.
		'ClaudeBot',
		'anthropic-ai',
		'Claude-Web',
		// Google (AI training only — Googlebot search crawling is untouched).
		'Google-Extended',
		// Apple (AI training only — Applebot search crawling is untouched).
		'Applebot-Extended',
		// Common Crawl (feeds many downstream training sets).
		'CCBot',
		// Meta.
		'meta-externalagent',
		'Meta-ExternalAgent',
		'FacebookBot',
		// ByteDance.
		'Bytespider',
		// Perplexity.
		'PerplexityBot',
		// Amazon.
		'Amazonbot',
		// Cohere.
		'cohere-ai',
		// Others with a track record of training-data scraping.
		'Diffbot',
		'Omgilibot',
		'Omgili',
		'ImagesiftBot',
		'YouBot',
		'Timpibot',
	];

	public function register(): void {
		if ( ! (bool) \NOP\IndieWeb\nop_indieweb_get_option( 'block_ai_training', false ) ) {
			return;
		}

		add_filter( 'robots_txt', [ $this, 'append_robots_rules' ], 10, 2 );
		add_action( 'wp_head', [ $this, 'output_meta' ], 1 );
	}

	/**
	 * Appends one Disallow block per AI agent to the virtual robots.txt.
	 *
	 * @param string $output The robots.txt content assembled so far.
	 * @param bool   $public Whether the site is set to be crawlable at all.
	 */
	public function append_robots_rules( string $output, bool $public ): string {
		// If the whole site is set to discourage search engines, WordPress
		// already emits a blanket disallow — no need to pile on.
		if ( ! $public ) {
			return $output;
		}

		$lines = [ '', '# Opt out of AI model training (nop-indieweb).' ];
		foreach ( self::AI_AGENTS as $agent ) {
			$lines[] = 'User-agent: ' . $agent;
			$lines[] = 'Disallow: /';
		}

		return rtrim( $output ) . "\n" . implode( "\n", $lines ) . "\n";
	}

	/**
	 * Emits a site-wide noai/noimageai robots meta tag. Additive — sits
	 * alongside any index/noindex robots meta an SEO plugin emits, since
	 * multiple robots metas are combined rather than overriding each other.
	 */
	public function output_meta(): void {
		echo '<meta name="robots" content="noai, noimageai" />' . "\n";
	}
}
