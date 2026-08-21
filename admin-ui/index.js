import { createRoot } from '@wordpress/element'
import { NuqsAdapter } from 'nuqs/adapters/react'
import '@shopify/polaris/build/esm/styles.css'
import './wp-admin-reset.css' // strip wp-admin form-style bleed from Polaris inputs
import './tailwind.css'
import App from './App'
import BillingApp from './BillingApp'
import InsightsApp from './InsightsApp'

const mount = document.getElementById( 'translangs-app' )
if ( mount ) {
	createRoot( mount ).render(
		<NuqsAdapter>
			<App />
		</NuqsAdapter>
	)
}

const billingMount = document.getElementById( 'translangs-billing-app' )
if ( billingMount ) {
	createRoot( billingMount ).render(
		<NuqsAdapter>
			<BillingApp />
		</NuqsAdapter>
	)
}

const insightsMount = document.getElementById( 'translangs-insights-app' )
if ( insightsMount ) {
	createRoot( insightsMount ).render(
		<NuqsAdapter>
			<InsightsApp />
		</NuqsAdapter>
	)
}
