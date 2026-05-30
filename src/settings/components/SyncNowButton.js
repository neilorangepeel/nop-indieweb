import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function SyncNowButton( { platform, lastSyncedAt = '' } ) {
	const nonces  = window.nopIndieWebSettings?.syncNonces ?? {};
	const adminUrl = window.nopIndieWebSettings?.adminUrl ?? '';
	const nonce   = nonces[ platform ];

	if ( ! nonce ) {
		return null;
	}

	const syncUrl = adminUrl
		+ 'options-general.php?page=nop-indieweb-settings'
		+ '&nop_indieweb_sync=' + platform
		+ '&_wpnonce=' + nonce;

	return (
		<div className="nop-sync-now">
			<Button variant="secondary" size="small" href={ syncUrl }>
				{ __( 'Sync now', 'nop-indieweb' ) }
			</Button>
			{ lastSyncedAt && (
				<span className="nop-sync-now__last description">
					{ lastSyncedAt }
				</span>
			) }
		</div>
	);
}
