import {
  ActionList,
  Badge,
  Banner,
  BlockStack,
  Box,
  Button,
  Card,
  ChoiceList,
  Collapsible,
  Divider,
  EmptyState,
  Filters,
  InlineStack,
  Modal,
  Page,
  Popover,
  Spinner,
  Text,
  TextField,
  Tooltip,
} from "@shopify/polaris";
import {
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
} from "@wordpress/element";
import {
  MagicIcon,
  SettingsIcon,
  SearchIcon,
  LanguageIcon,
  PageIcon
} from "@shopify/polaris-icons";
import { api } from "../api";
import SettingsModal from "./SettingsModal";
import LanguageManagerModal from "./LanguageManagerModal";
import LanguageList, { flagEmoji } from "./LanguageList";
import CompareOverlay from "./CompareOverlay";
import QuotaCard from "./QuotaCard";
import { hasMarkers, stripMarkers } from "./TranslationUnitEditor";

const StartIcon = ({ color = "currentColor", ...props }) => (
  <svg
    width="24"
    height="24"
    viewBox="0 0 24 24"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
    {...props}
  >
    <path
      d="M10.6618 4.89303C11.1085 3.70232 12.8915 3.70232 13.3382 4.89303L14.8693 8.97405C15.0123 9.3552 15.3298 9.65504 15.7334 9.79009L20.0544 11.2361C21.3152 11.658 21.3152 13.342 20.0544 13.7639L15.7334 15.2099C15.3298 15.345 15.0123 15.6448 14.8693 16.0259L13.3382 20.107C12.8915 21.2977 11.1085 21.2977 10.6618 20.107L9.13068 16.0259C8.98769 15.6448 8.67021 15.345 8.26665 15.2099L3.94556 13.7639C2.68481 13.342 2.68481 11.658 3.94556 11.2361L8.26665 9.79009C8.67021 9.65504 8.98769 9.3552 9.13068 8.97405L10.6618 4.89303Z"
      stroke={color}
      strokeWidth="1.5"
      strokeLinejoin="round"
    />
  </svg>
);

// Schema status → workspace pill (per design-ux-data-mapping.md §2). Translated
// rows carry no pill.
const STATUS_BADGE = {
  missing: null,
  needs_review: { tone: "warning", label: "Needs review" },
  reviewed: null,
  translated: null,
};

// Truncate a cell to a single line with an ellipsis; the whole cell is a
// clickable button that opens the overlay to read/edit the full text.
// ponytail: stays inline — `all: unset` must sit in the same declaration as the
// properties it resets, otherwise inline precedence wipes out equivalent utilities.
const oneLineStyle = {
  all: "unset",
  cursor: "pointer",
  display: "block",
  width: "100%",
  whiteSpace: "nowrap",
  overflow: "hidden",
  textOverflow: "ellipsis",
};

// Collection type → group heading prefix. Site settings has no prefix.
function groupHeading({ type, label }) {
  const prefix = {
    page: "Pages",
    post: "Posts",
    cpt: "Custom",
    taxonomy: "Taxonomies",
  }[type];
  return prefix ? `${prefix} · ${label}` : label;
}

const enc = encodeURIComponent;
const cfg = window.Translangs || {};

// Header status filters (client-side over collection counts).
const FILTERS = [
  { key: "untranslated", label: "Untranslated" },
  { key: "needs_review", label: "Needs review" },
  { key: "translated", label: "Translated" },
];

