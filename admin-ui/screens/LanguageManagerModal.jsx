import {
	BlockStack,
	Button,
	Divider,
	InlineStack,
	Modal,
	Select,
	Text,
	TextField,
} from '@shopify/polaris';
import { useEffect, useState } from '@wordpress/element';
import { api } from '../api';
import LanguageList from './LanguageList';

const enc = encodeURIComponent;
const localeSlug = (locale) => locale.toLowerCase().replaceAll('_', '-');
const regionLabel = (item) => {
	let region = item.region_name || item.region || 'General';
	if (item.region && (!item.region_name || item.region_name === item.region)) {
		try {
			region = new Intl.DisplayNames(undefined, { type: 'region' }).of(item.region) || item.region;
		} catch {
			region = item.region;
		}
	}
	return item.variant_name ? `${region} — ${item.variant_name}` : region;
};

export const AI_MODELS = [{ label: 'Auto', value: 'auto' }];
export const AI_STYLE_MAX = 500;
export const AI_STYLE_PLACEHOLDER = 'Professional, clear, modern, and approachable.';

// Language manager: a sidebar of every language (reuses the dashboard's
// LanguageList) + a config pane. Selecting a row edits it; "+ Add" starts a new
// one. Stays open after save so several languages can be added/reconfigured.
export default function LanguageManagerModal({ open, languages, catalog, defaultCode, initialCode, onClose, onChanged }) {
	const [sel, setSel] = useState('new');
	const isFirstLanguage = languages.length === 0;

	useEffect(() => {
		if (open) {
			setSel(initialCode || languages[0]?.code || 'new');
		}
	}, [open, initialCode]);

	const current = sel === 'new' ? null : languages.find((l) => l.code === sel) || null;
	const mode = current ? 'edit' : 'new';

	// If the selected language was deleted elsewhere, fall back to the first.
	useEffect(() => {
		if (sel !== 'new' && !current) {
			setSel(languages[0]?.code || 'new');
		}
	}, [languages]);

	function toggleStatus(lang) {
		api.patch(`/languages/${enc(lang.code)}`, { status: lang.enabled ? 'draft' : 'active' }).then((r) => {
			if (r.ok) {
				onChanged();
			}
		});
	}

	function del(lang) {
		api.del(`/languages/${enc(lang.code)}`).then((r) => {
			if (r.ok) {
				if (sel === lang.code) {
					setSel('new');
				}
				onChanged();
			}
		});
	}

	return (
		<Modal
			open={open}
			onClose={onClose}
			title="Configuration"
			large
		>
			<Modal.Section>
				<div className="tl-wide-modal" style={{ display: 'flex', gap: isFirstLanguage ? 0 : 16, minHeight: 420, maxWidth: '100%', overflowX: 'hidden' }}>
					{!isFirstLanguage && (
						<div
							style={{
								flex: '0 0 220px',
								width: 220,
								borderInlineEnd: '1px solid var(--p-color-border, rgba(0,0,0,0.08))',
								paddingInlineEnd: 12,
							}}
						>
							<BlockStack gap="200">
								<Text as="h3" variant="headingSm">Languages</Text>
								<LanguageList
									languages={languages}
									selectedCode={current?.code}
									onSelect={(lang) => setSel(lang.code)}
									onToggleStatus={toggleStatus}
									onDelete={del}
									onAdd={() => setSel('new')}
								/>
							</BlockStack>
						</div>
					)}

					<div style={{ flex: 1, minWidth: 0, paddingBlockStart: 4, paddingInline: 5 }}>
						<LanguageForm
							key={`${sel}:${catalog?.length || 0}`}
							mode={mode}
							current={current}
							defaultCode={defaultCode}
							catalog={catalog}
							existing={languages.map((l) => l.code)}
							firstLanguage={isFirstLanguage}
							onDone={(code) => {
								if (isFirstLanguage && mode === 'new' && code) {
									onClose();
									onChanged();
									return;
								}
								onChanged();
								if (mode === 'new' && code) {
									setSel(code);
								}
							}}
						/>
					</div>
				</div>
			</Modal.Section>
		</Modal>
	);
}

