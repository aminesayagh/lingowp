import {
  ActionList,
  Button,
  ButtonGroup,
  Icon,
  InlineStack,
  Modal,
  Popover,
  Text,
} from "@shopify/polaris";
import {
  ChevronDownIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  ClipboardIcon,
  MagicIcon,
  MenuHorizontalIcon,
} from "@shopify/polaris-icons";
import {
  useCallback,
  useEffect,
  useRef,
  useState,
} from "@wordpress/element";
import { api } from "../api";
import { ChipField, SourceChips, hasMarkers } from "./TranslationUnitEditor";

/*
 * Text-editor overlay (DSL: overlay#text_editor) — one shell for both variants:
 * long_text and composed_text. Left column is the read-only original, right
 * column is the editable translation.
 *
 * There is no footer: the translation autosaves. A composed unit only ever
 * edits through the chip field, never a plain textarea, so codec markers can't
 * be typed or mangled.
 */

const AUTOSAVE_DEBOUNCE_MS = 800;
const PANE_HEIGHT = "min(62vh, 620px)";

const PANE_CLASS = "flex-1 min-w-0 flex flex-col gap-2 px-4 py-3";

const textareaStyle = (muted) => ({
  all: "unset",
  boxSizing: "border-box",
  flex: 1,
  minHeight: 0,
  overflowY: "auto",
  whiteSpace: "pre-wrap",
  padding: "8px 4px",
  fontSize: "13px",
  lineHeight: "22px",
  color: muted ? "var(--p-color-text-subdued)" : "var(--p-color-text)",
});

function PaneHeader({ left, right }) {
  return (
    <InlineStack align="space-between" blockAlign="center" gap="200" wrap={false}>
      {left}
      {right}
    </InlineStack>
  );
}