export default function Dashboard({
  view,
  defaultLanguage,
  onLanguageCreated,
}) {
  const [languages, setLanguages] = useState([]);
  const [selectedLang, setSelectedLang] = useState("");
  const [collections, setCollections] = useState(null);
  // expanded[id] = 'loading' | array of text units (undefined = collapsed).
  const [expanded, setExpanded] = useState({});
  const [search, setSearch] = useState("");
  const [managerOpen, setManagerOpen] = useState(false);
  const [managerInitial, setManagerInitial] = useState(null);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [overlay, setOverlay] = useState(null);
  const [confirm, setConfirm] = useState(null);
  const [ai, setAi] = useState({ available: false, error: "" });
  const [quota, setQuota] = useState(null);
  const [notice, setNotice] = useState(null);
  const [showSearch, setShowSearch] = useState(false);
  const [statusFilter, setStatusFilter] = useState("all");

  // Mirrors `expanded` so effects can read it without re-subscribing.
  const expandedRef = useRef({});
  expandedRef.current = expanded;

  const defaultCode = defaultLanguage?.code || defaultLanguage;

  // Open the language manager, optionally focused on a specific language.
  function openManager(code) {
    setManagerInitial(code || null);
    setManagerOpen(true);
  }

  function loadQuota() {
    api.get("/quota").then((res) => setQuota(res.ok ? res.quota : null));
  }

  useEffect(() => {
    api.get("/connection/status").then((res) => {
      setAi({ available: !!res.ai_available, error: res.ai_error || "" });
    });
    loadQuota();
  }, []);

  // AI translate a scope (text | collection | language), then refresh views + quota.
  async function translateScope(scope, opts = {}) {
    if (!ai.available) {
      setNotice({
        tone: "warning",
        content:
          ai.error ||
          "AI is unavailable. Connect Translangs to enable AI translation.",
      });
      return;
    }
    const res = await api.post("/translate", {
      scope,
      language: selectedLang,
      ...opts,
    });
    if (res.error === "ERR_QUOTA") {
      setNotice({
        tone: "warning",
        content: `Not enough words remaining (needs ~${res.estimated_words}, ${res.words_remaining} left). Upgrade to run this.`,
      });
      return;
    }
    if (!res.ok) {
      setNotice({
        tone: "critical",
        content: "AI translation failed. Please try again.",
      });
      return;
    }
    setNotice({
      tone: "success",
      content: `Translated ${res.translated} text${
        res.translated === 1 ? "" : "s"
      }.`,
    });
    loadCollections();
    Object.keys(expanded).forEach((id) => {
      if (Array.isArray(expanded[id])) {
        loadTexts(id);
      }
    });
    loadQuota();
    refreshLanguages(); // rail percentages changed
  }

  // Rail "Translate All": select that language, confirm, then AI-translate it whole.
  function translateLanguageAll(lang) {
    setSelectedLang(lang.code);
    setConfirm({
      title: `AI translate all of ${
        lang.display_name || lang.name || lang.code
      }?`,
      body: "All untranslated text in this language will be sent to AI translation and may use a large number of words.",
      actionLabel: "Translate all",
      onConfirm: () => translateScope("language", { language: lang.code }),
    });
  }

  function patchLangStatus(code, status) {
    api.patch(`/languages/${enc(code)}`, { status }).then((res) => {
      if (res.ok) {
        refreshLanguages();
      }
    });
  }

  function deleteLang(code) {
    api.del(`/languages/${enc(code)}`).then((res) => {
      if (res.ok) {
        setSelectedLang((cur) => (cur === code ? "" : cur));
        refreshLanguages();
      }
    });
  }

  function confirmDeleteLang(lang) {
    setConfirm({
      title: `Delete ${lang.display_name || lang.name || lang.code}?`,
      body: "This removes it from the public site and the workspace. Its translations are kept and restored if you re-add the language.",
      actionLabel: "Delete",
      destructive: true,
      onConfirm: () => deleteLang(lang.code),
    });
  }

  function refreshLanguages() {
    api.get("/languages").then((res) => {
      if (!res.ok) {
        return;
      }
      const langs = res.languages || [];
      setLanguages(langs);
      setSelectedLang((cur) => {
        if (cur && langs.some((l) => l.code === cur)) {
          return cur;
        }
        const active = langs.find((l) => l.enabled) || langs[0];
        return active?.code || "";
      });
    });
  }

  useEffect(refreshLanguages, []);

  function loadCollections() {
    api.get(`/collections?language=${enc(selectedLang)}`).then((res) => {
      setCollections(res.ok ? res.collections || [] : []);
    });
  }

  // Reload for the new working language, keeping open groups open — the overlay
  // switches language in place and would otherwise lose the row it is editing.
  useEffect(() => {
    if (!selectedLang) {
      return;
    }
    setCollections(null);
    loadCollections();
    Object.keys(expandedRef.current).forEach(loadTexts);
  }, [selectedLang]);

  function loadTexts(id) {
    api
      .get(`/collections/${enc(id)}/texts?language=${enc(selectedLang)}`)
      .then((res) => {
        setExpanded((c) => ({ ...c, [id]: res.ok ? res.texts || [] : [] }));
      });
  }

  function toggleGroup(id) {
    setExpanded((cur) => {
      if (cur[id] !== undefined) {
        const next = { ...cur };
        delete next[id];
        return next;
      }
      loadTexts(id);
      return { ...cur, [id]: "loading" };
    });
  }

  // After a mutation on a unit: refresh that group's texts (if open) + counts.
  function afterMutate(cid) {
    if (expanded[cid] !== undefined) {
      loadTexts(cid);
    }
    loadCollections();
  }

  // Empty-language state: same shell, centered CTA (wireframe 1.3).
  if (view === "dashboard_empty_language" && languages.length === 0) {
    return (
      <Page title="Translangs" fullWidth>
        <Card>
          <EmptyState
            heading="Add your first language"
            action={{
              content: "Create a language",
              onAction: () => openManager(null),
            }}
          >
            <p>
              Your site is ready. Create a language to start translating your
              content.
            </p>
            <p>Need more later? Add them from the sidebar anytime.</p>
          </EmptyState>
        </Card>
        <LanguageManagerModal
          open={managerOpen}
          languages={languages}
          defaultCode={defaultCode}
          initialCode={managerInitial}
          onClose={() => setManagerOpen(false)}
          onChanged={() => {
            refreshLanguages();
            onLanguageCreated();
          }}
        />
      </Page>
    );
  }

  const filtered = (collections || []).filter((c) => {
    if (!groupHeading(c).toLowerCase().includes(search.toLowerCase())) {
      return false;
    }
    if (statusFilter === "untranslated") {
      return c.missing > 0;
    }
    if (statusFilter === "needs_review") {
      return c.needs_review > 0;
    }
    if (statusFilter === "translated") {
      return c.translated > 0;
    }
    return true;
  });

  // Every row currently rendered, in table order — this is what the overlay's
  // prev/next arrows walk, so they cross group boundaries.
  const loadedRows = filtered.flatMap((c) =>
    Array.isArray(expanded[c.id])
      ? expanded[c.id].map((u) => ({
          unit: u,
          cid: c.id,
          fieldLabel: `${groupHeading(c)} — ${u.field}`,
        }))
      : []
  );
  // Resolve by id, not a stored index: a refetch replaces the unit objects.
  const overlayIndex = overlay
    ? loadedRows.findIndex((r) => r.unit.id === overlay.id)
    : -1;
  const overlayRow = overlayIndex >= 0 ? loadedRows[overlayIndex] : null;

  const totalTexts = (collections || []).reduce((n, c) => n + c.total, 0);
  const totalDone = (collections || []).reduce((n, c) => n + c.translated, 0);

  // Column headers show the actual language names: source (default) on the left,
  // the selected target on the right. Prefer an explicit display name; otherwise
  // turn the code into a readable name (en → English) so raw codes never show.
  const codeName = (code) => {
    if (!code) return null;
    try {
      return new Intl.DisplayNames(undefined, { type: "language" }).of(code);
    } catch {
      return code;
    }
  };
  const langLabel = (l) =>
    l && (l.display_name || l.name || codeName(l.code || l));
  const originalLabel =
    langLabel(defaultLanguage) || codeName(defaultCode) || "Original";
  const translationLabel =
    langLabel(languages.find((l) => l.code === selectedLang)) || "Translation";

  // Standard Polaris Filters config (status only, single-select).
  const filters = [
    {
      key: "status",
      label: "Status",
      filter: (
        <ChoiceList
          title="Status"
          titleHidden
          choices={FILTERS.map((f) => ({ label: f.label, value: f.key }))}
          selected={statusFilter === "all" ? [] : [statusFilter]}
          onChange={(vals) => setStatusFilter(vals[0] || "all")}
        />
      ),
      shortcut: true,
    },
  ];
  const appliedFilters =
    statusFilter === "all"
      ? []
      : [
          {
            key: "status",
            label: `Status: ${
              FILTERS.find((f) => f.key === statusFilter)?.label
            }`,
            onRemove: () => setStatusFilter("all"),
          },
        ];

  return (
    <Page fullWidth>
      {notice && (
        <Box paddingBlockEnd="300">
          <Banner tone={notice.tone} onDismiss={() => setNotice(null)}>
            {notice.content}
          </Banner>
        </Box>
      )}
      <InlineStack gap="200" align="start" blockAlign="start" wrap={false}>
        {/* ── Left rail ─────────────────────────────────────────── */}
        <div
          style={{
            flex: "0 0 260px",
            width: 260,
            alignSelf: "flex-start",
            position: "sticky",
            top: 32,
            marginBlockStart: 8,
            height: "calc(100vh - 100px)",
            display: "flex",
            flexDirection: "column",
          }}
        >
          <div
            style={{
              flex: 1,
              minHeight: 0,
              overflowY: "auto",
              paddingRight: 4,
            }}
          >
            <BlockStack gap="400">
              {/* Brand header — name + logo live here, not in the page header. */}
              <InlineStack
                align="space-between"
                blockAlign="center"
                wrap={false}
              >
                <InlineStack
                  gap="100"
                  blockAlign="center"
                  wrap={false}
                  style={{ paddingLeft: 4 }}
                >
                  {cfg.logo && (
                    <img
                      src={cfg.logo}
                      alt=""
                      width="24"
                      height="24"
                      className="block"
                    />
                  )}
                  <Text as="span" variant="headingMd">
                    Translangs
                  </Text>
                </InlineStack>
              </InlineStack>
              <BlockStack gap="200">
                <Card>
                  <BlockStack gap="200">
                    <Text as="h2" variant="headingSm">
                      Target language
                    </Text>
                    <LanguageList
                      languages={languages}
                      selectedCode={selectedLang}
                      aiAvailable={ai.available}
                      onSelect={(lang) => setSelectedLang(lang.code)}
                      onEdit={(lang) => openManager(lang.code)}
                      onToggleStatus={(lang) =>
                        patchLangStatus(
                          lang.code,
                          lang.enabled ? "draft" : "active",
                        )
                      }
                      onTranslateAll={(lang) => translateLanguageAll(lang)}
                      onDelete={(lang) => confirmDeleteLang(lang)}
                      onAdd={() => openManager(null)}
                    />
                  </BlockStack>
                </Card>

                <Card>
                  <BlockStack gap="100">
                    <Text as="h2" variant="headingSm">
                      Sources
                    </Text>
                    {/* ponytail: v1 has post + option only; per-source-type
								     totals land when the other 4 types are wired. */}
                    <InlineStack align="space-between">
                      <Text as="span" variant="bodySm">
                        Content
                      </Text>
                      <Text as="span" tone="subdued" variant="bodySm">
                        {totalTexts}
                      </Text>
                    </InlineStack>
                  </BlockStack>
                </Card>
              </BlockStack>
            </BlockStack>
          </div>
          <div className="pt-4">
            <QuotaCard quota={quota} aiAvailable={ai.available} />
          </div>
        </div>

        {/* ── Main: one card, sticky search header, scrolling body ─ */}
        <div
          style={{
            flex: 1,
            minWidth: 0,
            marginBlockStart: 8,
            minHeight: "calc(100vh - 100px)",
          }}
        >
          <div
            style={{
              minHeight: "calc(100vh - 100px)",
              background: "var(--p-color-bg-surface, #fff)",
              borderRadius: "var(--p-border-radius-300, 12px)",
              boxShadow: "var(--p-shadow-100, 0 1px 2px rgba(0,0,0,0.08))",
            }}
          >
            <div
              style={{
                position: "sticky",
                top: 32,
                zIndex: 2,
                background: "var(--p-color-bg-surface, #fff)",
                borderTopLeftRadius: "var(--p-border-radius-300, 12px)",
                borderTopRightRadius: "var(--p-border-radius-300, 12px)",
              }}
            >
              <Box padding="300">
                <BlockStack gap="200">
                  {/* Line 1: search (left) + AI · Configure (right) */}
                  <div
                    style={{
                      display: "flex",
                      alignItems: "center",
                      gap: 10,
                      minHeight: 32,
                    }}
                  >
                    <div className="flex-1 min-w-0">
                      <TextField
                        label="Search text"
                        labelHidden
                        placeholder="Search text…"
                        value={search}
                        onChange={setSearch}
                        autoComplete="off"
                        focused={showSearch}
                        clearButton
                        onClearButtonClick={() => setSearch("")}
                      />
                    </div>
                    <Button
                      icon={(props) => <StartIcon {...props} />}
                      variant="tertiary"
                      disabled={!ai.available}
                      accessibilityLabel="AI translate all"
                      size="medium"
                      onClick={() =>
                        setConfirm({
                          title: "AI translate all missing text?",
                          body: "This sends every untranslated text in the selected language to AI translation and may use a large number of words.",
                          actionLabel: "Translate all",
                          onConfirm: () => translateScope("language"),
                        })
                      }
                    />
                    <Button
                      icon={SettingsIcon}
                      variant="tertiary"
                      accessibilityLabel="Configure"
                      size="medium"
                      onClick={() => openManager(null)}
                    />
                  </div>
                  {/* Line 2: standard Polaris Filters (status) */}
                  <div className="tl-filters">
                    <Filters
                      hideQueryField
                      filters={filters}
                      appliedFilters={appliedFilters}
                      onQueryChange={() => {}}
                      onQueryClear={() => {}}
                      onClearAll={() => setStatusFilter("all")}
                    />
                  </div>
                </BlockStack>
              </Box>
            </div>
            {collections === null ? (
              <Box padding="500">
                <Spinner accessibilityLabel="Loading content" size="small" />
              </Box>
            ) : filtered.length === 0 ? (
              <Box padding="500">
                <Text as="p" tone="subdued">
                  No translatable content found for this language yet.
                </Text>
              </Box>
            ) : (
              <BlockStack gap="0">
                <Box paddingInlineStart="1000">
                  <Box paddingInline="300" paddingBlock="200">
                    <InlineStack
                      gap="300"
                      align="space-between"
                      blockAlign="center"
                      wrap={false}
                    >
                      <Box width="42%" minWidth="0">
                        <Text as="span" variant="bodySm" tone="subdued" fontWeight="medium">
                          {originalLabel}
                        </Text>
                      </Box>
                      <Box width="42%" minWidth="0">
                        <Text as="span" variant="bodySm" tone="subdued" fontWeight="medium">
                          {translationLabel}
                        </Text>
                      </Box>
                      <Box width="16%" />
                    </InlineStack>
                  </Box>
                </Box>
                <Divider />
                {filtered.map((c) => (
                  <CollectionGroup
                    key={c.id}
                    collection={c}
                    language={selectedLang}
                    open={expanded[c.id] !== undefined}
                    texts={
                      Array.isArray(expanded[c.id]) ? expanded[c.id] : null
                    }
                    loading={expanded[c.id] === "loading"}
                    onToggle={() => toggleGroup(c.id)}
                    onMutate={() => afterMutate(c.id)}
                    onConfirm={setConfirm}
                    aiAvailable={ai.available}
                    onAiTranslate={translateScope}
                    onOpenOverlay={(unit) => setOverlay({ id: unit.id })}
                  />
                ))}
              </BlockStack>
            )}

            {collections !== null && (
              <>
                <Divider />
                <Box padding="300">
                  <Text as="p" alignment="end" tone="subdued" variant="bodySm">
                    {totalDone} of {totalTexts} translated
                  </Text>
                </Box>
              </>
            )}
          </div>
        </div>
      </InlineStack>

      <LanguageManagerModal
        open={managerOpen}
        languages={languages}
        defaultCode={defaultCode}
        initialCode={managerInitial}
        onClose={() => setManagerOpen(false)}
        onChanged={() => {
          refreshLanguages();
          onLanguageCreated();
        }}
      />
      <SettingsModal
        open={settingsOpen}
        defaultLanguage={defaultCode}
        onClose={() => setSettingsOpen(false)}
      />
      <CompareOverlay
        open={!!overlayRow}
        unit={overlayRow?.unit}
        fieldLabel={overlayRow?.fieldLabel}
        language={selectedLang}
        languages={languages}
        sourceLabel={originalLabel}
        sourceFlag={flagEmoji(
          typeof defaultLanguage === "object" && defaultLanguage
            ? defaultLanguage
            : { code: defaultCode }
        )}
        targetDir={
          languages.find((l) => l.code === selectedLang)?.direction || "ltr"
        }
        aiAvailable={ai.available}
        onAiTranslate={translateScope}
        onPickLanguage={setSelectedLang}
        onClose={() => setOverlay(null)}
        onSaved={() => overlayRow && afterMutate(overlayRow.cid)}
        hasPrev={overlayIndex > 0}
        hasNext={overlayIndex >= 0 && overlayIndex < loadedRows.length - 1}
        onPrev={() => setOverlay({ id: loadedRows[overlayIndex - 1].unit.id })}
        onNext={() => setOverlay({ id: loadedRows[overlayIndex + 1].unit.id })}
      />
      <ConfirmDialog confirm={confirm} onClose={() => setConfirm(null)} />
    </Page>
  );
}

