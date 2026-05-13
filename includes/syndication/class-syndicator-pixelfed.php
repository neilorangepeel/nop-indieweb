<?php
declare( strict_types=1 );

namespace NOP\IndieWeb\Syndication;

class Syndicator_Pixelfed extends Mastodon_Compatible_Syndicator {

	public function slug(): string  { return 'pixelfed'; }
	public function label(): string { return 'Pixelfed'; }

	protected function char_limit(): int { return 2000; }
}
