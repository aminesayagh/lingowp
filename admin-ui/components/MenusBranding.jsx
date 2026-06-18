import ContentList from './ContentList';

const NO_FILTERS = { q_original: '', q_translated: '', origin: 'all' };

/**
 * Menus & Branding (wireframe A8). Two sections that reuse the inline editor:
 * site identity / branding (wp_options text → tl_option) and menu item labels
 * (nav_menu_item → tl_post). Plain reuse of ContentList per area, no new editor.
 */
export default function MenusBranding( { config, lang, onCountsChanged } ) {
	return (
		<>
			<ContentList
				config={ config }
				area="branding"
				lang={ lang }
				filters={ NO_FILTERS }
				onCountsChanged={ onCountsChanged }
				title="Site identity & branding"
			/>
			<ContentList
				config={ config }
				area="menus"
				lang={ lang }
				filters={ NO_FILTERS }
				onCountsChanged={ onCountsChanged }
				title="Menus"
			/>
		</>
	);
}