function ConfirmDialog({ confirm, onClose }) {
  if (!confirm) {
    return null;
  }
  return (
    <Modal
      open
      onClose={onClose}
      title={confirm.title}
      primaryAction={{
        content: confirm.actionLabel,
        destructive: confirm.destructive,
        onAction: () => {
          confirm.onConfirm();
          onClose();
        },
      }}
      secondaryActions={[{ content: "Cancel", onAction: onClose }]}
    >
      <Modal.Section>
        <Text as="p">{confirm.body}</Text>
      </Modal.Section>
    </Modal>
  );
}

function CollectionGroup({
  collection,
  language,
  open,
  texts,
  loading,
  onToggle,
  onMutate,
  onConfirm,
  onOpenOverlay,
  aiAvailable,
  onAiTranslate,
}) {
  const c = collection;
  const [menuOpen, setMenuOpen] = useState(false);
  const [hovered, setHovered] = useState(false);

  function confirmAiGroup() {
    onConfirm({
      title: "AI translate this group?",
      body: "Untranslated text in this group will be sent to AI translation.",
      actionLabel: "Translate",
      onConfirm: () => onAiTranslate("collection", { id: c.id }),
    });
  }

  async function bulk(action) {
    setMenuOpen(false);
    const res = await api.post(`/collections/${enc(c.id)}/${action}`, {
      language,
    });
    if (res.ok) {
      onMutate();
    }
  }

  function confirmIgnoreAll() {
    onConfirm({
      title: "Ignore all texts in this group?",
      body: "Every text in this group will be excluded from translation and moved to the Ignored view.",
      actionLabel: "Ignore all",
      // destructive: true,
      onConfirm: () => bulk("ignore"),
    });
  }

  function confirmClearAll() {
    onConfirm({
      title: "Clear all translations in this group?",
      body: "Every translation in this group for the selected language will be removed.",
      actionLabel: "Clear all",
      // destructive: true,
      onConfirm: () => bulk("clear"),
    });
  }

  return (
    <Box>
      <div
        onMouseEnter={() => setHovered(true)}
        onMouseLeave={() => setHovered(false)}
      >
      <Box padding="300" background={open ? "bg-surface-secondary" : undefined}>
        <InlineStack align="space-between" blockAlign="center" wrap={false}>
          <button
            type="button"
            onClick={onToggle}
            style={{
              all: "unset",
              cursor: "pointer",
              flex: 1,
              display: "flex",
              alignItems: "center",
              gap: "8px",
            }}
          >
            <Text as="span" variant="bodyMd" fontWeight="medium">
              {open ? "▾" : "▸"}
            </Text>
            <span
              style={{
                display: "inline-flex",
                flexShrink: 0,
                width: 16,
                height: 16,
                color: "var(--p-color-icon-subdued)",
              }}
            >
              <PageIcon fill="currentColor" width={16} height={16} />
            </span>
            <Text as="span" variant="bodyMd" fontWeight="medium">
              {groupHeading(c)}
            </Text>
          </button>
          <div
            style={{
              opacity: hovered || menuOpen ? 1 : 0,
              visibility: hovered || menuOpen ? "visible" : "hidden",
              transition: "opacity 120ms ease",
            }}
          >
          <InlineStack gap="100" blockAlign="center" wrap={false}>
            <Button
              variant="tertiary"
              size="micro"
              disabled={!aiAvailable}
              icon={(props) => <StartIcon {...props} />}
              onClick={confirmAiGroup}
            >
              Translate all
            </Button>
            <Popover
              active={menuOpen}
              onClose={() => setMenuOpen(false)}
              activator={
                <Button
                  variant="tertiary"
                  size="micro"
                  onClick={() => setMenuOpen((o) => !o)}
                >
                  ⋯
                </Button>
              }
            >
              <ActionList
                items={[
                  {
                    content: "Ignore all",
                    destructive: true,
                    onAction: confirmIgnoreAll,
                  },
                  {
                    content: "Clear all",
                    destructive: true,
                    onAction: confirmClearAll,
                  },
                ]}
              />
            </Popover>
          </InlineStack>
          </div>
        </InlineStack>
      </Box>
      </div>
      <Collapsible open={open} id={`col-${c.id}`}>
        <Box paddingBlockEnd="200" paddingInlineStart="1000">
          {loading || texts === null ? (
            <Box padding="300">
              <Spinner accessibilityLabel="Loading texts" size="small" />
            </Box>
          ) : (
            texts.map((t) => (
              <TextUnitRow
                key={t.id}
                unit={t}
                language={language}
                onMutate={onMutate}
                onConfirm={onConfirm}
                aiAvailable={aiAvailable}
                onAiTranslate={onAiTranslate}
                onOpenOverlay={onOpenOverlay}
              />
            ))
          )}
        </Box>
      </Collapsible>
      <Divider />
    </Box>
  );
}

