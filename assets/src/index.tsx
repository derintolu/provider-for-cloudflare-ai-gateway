import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import App from './App';
import './style.scss';

domReady( () => {
	const root = document.getElementById( 'cloudflare-ai-gateway-root' );
	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
