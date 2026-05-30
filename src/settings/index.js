import { render } from '@wordpress/element';
import App from './App';

const root = document.getElementById( 'nop-settings-root' );
if ( root ) {
	render( <App />, root );
}
