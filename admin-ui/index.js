import { createRoot } from '@wordpress/element';
import '@shopify/polaris/build/esm/styles.css';
import App from './App';

const mount = document.getElementById( 'translangs-app' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
