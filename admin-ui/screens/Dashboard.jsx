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
import { useInfiniteQuery, useQuery, useQueryClient } from "@tanstack/react-query";
import { useWindowVirtualizer } from "@tanstack/react-virtual";
import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
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
import ExportImportModal from "./ExportImportModal";
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

// Open a text's destination in a new tab. Front-end pages get a scroll-to-text
// fragment so the browser jumps to (and highlights) the string being verified;
// admin editors (taxonomy/options) open as-is. Best-effort: a no-match snippet
// just lands on the page.
function openCanvas(url, target, snippet) {
  if (!url) return;
  const final =
    target === "frontend" && snippet ? `${url}#:~:text=${enc(snippet)}` : url;
  window.open(final, "_blank", "noopener");
}

// Header status filters — sent as ?status= to the server now.
const FILTERS = [
  { key: "untranslated", label: "Untranslated" },
  { key: "needs_review", label: "Needs review" },
  { key: "translated", label: "Translated" },
];

// Search runs server-side (?search= on /collections and /collections/{id}/texts),
// matching source text OR its translation in the selected language.
// 300ms because each keystroke refetches every group's counts.
const SEARCH_DEBOUNCE_MS = 300;
// One letter matches almost everything; below this the box is inert.
const SEARCH_MIN = 2;
// Collections page size (matches CollectionRestController's DEFAULT_LIMIT).
const PAGE_SIZE = 20;
// Rows revealed per "Show more" click in a group — an auto-opened 1,000-text
// group never dumps every row at once.
const TEXT_BATCH = 50;

