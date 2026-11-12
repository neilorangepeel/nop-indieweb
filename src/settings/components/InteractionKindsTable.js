import { ToggleControl, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const STATUS_OPTIONS = [
	{ value: 'publish', label: __( 'Published', 'nop-indieweb' ) },
	{ value: 'draft',   label: __( 'Draft',     'nop-indieweb' ) },
	{ value: 'private', label: __( 'Private',   'nop-indieweb' ) },
];

const KINDS = [
	{ slug: 'bookmark', label: __( 'Bookmark', 'nop-indieweb' ), prop: 'bookmark-of' },
	{ slug: 'reply',    label: __( 'Reply',    'nop-indieweb' ), prop: 'in-reply-to' },
	{ slug: 'like',     label: __( 'Like',     'nop-indieweb' ), prop: 'like-of'     },
	{ slug: 'repost',   label: __( 'Repost',   'nop-indieweb' ), prop: 'repost-of'   },
	{ slug: 'rsvp',     label: __( 'RSVP',     'nop-indieweb' ), prop: 'rsvp'        },
];

export default function InteractionKindsTable( { services, onChange } ) {
	const setKind = ( slug, key, val ) =>
		onChange( { ...services, [ slug ]: { ...( services[ slug ] ?? {} ), [ key ]: val } } );

	return (
		<table className="nop-kinds-table widefat">
			<thead>
				<tr>
					<th>{ __( 'Kind', 'nop-indieweb' ) }</th>
					<th>{ __( 'Enable', 'nop-indieweb' ) }</th>
					<th>{ __( 'Status', 'nop-indieweb' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ KINDS.map( ( { slug, label, prop } ) => {
					const k = services[ slug ] ?? {};
					return (
						<tr key={ slug }>
							<td>
								<strong>{ label }</strong>
								<br />
								<code className="nop-kind-badge">{ prop }</code>
							</td>
							<td>
								<ToggleControl
									label={ sprintf( __( 'Enable %s', 'nop-indieweb' ), label ) }
									hideLabelFromVision
									checked={ k.enabled ?? true }
									onChange={ ( val ) => setKind( slug, 'enabled', val ) }
									__nextHasNoMarginBottom
								/>
							</td>
							<td>
								<SelectControl
									label={ sprintf( __( '%s post status', 'nop-indieweb' ), label ) }
									hideLabelFromVision
									value={ k.post_status ?? 'publish' }
									options={ STATUS_OPTIONS }
									onChange={ ( val ) => setKind( slug, 'post_status', val ) }
									__nextHasNoMarginBottom
								/>
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}
