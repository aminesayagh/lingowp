// Copy for the Insights preview screen. Grouped by topic, not by build order —
// this screen presents Insights as one finished feature, not a roadmap.
// Voice: copywrite-profile-voice.md — clear, concise, neutral-casual,
// professional. No marketing language, no internal delivery detail.

export const HERO = {
	eyebrow: 'Coming soon',
	headline: 'Find out which language to add next',
	body: "Counts the languages your visitors ask for, even ones you don't offer yet.",
	emailNoteMissing: 'Set an admin email for this site to continue.',
}

export const KPI_ROW = [
	{ label: 'Translated views' },
	{ label: 'Languages live' },
	{ label: 'Unserved demand' },
	{ label: 'Fallback rate' },
]

export const TOPICS = [
	{
		id: 'demand',
		heading: 'Demand',
		note: 'Which languages your visitors want, and where they come from.',
		panels: [
			{
				id: 'what_to_translate_next',
				title: 'What to translate next',
				lede: "The languages you're missing the most, ranked by audience size.",
				body: {
					type: 'bars',
					items: [
						{ label: 'Portuguese', width: 100 },
						{ label: 'Italian', width: 55 },
						{ label: 'Dutch', width: 33 },
						{ label: 'Japanese', width: 24 },
						{ label: 'Turkish', width: 19 },
					],
				},
			},
			{
				id: 'where_visitors_come_from',
				title: 'Where visitors come from',
				lede: "Your top countries, flagged where you don't serve their language.",
				body: {
					type: 'bars',
					items: [
						{ label: 'United States', width: 100 },
						{ label: 'France', width: 58 },
						{ label: 'Brazil', width: 27 },
						{ label: 'Germany', width: 20 },
						{ label: 'Italy', width: 12 },
					],
				},
			},
			{
				id: 'import_history',
				title: 'Historical demand',
				lede: 'Which languages visitors have wanted all along, pulled from your existing Google Analytics data.',
				body: {
					type: 'bars',
					items: [
						{ label: 'Past 3 years', width: 88 },
						{ label: 'By language', width: 58 },
						{ label: 'By country', width: 34 },
					],
				},
			},
		],
	},
	{
		id: 'traffic',
		heading: 'Traffic & engagement',
		note: 'How visitors use and respond to each language you offer.',
		panels: [
			{
				id: 'traffic_by_language',
				title: 'Traffic by language',
				lede: 'Views, landing language, and device breakdown for each language you serve.',
				body: {
					type: 'bars',
					items: [
						{ label: 'French', width: 100 },
						{ label: 'Spanish', width: 66 },
						{ label: 'Arabic', width: 37 },
						{ label: 'Googlebot on /fr/', width: 22 },
					],
				},
			},
			{
				id: 'switcher_behavior',
				title: 'Switcher behavior',
				lede: 'Which languages people switch to by hand, and which ones they switch away from.',
				body: {
					type: 'bars',
					items: [
						{ label: 'Switched to French', width: 80 },
						{ label: 'Switched to Spanish', width: 55 },
						{ label: 'Left Arabic for English', width: 38 },
					],
				},
			},
			{
				id: 'goals_and_conversions',
				title: 'Goals & conversions',
				lede: 'Whether translated visitors convert — purchases, signups, or forms — by language.',
				body: {
					type: 'bars',
					items: [
						{ label: 'Conversions French', width: 76 },
						{ label: 'Conversions Spanish', width: 52 },
						{ label: 'Per 1,000 views', width: 19 },
					],
				},
			},
			{
				id: 'most_translated_pages',
				title: 'Most translated pages',
				lede: 'Which pages earn the most multilingual traffic, and which cost the most to translate.',
				body: {
					type: 'bars',
					items: [
						{ label: '/pricing', width: 84, mono: true },
						{ label: '/features', width: 52, mono: true },
						{ label: '/about', width: 28, mono: true },
					],
				},
			},
			{
				id: 'visitor_engagement',
				title: 'Visitor engagement',
				lede: 'Pages per visit, bounce rate, and return visits — for each language.',
				body: {
					type: 'bars',
					items: [
						{ label: 'Pages per visit', width: 62 },
						{ label: 'Bounce rate French', width: 44 },
						{ label: 'Returning visitors', width: 27 },
					],
				},
			},
		],
	},
	{
		id: 'quality',
		heading: 'Quality & coverage',
		note: 'How complete and accurate each translation is.',
		panels: [
			{
				id: 'coverage_and_fallback',
				title: 'Coverage & fallback',
				lede: 'How often visitors see untranslated text instead of a translation, and on which pages.',
				body: {
					type: 'bars',
					items: [
						{ label: 'Fallbacks in Arabic', width: 90 },
						{ label: 'Fallbacks in German', width: 45 },
						{ label: '/pricing', width: 64, mono: true },
						{ label: '/features/api', width: 41, mono: true },
					],
				},
			},
			{
				id: 'translation_quality',
				title: 'Translation quality',
				lede: 'Where translations need the most correction, and the lines you keep fixing again and again.',
				body: {
					type: 'bars',
					items: [
						{ label: 'Corrections in Arabic', width: 88 },
						{ label: 'Corrections in German', width: 46 },
						{ label: 'Most re-edited strings', width: 24 },
					],
				},
			},
			{
				id: 'coverage_snapshot',
				title: 'Coverage snapshot',
				lede: 'How complete each language is, and what needs review after recent edits.',
				body: {
					type: 'bars',
					items: [
						{ label: 'French complete', width: 94 },
						{ label: 'Arabic complete', width: 44 },
						{ label: 'Waiting for review', width: 26 },
					],
				},
			},
		],
	},
	{
		id: 'health',
		heading: 'Performance & health',
		note: 'Whether translation is working correctly, and its effect on your site.',
		panels: [
			{
				id: 'speed_and_cost',
				title: 'Speed & cost',
				lede: 'How fast translation responds, and how much slower — if at all — it makes your pages.',
				body: {
					type: 'stat_grid',
					items: [
						{ label: 'Translations run' },
						{ label: 'New strings found' },
						{ label: 'Average speed' },
						{ label: 'Added to page load' },
						{ label: 'Errors' },
						{ label: 'Timeouts' },
					],
				},
			},
			{
				id: 'plugin_health',
				title: 'Plugin health',
				lede: "Confirms numbers are coming in, every language you turned on is live, and nothing's broken.",
				body: {
					type: 'health',
					items: [
						{ label: 'Stats saving correctly' },
						{ label: 'All enabled languages serving' },
						{ label: 'Background errors' },
					],
				},
			},
			{
				id: 'broken_translated_links',
				title: 'Broken translated links',
				lede: 'Pages that work in your language but return "not found" under a language prefix.',
				body: {
					type: 'bars',
					items: [
						{ label: '/fr/checkout', width: 70, mono: true },
						{ label: '/es/blog/pricing', width: 41, mono: true },
						{ label: '/ar/contact', width: 23, mono: true },
					],
				},
			},
		],
	},
]
