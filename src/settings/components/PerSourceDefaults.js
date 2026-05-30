import { SelectControl, ToggleControl, FormTokenField } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const STATUS_OPTIONS = [
	{ value: 'publish', label: __( 'Published', 'nop-indieweb' ) },
	{ value: 'draft',   label: __( 'Draft',     'nop-indieweb' ) },
	{ value: 'private', label: __( 'Private',   'nop-indieweb' ) },
];

function tokenList( str ) {
	return str ? str.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) : [];
}

function tokenString( tokens ) {
	return tokens.join( ', ' );
}

/**
 * Inbound defaults for one source (status, category, tags, sideload photo/poster).
 *
 * @param {string}   value.post_status     'publish'|'draft'|'private'
 * @param {string}   value.post_category   comma-separated category name
 * @param {string}   value.post_tags        comma-separated tag names
 * @param {boolean}  value.sideload_photos  (or sideload_poster for Letterboxd)
 * @param {boolean}  showPhotos            whether to show the sideload toggle
 * @param {string}   photosLabel           label for the sideload toggle
 */
export default function PerSourceDefaults( { value, onChange, showPhotos = true, photosLabel } ) {
	const categories = window.nopIndieWebSettings?.categories ?? [];
	const tags       = window.nopIndieWebSettings?.tags       ?? [];

	const set = ( key, val ) => onChange( { ...value, [ key ]: val } );

	const sideloadKey = Object.prototype.hasOwnProperty.call( value, 'sideload_poster' )
		? 'sideload_poster'
		: 'sideload_photos';

	return (
		<div className="nop-source-defaults">
			<SelectControl
				label={ __( 'Status', 'nop-indieweb' ) }
				value={ value.post_status ?? 'publish' }
				options={ STATUS_OPTIONS }
				onChange={ ( val ) => set( 'post_status', val ) }
				__nextHasNoMarginBottom
			/>
			<FormTokenField
				label={ __( 'Category', 'nop-indieweb' ) }
				value={ tokenList( value.post_category ?? '' ) }
				suggestions={ categories }
				maxLength={ 1 }
				onChange={ ( tokens ) => set( 'post_category', tokenString( tokens ) ) }
				__experimentalShowHowTo={ false }
				__nextHasNoMarginBottom
			/>
			<FormTokenField
				label={ __( 'Tags', 'nop-indieweb' ) }
				value={ tokenList( value.post_tags ?? '' ) }
				suggestions={ tags }
				onChange={ ( tokens ) => set( 'post_tags', tokenString( tokens ) ) }
				__experimentalShowHowTo={ false }
				__nextHasNoMarginBottom
			/>
			{ showPhotos && (
				<ToggleControl
					label={ photosLabel ?? __( 'Save photos to media library', 'nop-indieweb' ) }
					checked={ value[ sideloadKey ] ?? false }
					onChange={ ( val ) => set( sideloadKey, val ) }
					__nextHasNoMarginBottom
				/>
			) }
		</div>
	);
}
