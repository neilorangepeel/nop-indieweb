import { ToggleControl, SelectControl, FormTokenField } from '@wordpress/components';
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

function tokenList( str ) {
	return str ? str.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) : [];
}
function tokenString( tokens ) {
	return tokens.join( ', ' );
}

export default function InteractionKindsTable( { services, onChange } ) {
	const categories = window.nopIndieWebSettings?.categories ?? [];

	const setKind = ( slug, key, val ) =>
		onChange( { ...services, [ slug ]: { ...( services[ slug ] ?? {} ), [ key ]: val } } );

	return (
		<table className="nop-kinds-table widefat">
			<thead>
				<tr>
					<th>{ __( 'Kind', 'nop-indieweb' ) }</th>
					<th>{ __( 'Enable', 'nop-indieweb' ) }</th>
					<th>{ __( 'Status', 'nop-indieweb' ) }</th>
					<th>{ __( 'Category', 'nop-indieweb' ) }</th>
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
							<td>
								<FormTokenField
									label={ sprintf( __( '%s category', 'nop-indieweb' ), label ) }
									__experimentalShowHowTo={ false }
									value={ tokenList( k.post_category ?? '' ) }
									suggestions={ categories }
									maxLength={ 1 }
									onChange={ ( tokens ) => setKind( slug, 'post_category', tokenString( tokens ) ) }
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
