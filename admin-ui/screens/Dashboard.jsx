import {
  ActionList,
  Badge,
  Banner,
  BlockStack,
  Box,
  Button,
  Card,
  Collapsible,
  Divider,
  EmptyState,
  InlineStack,
  Modal,
  Page,
  Popover,
  Spinner,
  Text,
  TextField,
} from "@shopify/polaris";
import { useEffect, useState } from "@wordpress/element";
import {
  MagicIcon,
  SettingsIcon,
  FilterIcon,
  SearchIcon,
  LanguageIcon,
} from "@shopify/polaris-icons";
import { api } from "../api";
import SettingsModal from "./SettingsModal";
import LanguageManagerModal from "./LanguageManagerModal";
import LanguageList from "./LanguageList";
import CompareOverlay from "./CompareOverlay";
import QuotaCard from "./QuotaCard";

const StartIcon = ({
	color = "currentColor",
}) => (
  <svg
    width="24"
    height="24"
    viewBox="0 0 24 24"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
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
  missing: { tone: "attention", label: "New" },
  needs_review: { tone: "warning", label: "Needs review" },
  reviewed: { tone: "success", label: "Reviewed" },
  translated: null,
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
  const [filterOpen, setFilterOpen] = useState(false);
  const [statusFilter, setStatusFilter] = useState("all");

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

  // Reload collections + reset expansion whenever the working language changes.
  useEffect(() => {
    if (!selectedLang) {
      return;
    }
    setCollections(null);
    setExpanded({});
    loadCollections();
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
  const totalTexts = (collections || []).reduce((n, c) => n + c.total, 0);
  const totalDone = (collections || []).reduce((n, c) => n + c.translated, 0);

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
            <BlockStack gap="200">
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
                      style={{ display: "block" }}
                    />
                  )}
                  <Text as="span" variant="headingMd">
                    Translangs
                  </Text>
                </InlineStack>
              </InlineStack>
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
                      patchLangStatus(lang.code, lang.enabled ? "draft" : "active")
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
          </div>
          <div style={{ paddingTop: 16 }}>
            <QuotaCard quota={quota} aiAvailable={ai.available} />
          </div>
        </div>

        {/* ── Main: one card, sticky search header, scrolling body ─ */}
        <div
          style={{
            flex: 1,
            minWidth: 0,
            marginBlockStart: 8,
            height: "calc(100vh - 100px)",
            overflowY: "auto",
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
                top: 0,
                zIndex: 2,
                background: "var(--p-color-bg-surface, #fff)",
              }}
            >
              <Box padding="300">
                <div
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 4,
                    height: 32,
                  }}
                >
                  {/* Search on the left */}
                  <Button
                    icon={SearchIcon}
                    variant="tertiary"
                    pressed={showSearch}
                    accessibilityLabel="Search"
                    onClick={() => setShowSearch((s) => !s)}
                  />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    {showSearch && (
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
                    )}
                  </div>
                  {/* Right: AI · Filter · Configure */}
                  <Button
                    icon={(props) => <StartIcon {...props} />}
                    variant="tertiary"
                    disabled={!ai.available}
                    accessibilityLabel="AI translate all"
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
                    icon={FilterIcon}
                    variant="tertiary"
                    pressed={statusFilter !== "all"}
                    accessibilityLabel="Filter"
                    onClick={() => setFilterOpen(true)}
                  />
                  <Button
                    icon={LanguageIcon}
                    variant="tertiary"
                    accessibilityLabel="Configure"
                    onClick={() => openManager(null)}
                  />
                </div>
              </Box>
            </div>
            <Divider />
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
                    onOpenOverlay={(unit) =>
                      setOverlay({
                        unit,
                        cid: c.id,
                        fieldLabel: `${groupHeading(c)} — ${unit.field}`,
                      })
                    }
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
        open={!!overlay}
        unit={overlay?.unit}
        fieldLabel={overlay?.fieldLabel}
        language={selectedLang}
        aiAvailable={ai.available}
        onAiTranslate={translateScope}
        onClose={() => setOverlay(null)}
        onSaved={() => overlay && afterMutate(overlay.cid)}
      />
      <ConfirmDialog confirm={confirm} onClose={() => setConfirm(null)} />
      <Modal
        open={filterOpen}
        onClose={() => setFilterOpen(false)}
        title="Filter"
        size="small"
      >
        <Modal.Section>
          <InlineStack gap="200" blockAlign="center" wrap={false}>
            <Button
              pressed={statusFilter === "all"}
              onClick={() => {
                setStatusFilter("all");
                setFilterOpen(false);
              }}
            >
              All
            </Button>
            {FILTERS.map((f) => (
              <Button
                key={f.key}
                pressed={statusFilter === f.key}
                onClick={() => {
                  setStatusFilter(f.key);
                  setFilterOpen(false);
                }}
              >
                {f.label}
              </Button>
            ))}
          </InlineStack>
        </Modal.Section>
      </Modal>
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
      destructive: true,
      onConfirm: () => bulk("ignore"),
    });
  }

  function confirmClearAll() {
    onConfirm({
      title: "Clear all translations in this group?",
      body: "Every translation in this group for the selected language will be removed.",
      actionLabel: "Clear all",
      destructive: true,
      onConfirm: () => bulk("clear"),
    });
  }

  return (
    <Box>
      <Box padding="300" background={open ? "bg-surface-secondary" : undefined}>
        <InlineStack align="space-between" blockAlign="center" wrap={false}>
          <button
            type="button"
            onClick={onToggle}
            style={{ all: "unset", cursor: "pointer", flex: 1 }}
          >
            <Text as="span" variant="bodyMd" fontWeight="medium">
              {(open ? "▾ " : "▸ ") + groupHeading(c)}
            </Text>
          </button>
          <InlineStack gap="200" blockAlign="center" wrap={false}>
            <Text as="span" tone="subdued" variant="bodySm">
              {c.total} texts · {c.translated} translated
            </Text>
            <Button
              size="micro"
              disabled={!aiAvailable}
              onClick={confirmAiGroup}
            >
              ⚡ Translate all
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
        </InlineStack>
      </Box>
      <Collapsible open={open} id={`col-${c.id}`}>
        <Box paddingBlockEnd="200">
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
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState("");
  const [saving, setSaving] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [copyOpen, setCopyOpen] = useState(false);

  const badge = STATUS_BADGE[unit.status];
  const shown = unit.translated ?? unit.fallback;
  const hasTranslation =
    unit.translated !== null && unit.translated !== undefined;

  function startEdit() {
    setDraft(unit.translated ?? "");
    setEditing(true);
  }

  async function save() {
    setSaving(true);
    const res = await api.post("/translations", {
      text_unit_id: unit.id,
      language,
      text: draft,
    });
    setSaving(false);
    if (res.ok) {
      setEditing(false);
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

  function copyOriginal() {
    setCopyOpen(false);
    if (navigator.clipboard) {
      navigator.clipboard.writeText(unit.original);
    }
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
    <Box paddingInline="300" paddingBlock="200">
      <InlineStack
        gap="300"
        align="space-between"
        blockAlign="start"
        wrap={false}
      >
        <Box width="42%">
          <Popover
            active={copyOpen}
            onClose={() => setCopyOpen(false)}
            activator={
              <button
                type="button"
                onClick={() => setCopyOpen(true)}
                style={{ all: "unset", cursor: "pointer", display: "block" }}
              >
                <Text as="span" variant="bodySm">
                  {unit.original}
                </Text>
              </button>
            }
          >
            <ActionList items={[{ content: "Copy", onAction: copyOriginal }]} />
          </Popover>
        </Box>

        <Box width="42%">
          {editing ? (
            <BlockStack gap="100">
              <TextField
                label="Translation"
                labelHidden
                multiline={2}
                value={draft}
                onChange={setDraft}
                autoComplete="off"
              />
              <InlineStack gap="100">
                <Button
                  size="micro"
                  variant="primary"
                  loading={saving}
                  onClick={save}
                >
                  Save
                </Button>
                <Button size="micro" onClick={() => setEditing(false)}>
                  Cancel
                </Button>
              </InlineStack>
            </BlockStack>
          ) : (
            <button
              type="button"
              onClick={startEdit}
              style={{
                all: "unset",
                cursor: "text",
                display: "block",
                width: "100%",
              }}
            >
              <Text
                as="span"
                variant="bodySm"
                tone={hasTranslation ? undefined : "subdued"}
              >
                {shown}
              </Text>
            </button>
          )}
        </Box>

        <Box width="16%">
          <InlineStack gap="100" align="end" blockAlign="center" wrap={false}>
            {unit.is_ai && <Badge size="small">AI</Badge>}
            {badge && (
              <Badge tone={badge.tone} size="small">
                {badge.label}
              </Badge>
            )}
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
          </InlineStack>
        </Box>
      </InlineStack>
    </Box>
  );
}