export default function Dashboard({
  view,
  defaultLanguage,
  onLanguageCreated,
}) {
  const queryClient = useQueryClient();
  const [languages, setLanguages] = useState([]);
  const [selectedLang, setSelectedLang] = useState("");
  const [search, setSearch] = useState("");
  // The debounced term actually sent to the server ("" = not searching).
  const [query, setQuery] = useState("");
  // { id, cid } of the text unit open in the compare overlay, or null.
  const [overlay, setOverlay] = useState(null);
  const [managerOpen, setManagerOpen] = useState(false);
  const [managerInitial, setManagerInitial] = useState(null);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [exportImportOpen, setExportImportOpen] = useState(false);
  const [pageMenuOpen, setPageMenuOpen] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [confirm, setConfirm] = useState(null);
  const [ai, setAi] = useState({ available: false, error: "" });
  const [quota, setQuota] = useState(null);
  const [notice, setNotice] = useState(null);
  const [autosaveToast, setAutosaveToast] = useState(false);
  const [statusFilter, setStatusFilter] = useState("all");

  const autosaveToastTimerRef = useRef(null);

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

  useEffect(() => {
    function showAutosaveToast() {
      setAutosaveToast(true);
      clearTimeout(autosaveToastTimerRef.current);
      autosaveToastTimerRef.current = setTimeout(() => {
        setAutosaveToast(false);
      }, 3000);
    }

    function handleShortcut(event) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "s") {
        event.preventDefault();
        showAutosaveToast();
      }
    }

    window.addEventListener("keydown", handleShortcut);
    return () => {
      window.removeEventListener("keydown", handleShortcut);
      clearTimeout(autosaveToastTimerRef.current);
    };
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
    if (res.collection_summary) {
      patchCollectionSummary(res.collection_summary);
    } else {
      invalidateCollections();
    }
    if (res.updated_unit) {
      // scope 'text' resolves to one unit; propagate it to any other visible
      // occurrence of the same text (its own group is invalidated below anyway).
      propagateTranslation(opts.collection_id ?? opts.id, res.updated_unit);
    }
    // Every mounted group's texts for this language may have changed (a scope
    // or language-wide AI translate isn't limited to one group).
    queryClient.invalidateQueries({
      predicate: (q) => q.queryKey[0] === "texts" && q.queryKey[2] === selectedLang,
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

  // Debounce the box into the term we actually query with. Below SEARCH_MIN the term
  // counts as empty, so backspacing to one letter restores the full tree.
  useEffect(() => {
    const q = search.trim();
    const id = setTimeout(
      () => setQuery(q.length < SEARCH_MIN ? "" : q),
      SEARCH_DEBOUNCE_MS
    );
    return () => clearTimeout(id);
  }, [search]);

  const searchParam = (q) => (q ? `&search=${enc(q)}` : "");

  // Collections, paged. Language/search/status/limit live in the key, so any
  // change refetches from page 0 automatically — no manual reset effect needed.
  const groupsQ = useInfiniteQuery({
    queryKey: [
      "collections",
      { language: selectedLang, search: query, status: statusFilter, limit: PAGE_SIZE },
    ],
    initialPageParam: 0,
    queryFn: async ({ pageParam, signal }) => {
      const res = await api.get(
        `/collections?language=${enc(selectedLang)}&offset=${pageParam}&limit=${PAGE_SIZE}` +
          `${searchParam(query)}&status=${enc(statusFilter)}`,
        { signal }
      );
      if (!res.ok) {
        throw new Error(res.error || "ERR_UNREACHABLE");
      }
      return res;
    },
    getNextPageParam: (last) => (last.has_more ? last.next_offset : undefined),
    enabled: !!selectedLang,
  });

  function invalidateCollections() {
    queryClient.invalidateQueries({
      predicate: (q) =>
        q.queryKey[0] === "collections" && q.queryKey[1]?.language === selectedLang,
    });
  }

  // Manual "recheck the site for missing text": re-arm the same scan that runs
  // on activation, then poll status the same way SettingUp.jsx does during
  // onboarding. On completion, refetch the board so newly discovered text
  // shows up without a full page reload.
  function refreshScan() {
    if (refreshing) {
      return;
    }
    setRefreshing(true);
    setNotice({ tone: "info", content: "Rescanning your site…" });
    api.post("/scan/refresh").then(pollScanStatus);
  }

  function pollScanStatus() {
    api.get("/scan/status").then((res) => {
      const status = res.status || "running";
      if (status === "running") {
        setTimeout(pollScanStatus, 3000);
        return;
      }
      setRefreshing(false);
      if (status === "completed") {
        setNotice({ tone: "success", content: "Scan complete." });
        invalidateCollections();
      } else {
        setNotice({
          tone: "critical",
          content: "Scan could not finish. Please try again.",
        });
      }
    });
  }

  // A mutation response that resolved to one collection's fresh counts
  // (`collection_summary`) patches the cached board directly — no refetch
  // round-trip. Also keeps the header total_texts/total_translated in step
  // (derives their delta from the one collection's old vs. new numbers), and
  // drops the row if it no longer matches the active status filter (mirrors
  // what a real refetch would do server-side).
  function patchCollectionSummary(summary) {
    const stillMatchesFilter =
      statusFilter === "all" ||
      (statusFilter === "untranslated" && summary.missing > 0) ||
      (statusFilter === "needs_review" && summary.needs_review > 0) ||
      (statusFilter === "translated" && summary.translated > 0);

    queryClient.setQueriesData(
      {
        predicate: (q) =>
          q.queryKey[0] === "collections" && q.queryKey[1]?.language === selectedLang,
      },
      (old) => {
        if (!old) {
          return old;
        }
        let deltaTotal = 0;
        let deltaTranslated = 0;
        let found = false;
        const pages = old.pages.map((p) => {
          if (!p.collections.some((c) => c.id === summary.id)) {
            return p;
          }
          found = true;
          const collections = p.collections.flatMap((c) => {
            if (c.id !== summary.id) {
              return [c];
            }
            deltaTotal = summary.total - c.total;
            deltaTranslated = summary.translated - c.translated;
            return stillMatchesFilter ? [{ ...c, ...summary }] : [];
          });
          return { ...p, collections };
        });
        if (!found) {
          return old;
        }
        return {
          ...old,
          pages: pages.map((p) => ({
            ...p,
            total_texts: p.total_texts + deltaTotal,
            total_translated: p.total_translated + deltaTranslated,
          })),
        };
      }
    );
  }

  // Hash-link kinds (post/term/html/option) share one translation row across
  // every position with the same source text — a save in one group doesn't
  // magically update another, already-cached group showing the same text.
  // Patch every OTHER cached group's matching row (by original_hash) directly,
  // and recompute that group's summary from the patched array. Skips the
  // group just edited — that one already gets its normal targeted invalidate.
  function propagateTranslation(editedCid, updatedUnit) {
    const hash = updatedUnit?.original_hash;
    if (!hash) {
      return; // meta / unlinked kinds never share a translation
    }

    const entries = queryClient.getQueriesData({
      predicate: (q) =>
        q.queryKey[0] === "texts" && q.queryKey[2] === selectedLang && q.queryKey[1] !== editedCid,
    });

    for (const [key, data] of entries) {
      const texts = data?.texts;
      if (!Array.isArray(texts)) {
        continue;
      }
      let changed = false;
      const nextTexts = texts.map((t) => {
        if (t.original_hash !== hash) {
          return t;
        }
        changed = true;
        return {
          ...t,
          translated: updatedUnit.translated,
          status: updatedUnit.status,
          origin: updatedUnit.origin,
          is_ai: updatedUnit.is_ai,
          word_count: updatedUnit.word_count,
        };
      });
      if (!changed) {
        continue;
      }
      queryClient.setQueryData(key, { ...data, texts: nextTexts });

      const cid = key[1];
      const summary = { id: cid, total: nextTexts.length, translated: 0, needs_review: 0, missing: 0 };
      for (const t of nextTexts) {
        if (t.status === "missing") {
          summary.missing++;
        } else if (t.status === "needs_review") {
          summary.needs_review++;
        } else {
          summary.translated++;
        }
      }
      patchCollectionSummary(summary);
    }
  }

  // Offset paging can repeat a row across pages (mid-scroll inserts/removals);
  // first occurrence wins, which preserves page order.
  const groups = useMemo(() => {
    const seen = new Set();
    const result = [];
    for (const p of groupsQ.data?.pages ?? []) {
      for (const g of p.collections ?? []) {
        if (seen.has(g.id)) continue;
        seen.add(g.id);
        result.push(g);
      }
    }
    return result;
  }, [groupsQ.data]);

  // Header progress badge — the whole matching set's totals, from the most
  // recently fetched page (every page carries the same whole-set totals).
  const pages = groupsQ.data?.pages ?? [];
  const lastPage = pages[pages.length - 1];
  const totalTexts = lastPage?.total_texts ?? 0;
  const totalDone = lastPage?.total_translated ?? 0;
  const totalGroups = lastPage?.total ?? groups.length;

  // Every group is open by default; a manual collapse is remembered here and
  // reset when the working language changes (mirrors the old per-language reset).
  const [collapsedIds, setCollapsedIds] = useState(() => new Set());
  useEffect(() => {
    setCollapsedIds(new Set());
  }, [selectedLang]);
  const toggleCollapsed = useCallback((id) => {
    setCollapsedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }, []);

  // Window-scroll virtualization: only near-view group rows mount. One extra
  // "sentinel" row (count+1) carries the load-more/retry control when more
  // pages exist. Scroll margin is measured (not assumed), so content above the
  // list (notices, sticky header) doesn't misalign rows.
  const listRef = useRef(null);
  const [scrollMargin, setScrollMargin] = useState(0);
  useLayoutEffect(() => {
    const update = () => setScrollMargin(listRef.current?.offsetTop ?? 0);
    update();
    window.addEventListener("resize", update);
    return () => window.removeEventListener("resize", update);
  }, []);
  const getItemKey = useCallback(
    (i) => (i < groups.length ? groups[i].id : "__sentinel__"),
    [groups]
  );
  const v = useWindowVirtualizer({
    count: groupsQ.hasNextPage ? groups.length + 1 : groups.length,
    estimateSize: () => 320,
    overscan: 3,
    getItemKey,
    scrollMargin,
  });
  const virtualItems = v.getVirtualItems();

  // Fetch the next page once the sentinel/last row nears the viewport.
  useEffect(() => {
    const last = virtualItems[virtualItems.length - 1];
    if (!last) {
      return;
    }
    if (
      last.index >= groups.length - 3 &&
      groupsQ.hasNextPage &&
      !groupsQ.isFetchingNextPage &&
      !groupsQ.isRefetching
    ) {
      groupsQ.fetchNextPage();
    }
  }, [
    virtualItems,
    groups.length,
    groupsQ.hasNextPage,
    groupsQ.isFetchingNextPage,
    groupsQ.isRefetching,
  ]);

  // The overlay reads its group's texts from the same cache key each
  // CollectionGroup uses, so opening a neighbor group is just a query-key
  // change (shares cache with that group's own row if it's mounted).
  const overlayTextsQ = useQuery({
    queryKey: ["texts", overlay?.cid, selectedLang, query],
    queryFn: async ({ signal }) => {
      const res = await api.get(
        `/collections/${enc(overlay.cid)}/texts?language=${enc(selectedLang)}${searchParam(query)}`,
        { signal }
      );
      if (!res.ok) {
        throw new Error(res.error || "ERR_UNREACHABLE");
      }
      return res;
    },
    enabled: !!overlay,
  });
  const overlayTexts = overlayTextsQ.data?.texts ?? [];
  const overlayIndex = overlay
    ? overlayTexts.findIndex((t) => t.id === overlay.id)
    : -1;
  const overlayUnit = overlayIndex >= 0 ? overlayTexts[overlayIndex] : null;

  // Cross-group prev/next: every matching group's text-id order, fetched only
  // while the overlay is open (not part of the paginated board itself).
  const orderQ = useQuery({
    queryKey: ["text-order", selectedLang, query, statusFilter],
    queryFn: async ({ signal }) => {
      const res = await api.get(
        `/collections/text-order?language=${enc(selectedLang)}${searchParam(query)}&status=${enc(statusFilter)}`,
        { signal }
      );
      if (!res.ok) {
        throw new Error(res.error || "ERR_UNREACHABLE");
      }
      return res;
    },
    enabled: !!overlay,
  });
  const orderFlat = useMemo(
    () =>
      (orderQ.data?.groups ?? []).flatMap((g) =>
        g.ids.map((id) => ({ id, cid: g.cid, label: g.label, type: g.type }))
      ),
    [orderQ.data]
  );
  const globalIndex = overlay
    ? orderFlat.findIndex((o) => o.id === overlay.id)
    : -1;
  const overlayOrderEntry = globalIndex >= 0 ? orderFlat[globalIndex] : null;
  // Prefer the order response's group label (works even for a neighbor group
  // the board hasn't paginated its way to yet); fall back to the loaded board
  // list for the brief window before the order list has resolved.
  const overlayCollection =
    overlayOrderEntry ??
    (overlay ? groups.find((g) => g.id === overlay.cid) : null);
  const overlayFieldLabel =
    overlayUnit && overlayCollection
      ? `${groupHeading(overlayCollection)} — ${overlayUnit.field}`
      : undefined;

  // After a mutation on a unit: invalidate that group's texts (any cached
  // search variant); patch the collections cache directly if the mutation
  // returned a fresh summary, otherwise fall back to invalidating it; and
  // propagate the new value to any other visible occurrence of the same text.
  function afterMutate(cid, patch) {
    queryClient.invalidateQueries({ queryKey: ["texts", cid, selectedLang] });
    if (patch?.collection_summary) {
      patchCollectionSummary(patch.collection_summary);
    } else {
      invalidateCollections();
    }
    if (patch?.updated_unit) {
      propagateTranslation(cid, patch.updated_unit);
    }
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

  const searching = query !== "";

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
                    <Popover
                      active={pageMenuOpen}
                      onClose={() => setPageMenuOpen(false)}
                      activator={
                        <Button
                          variant="tertiary"
                          size="medium"
                          accessibilityLabel="More actions"
                          onClick={() => setPageMenuOpen((o) => !o)}
                        >
                          ⋯
                        </Button>
                      }
                    >
                      <ActionList
                        items={[
                          {
                            content: "Settings",
                            onAction: () => {
                              setPageMenuOpen(false);
                              setSettingsOpen(true);
                            },
                          },
                          {
                            content: "Export / Import",
                            onAction: () => {
                              setPageMenuOpen(false);
                              setExportImportOpen(true);
                            },
                          },
                          {
                            content: "Refresh",
                            disabled: refreshing,
                            onAction: () => {
                              setPageMenuOpen(false);
                              refreshScan();
                            },
                          },
                        ]}
                      />
                    </Popover>
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
            {groupsQ.isPending ? (
              <Box padding="500">
                <Spinner accessibilityLabel="Loading content" size="small" />
              </Box>
            ) : groupsQ.isError && groups.length === 0 ? (
              <Box padding="500">
                <BlockStack gap="200">
                  <Text as="p" tone="critical">
                    Could not load content for this language.
                  </Text>
                  <InlineStack>
                    <Button onClick={() => groupsQ.refetch()}>Retry</Button>
                  </InlineStack>
                </BlockStack>
              </Box>
            ) : groups.length === 0 ? (
              <Box padding="500">
                <Text as="p" tone="subdued">
                  {searching
                    ? `No text matches “${query}” in this language.`
                    : "No translatable content found for this language yet."}
                </Text>
              </Box>
            ) : (
              <BlockStack gap="0">
                {searching && (
                  <Box paddingInline="300" paddingBlock="200">
                    <Text as="p" tone="subdued" variant="bodySm">
                      {totalGroups} {totalGroups === 1 ? "group matches" : "groups match"}.
                    </Text>
                  </Box>
                )}
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
                <div
                  ref={listRef}
                  style={{ position: "relative", height: v.getTotalSize() }}
                >
                  {virtualItems.map((row) => {
                    const isLoaderRow = row.index >= groups.length;
                    const c = groups[row.index];
                    return (
                      <div
                        key={row.key}
                        data-index={row.index}
                        ref={v.measureElement}
                        style={{
                          position: "absolute",
                          top: 0,
                          left: 0,
                          width: "100%",
                          transform: `translateY(${row.start - scrollMargin}px)`,
                        }}
                      >
                        {isLoaderRow ? (
                          <Box padding="300">
                            <InlineStack align="center">
                              {groupsQ.isFetchNextPageError ? (
                                <Button onClick={() => groupsQ.fetchNextPage()}>
                                  Retry loading more
                                </Button>
                              ) : groupsQ.hasNextPage ? (
                                <Button
                                  onClick={() => groupsQ.fetchNextPage()}
                                  loading={groupsQ.isFetchingNextPage}
                                  disabled={groupsQ.isFetchingNextPage}
                                >
                                  Load more
                                </Button>
                              ) : null}
                            </InlineStack>
                          </Box>
                        ) : (
                          <CollectionGroup
                            collection={c}
                            language={selectedLang}
                            search={query}
                            collapsed={collapsedIds.has(c.id)}
                            onToggleCollapsed={() => toggleCollapsed(c.id)}
                            onMutate={(patch) => afterMutate(c.id, patch)}
                            onConfirm={setConfirm}
                            aiAvailable={ai.available}
                            onAiTranslate={translateScope}
                            onOpenOverlay={(unit, cid) => setOverlay({ id: unit.id, cid })}
                          />
                        )}
                      </div>
                    );
                  })}
                </div>
              </BlockStack>
            )}

            {!groupsQ.isPending && (
              <>
                <Divider />
                <Box padding="300">
                  <Text as="p" alignment="end" tone="subdued" variant="bodySm">
                    {totalDone} of {totalTexts} translated
                    {searching ? " in matching text" : ""}
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
      <ExportImportModal
        open={exportImportOpen}
        languages={languages}
        initialLanguage={selectedLang}
        onClose={() => setExportImportOpen(false)}
      />
      <CompareOverlay
        open={!!overlayUnit}
        unit={overlayUnit}
        fieldLabel={overlayFieldLabel}
        language={selectedLang}
        collectionId={overlay?.cid}
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
        onSaved={(patch) => overlay && afterMutate(overlay.cid, patch)}
        hasPrev={globalIndex > 0}
        hasNext={globalIndex >= 0 && globalIndex < orderFlat.length - 1}
        onPrev={() => {
          const prev = orderFlat[globalIndex - 1];
          setOverlay({ id: prev.id, cid: prev.cid });
        }}
        onNext={() => {
          const next = orderFlat[globalIndex + 1];
          setOverlay({ id: next.id, cid: next.cid });
        }}
      />
      <ConfirmDialog confirm={confirm} onClose={() => setConfirm(null)} />
      {autosaveToast && (
        <div
          role="status"
          aria-live="polite"
          style={{
            position: "fixed",
            left: "50%",
            bottom: "24px",
            transform: "translateX(-50%)",
            zIndex: 999999,
            padding: "10px 14px",
            borderRadius: "8px",
            background: "var(--p-color-bg-fill-inverse)",
            color: "var(--p-color-text-inverse)",
            boxShadow: "var(--p-shadow-400)",
            fontSize: "13px",
            lineHeight: "20px",
          }}
        >
          The plugin has auto save enabled.
        </div>
      )}
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
  search,
  collapsed,
  onToggleCollapsed,
  onMutate,
  onConfirm,
  onOpenOverlay,
  aiAvailable,
  onAiTranslate,
}) {
  const c = collection;
  const open = !collapsed;
  const searching = search !== "";
  const [menuOpen, setMenuOpen] = useState(false);
  const [hovered, setHovered] = useState(false);
  const [shown, setShown] = useState(TEXT_BATCH);

  // Suppress requests for groups only flashed past during fast scroll: a
  // collapsed/just-scrolled-past group never settles long enough to fetch.
  const [settled, setSettled] = useState(false);
  useEffect(() => {
    if (collapsed) {
      setSettled(false);
      return;
    }
    const t = setTimeout(() => setSettled(true), 120);
    return () => clearTimeout(t);
  }, [collapsed]);

  const textsQ = useQuery({
    queryKey: ["texts", c.id, language, search],
    queryFn: async ({ signal }) => {
      const res = await api.get(
        `/collections/${enc(c.id)}/texts?language=${enc(language)}${search ? `&search=${enc(search)}` : ""}`,
        { signal }
      );
      if (!res.ok) {
        throw new Error(res.error || "ERR_UNREACHABLE");
      }
      return res;
    },
    enabled: !collapsed && settled,
  });
  const allTexts = textsQ.data?.texts ?? [];
  const rows = allTexts.slice(0, shown);

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
      onMutate(res);
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

  // Ignore all / Clear all are whole-collection on the server. Offering them beside
  // a filtered row list would read as "ignore these 3", so they hide during a search;
  // the preview link is scope-free and stays.
  const bulkItems = [
    ...(c.preview_url
      ? [
          {
            content:
              c.preview_target === "frontend"
                ? "Show in canvas"
                : "Edit in WordPress",
            onAction: () => {
              setMenuOpen(false);
              openCanvas(c.preview_url, c.preview_target);
            },
          },
        ]
      : []),
    ...(searching
      ? []
      : [
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
        ]),
  ];

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
            onClick={onToggleCollapsed}
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
            {/* While searching, the server's counts describe the matching subset,
                so `total` is the match count. */}
            {searching && (
              <Text as="span" variant="bodySm" tone="subdued">
                {c.total} {c.total === 1 ? "match" : "matches"}
              </Text>
            )}
          </button>
          <div
            style={{
              opacity: hovered || menuOpen ? 1 : 0,
              visibility: hovered || menuOpen ? "visible" : "hidden",
              transition: "opacity 120ms ease",
            }}
          >
          <InlineStack gap="100" blockAlign="center" wrap={false}>
            {!searching && (
              <Button
                variant="tertiary"
                size="micro"
                disabled={!aiAvailable}
                icon={(props) => <StartIcon {...props} />}
                onClick={confirmAiGroup}
              >
                Translate all
              </Button>
            )}
            {bulkItems.length > 0 && (
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
                <ActionList items={bulkItems} />
              </Popover>
            )}
          </InlineStack>
          </div>
        </InlineStack>
      </Box>
      </div>
      <Collapsible open={open} id={`col-${c.id}`}>
        <Box paddingBlockEnd="200" paddingInlineStart="1000">
          {textsQ.isPending ? (
            <Box padding="300">
              <Spinner accessibilityLabel="Loading texts" size="small" />
            </Box>
          ) : textsQ.isError ? (
            <Box padding="300">
              <Text as="p" tone="critical" variant="bodySm">
                Could not load texts for this group.
              </Text>
            </Box>
          ) : (
            <>
              {rows.map((t) => (
                <TextUnitRow
                  key={t.id}
                  unit={t}
                  language={language}
                  collectionId={c.id}
                  onMutate={onMutate}
                  onConfirm={onConfirm}
                  aiAvailable={aiAvailable}
                  onAiTranslate={onAiTranslate}
                  onOpenOverlay={(unit) => onOpenOverlay(unit, c.id)}
                />
              ))}
              {allTexts.length > shown && (
                <Box padding="300">
                  <Button
                    onClick={() =>
                      setShown((n) => Math.min(n + TEXT_BATCH, allTexts.length))
                    }
                  >
                    Show {Math.min(TEXT_BATCH, allTexts.length - shown)} more
                  </Button>
                </Box>
              )}
            </>
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
  collectionId,
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
      collection_id: collectionId,
    });
    if (res.ok) {
      onMutate(res);
    }
  }

  async function doStatus(action) {
    setMenuOpen(false);
    const res = await api.post(`/texts/${enc(unit.id)}/status`, {
      action,
      language,
      collection_id: collectionId,
    });
    if (res.ok) {
      onMutate(res);
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
        onAiTranslate("text", { ref: unit.id, collection_id: collectionId });
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
      content:
        unit.preview_target === "frontend" ? "Preview on website" : "Edit in WordPress",
      onAction: () => {
        setMenuOpen(false);
        openCanvas(
          unit.preview_url,
          unit.preview_target,
          stripMarkers(unit.translated ?? unit.original ?? ""),
        );
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