export default function CompareOverlay({
  open,
  unit,
  fieldLabel,
  language,
  languages = [],
  sourceLabel = "Original",
  sourceFlag = "",
  targetDir = "ltr",
  aiAvailable,
  onAiTranslate,
  onPickLanguage,
  onClose,
  onSaved,
  onPrev,
  onNext,
  hasPrev,
  hasNext,
}) {
  const [text, setText] = useState("");
  const [saving, setSaving] = useState(false);
  const [savedTick, setSavedTick] = useState(0);
  const [error, setError] = useState("");
  const [translating, setTranslating] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [langOpen, setLangOpen] = useState(false);

  const savedRef = useRef(""); // last value known to be persisted
  const validRef = useRef(true); // chip-structure validity (composed only)
  const pendingRef = useRef(null); // value waiting on the debounce
  const timerRef = useRef(null);

  const composed = !!unit && hasMarkers(unit.original || "");
  const unitId = unit?.id;
  const incoming = unit?.translated ?? "";

  const save = useCallback(
    async (value) => {
      if (!unitId || value === savedRef.current) {
        return;
      }
      if (composed && !validRef.current) {
        return; // broken tag structure never reaches the server
      }
      setSaving(true);
      const res = await api.post("/translations", {
        text_unit_id: unitId,
        language,
        text: value,
      });
      setSaving(false);
      if (res.ok) {
        savedRef.current = value;
        setError("");
        setSavedTick((t) => t + 1);
        onSaved();
        return;
      }
      setError(
        res.error === "ERR_TAG_STRUCTURE"
          ? "Tag structure is invalid — fix the highlighted tags."
          : "Could not save. Please try again."
      );
    },
    [unitId, language, composed, onSaved]
  );

  const flush = useCallback(() => {
    clearTimeout(timerRef.current);
    const value = pendingRef.current;
    pendingRef.current = null;
    return value === null ? Promise.resolve() : save(value);
  }, [save]);

  const schedule = useCallback(
    (value) => {
      pendingRef.current = value;
      clearTimeout(timerRef.current);
      timerRef.current = setTimeout(flush, AUTOSAVE_DEBOUNCE_MS);
    },
    [flush]
  );

  // Switching unit or language starts a clean draft — never carry one over.
  useEffect(() => {
    if (!open || !unitId) {
      return;
    }
    clearTimeout(timerRef.current);
    pendingRef.current = null;
    savedRef.current = incoming;
    validRef.current = true;
    setText(incoming);
    setError("");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, unitId, language]);

  // Adopt a value that changed server-side (AI translate, a refetch elsewhere).
  useEffect(() => {
    if (open && incoming !== savedRef.current) {
      savedRef.current = incoming;
      setText(incoming);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [incoming]);

  useEffect(() => () => clearTimeout(timerRef.current), []);

  if (!unit) {
    return null;
  }

  const setDraft = (value, isValid = true) => {
    validRef.current = isValid;
    setText(value);
    schedule(value);
  };

  async function withFlush(fn) {
    await flush();
    fn();
  }

  async function translate() {
    setTranslating(true);
    await flush();
    await onAiTranslate("text", { ref: unit.id });
    setTranslating(false);
  }

  const targetLang = languages.find((l) => l.code === language);
  const targetLabel =
    targetLang?.display_name || targetLang?.name || language || "Translation";

  const header = (
    <InlineStack gap="300" blockAlign="center" wrap={false}>
      <ButtonGroup variant="segmented">
        <Button
          icon={ChevronLeftIcon}
          disabled={!hasPrev}
          onClick={() => withFlush(onPrev)}
          accessibilityLabel="Before text"
        />
        <Button
          icon={ChevronRightIcon}
          disabled={!hasNext}
          onClick={() => withFlush(onNext)}
          accessibilityLabel="Next text"
        />
      </ButtonGroup>
      <Popover
        active={menuOpen}
        onClose={() => setMenuOpen(false)}
        activator={
          <Button
            variant="tertiary"
            icon={MenuHorizontalIcon}
            onClick={() => setMenuOpen((o) => !o)}
            accessibilityLabel="More actions"
          />
        }
      >
        <ActionList
          items={[{ content: "View on Canvas", disabled: true }]}
        />
      </Popover>
    </InlineStack>
  );

  return (
    <Modal
      open={open}
      onClose={() => withFlush(onClose)}
      title={header}
      size="large"
      noScroll
    >
      <div
        style={{
          display: "flex",
          height: PANE_HEIGHT,
          alignItems: "stretch",
        }}
      >
        {/* ---- original (read only) ---- */}
        <div className={PANE_CLASS}>
          <PaneHeader
            left={
              <InlineStack gap="150" blockAlign="center" wrap={false}>
                <span className="text-base leading-none">{sourceFlag}</span>
                <Text as="span" variant="headingXs" tone="subdued">
                  {sourceLabel}
                </Text>
              </InlineStack>
            }
            right={
              <InlineStack gap="100" blockAlign="center" wrap={false}>
                <Button
                  size="micro"
                  icon={MagicIcon}
                  disabled={!aiAvailable}
                  loading={translating}
                  onClick={translate}
                >
                  Translate
                </Button>
                <Button
                  size="micro"
                  variant="tertiary"
                  icon={ClipboardIcon}
                  accessibilityLabel="Copy the original into the translation"
                  onClick={() => setDraft(unit.original ?? "")}
                />
              </InlineStack>
            }
          />
          {composed ? (
            <SourceChips unit={unit.original} tags={unit.tags} />
          ) : (
            <div style={textareaStyle(false)}>{unit.original}</div>
          )}
        </div>

        <div style={{ width: 1, background: "var(--p-color-border)" }} />

        {/* ---- translation (editable) ---- */}
        <div className={PANE_CLASS}>
          <PaneHeader
            left={
              <Popover
                active={langOpen}
                onClose={() => setLangOpen(false)}
                activator={
                  <button
                    type="button"
                    onClick={() => setLangOpen((o) => !o)}
                    style={{
                      all: "unset",
                      cursor: "pointer",
                      display: "inline-flex",
                      alignItems: "center",
                      gap: "6px",
                    }}
                  >
                    <Text as="span" variant="headingXs" tone="subdued">
                      {targetLabel}
                    </Text>
                    <span className="w-3.5 h-3.5">
                      <Icon source={ChevronDownIcon} tone="subdued" />
                    </span>
                  </button>
                }
              >
                <ActionList
                  items={languages.map((l) => ({
                    content: l.display_name || l.name || l.code,
                    active: l.code === language,
                    onAction: () =>
                      withFlush(() => {
                        setLangOpen(false);
                        onPickLanguage(l.code);
                      }),
                  }))}
                />
              </Popover>
            }
            right={
              <Text as="span" variant="bodyXs" tone="subdued">
                {saving ? "Saving…" : savedTick > 0 ? "Saved" : ""}
              </Text>
            }
          />

          {composed ? (
            <ChipField
              sourceUnit={unit.original}
              value={text}
              tags={unit.tags}
              dir={targetDir}
              onChange={setDraft}
            />
          ) : (
            <textarea
              dir={targetDir}
              value={text}
              onChange={(e) => setDraft(e.target.value)}
              onBlur={flush}
              placeholder={unit.fallback || unit.original || ""}
              aria-label="Translation"
              style={{ ...textareaStyle(false), resize: "none" }}
            />
          )}

          {error && (
            <Text as="p" tone="critical" variant="bodySm">
              {error}
            </Text>
          )}
        </div>
      </div>
    </Modal>
  );
}
