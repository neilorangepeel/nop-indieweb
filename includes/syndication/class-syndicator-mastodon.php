<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

class Syndicator_Mastodon extends Mastodon_Compatible_Syndicator {

	public function slug(): string  { return 'mastodon'; }
	public function label(): string { return 'Mastodon'; }

	protected function char_limit(): int { return 500; }
}