function LanguageForm({ mode, current, catalog, defaultCode, existing, firstLanguage = false, onDone }) {
	const isNew = mode === 'new';
	const localeUnavailable = (locale) => locale === defaultCode || existing.includes(locale);
	const localeStatus = (locale) => {
		if (locale === defaultCode) {
			return ' — Website default';
		}
		return existing.includes(locale) ? ' — Already added' : '';
	};
	const catalogItems = catalog || [];
	const available = catalogItems.filter((item) => !localeUnavailable(item.locale));
	const languages = Array.from(
		catalogItems.reduce((groups, item) => {
			const group = groups.get(item.language) || {
				label: item.language_name,
				items: [],
			};
			group.items.push(item);
			groups.set(item.language, group);
			return groups;
		}, new Map()).entries()
	);
	const languageChoices = languages.map(([value, group]) => ({
		label: group.label,
		value,
		disabled: group.items.every((item) => localeUnavailable(item.locale)),
	}));
	const initialLocale = isNew ? available[0] || null : (catalog || []).find((item) => item.locale === current.code) || null;
	const [language, setLanguage] = useState(initialLocale?.language || '');
	const [code, setCode] = useState(isNew ? initialLocale?.locale || '' : current.code);
	const [displayName, setDisplayName] = useState(isNew ? '' : current.display_name || '');
	const [slug, setSlug] = useState(isNew ? localeSlug(initialLocale?.locale || '') : current.slug || '');
	const [fallback, setFallback] = useState(isNew ? defaultCode : current.fallback_language || '');
	const [aiModel, setAiModel] = useState(isNew ? 'auto' : current.ai_model || 'auto');
	const [style, setStyle] = useState(isNew ? '' : current.ai_style || '');
	const [direction, setDirection] = useState(isNew ? initialLocale?.direction || 'ltr' : current.direction || 'ltr');
	const [saving, setSaving] = useState(false);
	// Snapshot of the values when the form loaded; the Save button only appears
	// once the form differs from it (and resets to the saved values after save).
	const [baseline, setBaseline] = useState({ code, displayName, slug, fallback, aiModel, style });

	const dirty =
		code !== baseline.code ||
		displayName !== baseline.displayName ||
		slug !== baseline.slug ||
		fallback !== baseline.fallback ||
		aiModel !== baseline.aiModel ||
		style !== baseline.style;
	const firstLanguageReady = code.trim() !== '' && displayName.trim() !== '' && slug.trim() !== '';
	const showAction = firstLanguage || dirty;

	const regionChoices = catalogItems
		.filter((item) => item.language === language)
		.map((item) => ({
			label: `${regionLabel(item)}${localeStatus(item.locale)}`,
			value: item.locale,
			disabled: localeUnavailable(item.locale),
		}));
	const defaultLanguage = catalogItems.find((item) => item.locale === defaultCode);
	const fallbackChoices = [
		{ label: 'None', value: '' },
		{
			label: `${defaultLanguage?.english_name || defaultCode} — Website default`,
			value: defaultCode,
		},
		...catalogItems
			.filter(
				(item) =>
					existing.includes(item.locale) &&
					item.locale !== code &&
					item.locale !== defaultCode
			)
			.map((item) => ({ label: item.english_name, value: item.locale })),
	];

	function pickLanguage(value) {
		const choice = available.find((item) => item.language === value);
		if (!choice) {
			return;
		}
		setLanguage(value);
		pickLocale(choice.locale);
	}

	function pickLocale(value) {
		const choice = catalogItems.find((item) => item.locale === value);
		if (!choice || localeUnavailable(value)) {
			return;
		}
		setCode(value);
		setDirection(choice.direction || 'ltr');
		setSlug(localeSlug(value));
	}

	async function submit() {
		setSaving(true);
		const res = isNew
			? await api.post('/languages', {
				code,
				locale: code,
				slug,
				display_name: displayName,
				fallback_language: fallback,
				direction,
				ai_model: aiModel,
				ai_style: style,
			})
			: await api.patch(`/languages/${enc(current.code)}`, {
				display_name: displayName,
				slug,
				fallback_language: fallback,
				ai_model: aiModel,
				ai_style: style,
			});
		setSaving(false);
		if (res.ok) {
			setBaseline({ code, displayName, slug, fallback, aiModel, style });
			onDone(isNew ? code : current.code);
		}
	}

	if (isNew && catalog === null) {
		return <Text as="p" tone="subdued">Loading WordPress languages…</Text>;
	}

	if (isNew && available.length === 0) {
		return (
			<BlockStack gap="200">
				<Text as="h3" variant="headingMd">Add a language</Text>
				<Text as="p" tone="subdued">
					No additional WordPress languages are available. Open Settings → General → Site Language once to refresh WordPress’s language catalog, then reopen this form.
				</Text>
			</BlockStack>
		);
	}

	return (
		<BlockStack gap="300">
			<Text as="h3" variant="headingMd">
				{isNew ? 'Add a language' : `Edit ${current.display_name || current.name || current.code}`}
			</Text>
			<BlockStack gap="300">
				{isNew && (
					<>
						<Select
							label="Language"
							requiredIndicator
							options={languageChoices}
							value={language}
							onChange={pickLanguage}
						/>
						<Select
							label="Region"
							requiredIndicator
							options={regionChoices}
							value={code}
							onChange={pickLocale}
						/>
					</>
				)}
				<TextField label="Name" requiredIndicator value={displayName} onChange={setDisplayName} autoComplete="off" />
				<TextField label="URL slug" requiredIndicator value={slug} onChange={setSlug} autoComplete="off" />
				<Select
					label="Fallback language"
					requiredIndicator
					options={fallbackChoices}
					value={fallback}
					onChange={setFallback}
				/>
			</BlockStack>

			<Divider />

			<Text as="h3" variant="headingSm">AI settings</Text>
			<BlockStack gap="300">
				<Select label="AI model" options={AI_MODELS} value={aiModel} onChange={setAiModel} />
				<TextField
					label="Website voice and style"
					multiline={2}
					maxLength={AI_STYLE_MAX}
					showCharacterCount
					value={style}
					onChange={setStyle}
					autoComplete="off"
					placeholder={AI_STYLE_PLACEHOLDER}
					helpText="Your website voice guides the translation, but the original text’s tone and intent always take priority."
				/>
			</BlockStack>

			{showAction && (
				<InlineStack align="end">
					<Button
						variant="primary"
						loading={saving}
						disabled={firstLanguage ? !firstLanguageReady : isNew && !code}
						onClick={submit}
					>
						{firstLanguage ? 'Create language' : isNew ? 'Add language' : 'Save'}
					</Button>
				</InlineStack>
			)}
		</BlockStack>
	);
}
