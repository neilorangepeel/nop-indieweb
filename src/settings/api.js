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

export function getHealthStatus() {
	return apiFetch( { url: base() + '/health', method: 'GET', parse: true } );
}

export function getSyndicationHealth() {
	const url = base().replace( '/settings', '/syndication/health' );
	return apiFetch( { url, method: 'GET', parse: true } );
}

export function retrySyndication( postId, target ) {
	const url = base().replace( '/settings', '/syndication/retry' );
	return apiFetch( { url, method: 'POST', data: { post_id: postId, target }, parse: true } );
}

export function runHealthCheck() {
	return apiFetch( { url: base() + '/health', method: 'POST', parse: true } );
}
