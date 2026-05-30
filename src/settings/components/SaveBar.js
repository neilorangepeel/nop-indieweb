import { Button, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function SaveBar( { onSave, isSaving, notice, onDismiss } ) {
	return (
		<div className="nop-save-bar">
			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ onDismiss }
					isDismissible
				>
					{ notice.message }
				</Notice>
			) }
			<div className="nop-save-bar__actions">
				<Button
					variant="primary"
					onClick={ onSave }
					disabled={ isSaving }
				>
					{ __( 'Save Changes', 'nop-indieweb' ) }
				</Button>
				{ isSaving && <Spinner /> }
			</div>
		</div>
	);
}
