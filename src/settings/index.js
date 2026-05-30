import apiFetch from '@wordpress/api-fetch';
import { render } from '@wordpress/element';
import App from './App';
import './style.scss';

// Wire up the REST nonce so all apiFetch calls are authenticated.
const nonce = window.nopIndieWebSettings?.nonce;
if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

const root = document.getElementById( 'nop-settings-root' );
if ( root ) {
	render( <App />, root );
}
