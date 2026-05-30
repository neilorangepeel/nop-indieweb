import apiFetch from '@wordpress/api-fetch';

const base = () => window.nopIndieWebSettings?.restUrl ?? '/wp-json/nop-indieweb/v1/settings';

export function getSettings() {
	return apiFetch( { url: base(), method: 'GET', parse: true } );
}

export function updateSettings( data ) {
	return apiFetch( { url: base(), method: 'POST', data, parse: true } );
}

export function getSessions() {
	return apiFetch( { url: base() + '/sessions', method: 'GET', parse: true } );
}

export function revokeSession( id ) {
	return apiFetch( { url: base() + '/sessions/' + id, method: 'DELETE', parse: true } );
}

export function testConnection( platform ) {
	const url = base().replace( '/settings', '/test-connection' );
	return apiFetch( { url, method: 'POST', data: { platform }, parse: true } );
}
