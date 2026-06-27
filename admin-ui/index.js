import { createRoot } from '@wordpress/element';
import '@shopify/polaris/build/esm/styles.css';
import App from './App';
import './board.css';

const mount = document.getElementById( 'translangs-board' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