function TextUnitRow({
  unit,
  language,
  onMutate,
  onConfirm,
  onOpenOverlay,
  aiAvailable,
  onAiTranslate,
}) {
  const [draft, setDraft] = useState(unit.translated ?? "");
  const [menuOpen, setMenuOpen] = useState(false);
  const [hovered, setHovered] = useState(false);

  const badge = STATUS_BADGE[unit.status];
  const shown = unit.translated ?? unit.fallback;
  const hasTranslation =
    unit.translated !== null && unit.translated !== undefined;

  // "Long" = the original doesn't fit on one line in its cell at the current
  // width. Measured, so it adapts to screen/column size instead of a fixed
  // character count. Long text edits in the overlay; short text edits inline.
  // A composed unit carries codec markers ({1}…{/1}); it must edit in the
  // overlay's chip editor, never the plain inline input.
  const isComposed = hasMarkers(unit.original || "");
  const originalText = isComposed ? stripMarkers(unit.original) : unit.original;
  const shownText = isComposed ? stripMarkers(shown) : shown;
  const originalRef = useRef(null);
  const [isLong, setIsLong] = useState(false);
  useLayoutEffect(() => {
    const el = originalRef.current;
    if (!el) {
      return;
    }
    const check = () => setIsLong(el.scrollWidth > el.clientWidth + 1);
    check();
    const ro = new ResizeObserver(check);
    ro.observe(el);
    return () => ro.disconnect();
  }, [unit.original]);

  // Only long or structured (rich-text) source opens the overlay; short plain
  // text is fully visible and edits inline, so its cell stays non-clickable.
  const clickable = isLong || isComposed;

  // Keep the inline draft in sync when the translation changes elsewhere
  // (AI translate, overlay save).
  useEffect(() => {
    setDraft(unit.translated ?? "");
  }, [unit.translated]);

  // Autosave the inline field on blur — only when the value actually changed.
  async function saveTranslation() {
    if ((unit.translated ?? "") === draft) {
      return;
    }
    const res = await api.post("/translations", {
      text_unit_id: unit.id,
      language,
      text: draft,
    });
    if (res.ok) {
      onMutate();
    }
  }

  async function doStatus(action) {
    setMenuOpen(false);
    const res = await api.post(`/texts/${enc(unit.id)}/status`, {
      action,
      language,
    });
    if (res.ok) {
      onMutate();
    }
  }

  function confirmClear() {
    onConfirm({
      title: "Clear translation?",
      body: "This removes the translation for this text in the selected language.",
      actionLabel: "Clear",
      destructive: true,
      onConfirm: () => doStatus("clear"),
    });
  }

  function confirmIgnore() {
    onConfirm({
      title: "Ignore this text?",
      body: "Ignored text is excluded from translation and moves to the Ignored view.",
      actionLabel: "Ignore",
      destructive: true,
      onConfirm: () => doStatus("ignore"),
    });
  }

  const menuItems = [
    aiAvailable && {
      content: "AI translate",
      onAction: () => {
        setMenuOpen(false);
        onAiTranslate("text", { ref: unit.id });
      },
    },
    {
      content: "Open in overlay",
      onAction: () => {
        setMenuOpen(false);
        onOpenOverlay(unit);
      },
    },
    unit.preview_url && {
      content: unit.preview_highlight ? "Preview on website" : "Edit in WordPress",
      onAction: () => {
        setMenuOpen(false);
        const snippet = stripMarkers(unit.translated ?? unit.original ?? "");
        const url =
          unit.preview_highlight && snippet
            ? `${unit.preview_url}#:~:text=${encodeURIComponent(snippet)}`
            : unit.preview_url;
        window.open(url, "_blank", "noopener");
      },
    },
    hasTranslation && {
      content: "To review",
      onAction: () => doStatus("review"),
    },
    hasTranslation && {
      content: "Clear",
      destructive: true,
      onAction: () => {
        setMenuOpen(false);
        confirmClear();
      },
    },
    {
      content: "Ignore",
      destructive: true,
      onAction: () => {
        setMenuOpen(false);
        confirmIgnore();
      },
    },
  ].filter(Boolean);

  return (
    <div
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => setHovered(false)}
    >
    <Box paddingInline="300" paddingBlock="200">
      <InlineStack
        gap="300"
        align="space-between"
        blockAlign="center"
        wrap={false}
      >
        <Box width="42%" minWidth="0">
          <button
            ref={originalRef}
            type="button"
            onClick={clickable ? () => onOpenOverlay(unit) : undefined}
            disabled={!clickable}
            style={{ ...oneLineStyle, cursor: clickable ? "pointer" : "default" }}
          >
            <Text as="span" variant="bodySm">
              {originalText}
            </Text>
          </button>
        </Box>

        <Box width="42%" minWidth="0">
          {clickable ? (
            <button
              type="button"
              onClick={() => onOpenOverlay(unit)}
              style={oneLineStyle}
            >
              <Text
                as="span"
                variant="bodySm"
                tone={hasTranslation ? undefined : "subdued"}
              >
                {shownText}
              </Text>
            </button>
          ) : (
            <input
              type="text"
              aria-label="Translation"
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              onBlur={saveTranslation}
              placeholder={unit.fallback || ""}
              style={{
                all: "unset",
                display: "block",
                width: "100%",
                minWidth: 0,
                cursor: "text",
                fontSize: "var(--p-font-size-300, 0.75rem)",
                lineHeight: "var(--p-font-line-height-300, 1rem)",
                color: hasTranslation
                  ? "var(--p-color-text)"
                  : "var(--p-color-text-subdued)",
              }}
            />
          )}
        </Box>

        <Box width="16%">
          <InlineStack gap="100" align="end" blockAlign="center" wrap={false}>
            {isComposed && (hovered || menuOpen) && (
              <Tooltip content="This text contains inline formatting (links, bold, etc.). Open it to edit — the formatting is kept for you.">
                <Badge tone="info" size="small">
                  Rich text
                </Badge>
              </Tooltip>
            )}
            {unit.is_ai && <Badge size="small">AI</Badge>}
            {badge && (
              <Badge tone={badge.tone} size="small">
                {badge.label}
              </Badge>
            )}
            <div
              style={{
                opacity: hovered || menuOpen ? 1 : 0,
                visibility: hovered || menuOpen ? "visible" : "hidden",
                transition: "opacity 120ms ease",
              }}
            >
              <Popover
                active={menuOpen}
                onClose={() => setMenuOpen(false)}
                activator={
                  <Button
                    variant="tertiary"
                    size="micro"
                    onClick={() => setMenuOpen((o) => !o)}
                  >
                    ⋯
                  </Button>
                }
              >
                <ActionList items={menuItems} />
              </Popover>
            </div>
          </InlineStack>
        </Box>
      </InlineStack>
    </Box>
    </div>
  );
}
