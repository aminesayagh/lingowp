import { BlockStack, Card, Spinner, Text } from '@shopify/polaris'
import { useEffect, useState } from '@wordpress/element'
import { AnimatePresence, motion } from 'motion/react'
import { api } from '../api'
import OnboardingPage from './OnboardingPage'

// Step 2 of 2. Nothing to configure here — the initial scan runs automatically and
// the screen redirects to the dashboard once it finishes. No counts/percentages
// (arch: not actionable), and no manual controls.
export default function SettingUp( { onDone } ) {
	const [ status, setStatus ] = useState( 'running' )

	// Light polling of scan status.
	useEffect( () => {
		let active = true
		const tick = () => {
			api.get( '/scan/status' ).then( ( res ) => {
				if ( active ) {
					setStatus( res.status || 'running' )
				}
			} )
		}
		tick()
		const id = setInterval( tick, 3000 )
		return () => {
			active = false
			clearInterval( id )
		}
	}, [] )

	// When the scan is done (completed or failed), finish onboarding and redirect.
	// A short beat lets the user read the result first.
	useEffect( () => {
		if ( status === 'running' ) {
			return
		}
		const delay = status === 'failed' ? 2500 : 1200
		const id = setTimeout( () => {
			api.post( '/onboarding/complete' ).then( ( res ) => {
				onDone( res.next || 'dashboard_empty_language' )
			} )
		}, delay )
		return () => clearTimeout( id )
	}, [ onDone, status ] )

	return (
		<OnboardingPage>
			<Card>
				<AnimatePresence initial={ false } mode="wait">
					<motion.div
						key={ status }
						initial={ { opacity: 0, y: 6 } }
						animate={ { opacity: 1, y: 0 } }
						exit={ { opacity: 0, y: -6 } }
						transition={ { duration: 0.16, ease: 'easeOut' } }
					>
						<BlockStack gap="300" inlineAlign="center">
							{ status === 'running' && (
								<>
									<Spinner
										accessibilityLabel="Scanning"
										size="small"
									/>
									<Text
										alignment="center"
										as="p"
										tone="subdued"
										variant="bodyMd"
									>
										We&#39;re automatically scanning your
										site for translatable content.
									</Text>
								</>
							) }

							{ status === 'completed' && (
								<>
									<Text
										alignment="center"
										as="h2"
										variant="headingMd"
									>
										Setup is ready
									</Text>
									<Text
										alignment="center"
										as="p"
										tone="subdued"
										variant="bodyMd"
									>
										Opening your dashboard…
									</Text>
								</>
							) }

							{ status === 'failed' && (
								<>
									<Text
										alignment="center"
										as="h2"
										variant="headingMd"
									>
										Setup could not finish
									</Text>
									<Text
										alignment="center"
										as="p"
										tone="subdued"
										variant="bodyMd"
									>
										We could not complete the automatic
										scan. Opening your dashboard — your
										setup may need support review.
									</Text>
								</>
							) }
						</BlockStack>
					</motion.div>
				</AnimatePresence>
			</Card>
		</OnboardingPage>
	)
}
