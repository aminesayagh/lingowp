import { AppProvider, Page, Spinner } from '@shopify/polaris';
import enTranslations from '@shopify/polaris/locales/en.json';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { api } from './api';
import Configuration from './screens/Configuration';
import SettingUp from './screens/SettingUp';
import Dashboard from './screens/Dashboard';

// Reflect the current screen in the admin query string (?page=translangs&view=…)
// for shareable URLs. Routing is state-driven, not popstate-driven, so browser
// back/forward never steps through onboarding (arch nav rule).
function pushView( view ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'page', 'translangs' );
	url.searchParams.set( 'view', view );
	window.history.pushState( {}, '', url );
}

// Decide the landing screen. Incomplete onboarding can never reach the dashboard;
// completed onboarding can never revisit configuration.
function resolveView( state ) {
	if ( ! state.onboarding_completed ) {
		const urlView = new URLSearchParams( window.location.search ).get( 'view' );
		if ( urlView === 'onboarding_setting_up' && state.connected ) {
			return 'onboarding_setting_up';
		}
		return 'onboarding_configuration';
	}
	return state.has_target_language ? 'dashboard_workspace' : 'dashboard_empty_language';
}

export default function App() {
	const [ state, setState ] = useState( null );
	const [ view, setViewState ] = useState( null );

	const setView = useCallback( ( next ) => {
		setViewState( next );
		pushView( next );
	}, [] );

	useEffect( () => {
		api.get( '/onboarding/state' ).then( ( s ) => {
			setState( s );
			setViewState( resolveView( s ) );
		} );
	}, [] );

	if ( ! state || ! view ) {
		return (
			<AppProvider i18n={ enTranslations }>
				<Page>
					<Spinner accessibilityLabel="Loading Translangs" />
				</Page>
			</AppProvider>
		);
	}

	return (
		<AppProvider i18n={ enTranslations }>
			{ view === 'onboarding_configuration' && (
				<Configuration onDone={ () => setView( 'onboarding_setting_up' ) } />
			) }
			{ view === 'onboarding_setting_up' && (
				<SettingUp onDone={ ( next ) => setView( next ) } />
			) }
			{ ( view === 'dashboard_empty_language' || view === 'dashboard_workspace' ) && (
				<Dashboard
					view={ view }
					defaultLanguage={ state.default_language }
					onLanguageCreated={ () => setView( 'dashboard_workspace' ) }
				/>
			) }
		</AppProvider>
	);
}
