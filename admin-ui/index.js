import { createRoot } from '@wordpress/element';
import '@shopify/polaris/build/esm/styles.css';
import './wp-admin-reset.css'; // strip wp-admin form-style bleed from Polaris inputs
import App from './App';

const mount = document.getElementById( 'translangs-app' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
