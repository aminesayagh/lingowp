import { createRoot } from '@wordpress/element'
import { NuqsAdapter } from 'nuqs/adapters/react'
import '@shopify/polaris/build/esm/styles.css'
import './wp-admin-reset.css' // strip wp-admin form-style bleed from Polaris inputs
import './tailwind.css'
import App from './App'
import BillingApp from './BillingApp'
import InsightsApp from './InsightsApp'

const mount = document.getElementById( 'lingowp-app' )
if ( mount ) {
	createRoot( mount ).render(
		<NuqsAdapter>
			<App />
		</NuqsAdapter>
	)
}

const billingMount = document.getElementById( 'lingowp-billing-app' )
if ( billingMount ) {
	createRoot( billingMount ).render(
		<NuqsAdapter>
			<BillingApp />
		</NuqsAdapter>
	)
}

const insightsMount = document.getElementById( 'lingowp-insights-app' )
if ( insightsMount ) {
	createRoot( insightsMount ).render(
		<NuqsAdapter>
			<InsightsApp />
		</NuqsAdapter>
	)
}
