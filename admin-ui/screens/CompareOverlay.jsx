import {
  ActionList,
  Button,
  ButtonGroup,
  Icon,
  InlineStack,
  Modal,
  Popover,
  Text,
  Tooltip,
} from "@shopify/polaris";
import {
  CheckIcon,
  ChevronDownIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  DuplicateIcon,
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
import { flagIconUrl, FlagIcon } from "./FlagIcon";
import { ChipField, SourceChips, hasMarkers, stripMarkers } from "./TranslationUnitEditor";
import { lengthWarningMessage } from "./lengthWarning.mjs";

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
// Polaris' Modal dialog sits at a z-index (519) above a Tooltip's own portal
// (400) — inside a Modal a Tooltip renders but paints behind it unless
// explicitly overridden higher than the dialog.
const TOOLTIP_Z_INDEX = 1000;

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
    <div
      style={{
        display: "grid",
        alignItems: "center",
        height: "24px",
        minHeight: "24px",
        flex: "0 0 24px",
        "--p-height-700": "24px",
        "--p-width-700": "24px",
      }}
    >
      <InlineStack
        align="space-between"
        blockAlign="center"
        gap="200"
        wrap={false}
      >
        {left}
        {right}
      </InlineStack>
    </div>
  );
}

export default function CompareOverlay({
  open,
  unit,
  fieldLabel,
  language,
  collectionId,
  languages = [],
  sourceLabel = "Original",
  sourceFlag = "",
  targetDir = "ltr",
  aiAvailable,
  onAiTranslate,
  onPickLanguage,
  onOpenPreview,
  onClose,
  onSaved,
  onLengthWarning,
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
  const [filled, setFilled] = useState(false);

  const savedRef = useRef(""); // last value known to be persisted
  const validRef = useRef(true); // chip-structure validity (composed only)
  const pendingRef = useRef(null); // value waiting on the debounce
  const timerRef = useRef(null);

  const composed = !!unit && hasMarkers(unit.original || "");
  const unitId = unit?.id;
  // A composed unit with no translation yet starts from the original so every
  // formatting chip is present; the translator edits the words in place. This is
  // only a starting draft — autosave still fires only once the value changes.
  const incoming =
    unit?.translated ?? (composed ? unit?.original ?? "" : "");

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
        collection_id: collectionId,
      });
      setSaving(false);
      if (res.ok) {
        savedRef.current = value;
        setError("");
        setSavedTick((t) => t + 1);
        onSaved(res);
        const originalForCompare = composed
          ? stripMarkers(unit.original || "")
          : unit.original || "";
        const translatedForCompare = composed ? stripMarkers(value) : value;
        const warning = lengthWarningMessage(originalForCompare, translatedForCompare);
        if (warning) {
          onLengthWarning(warning);
        }
        return;
      }
      setError(
        res.error === "ERR_TAG_STRUCTURE"
          ? "Tag structure is invalid — fix the highlighted tags."
          : "Could not save. Please try again."
      );
    },
    [unitId, language, composed, collectionId, onSaved, unit, onLengthWarning]
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
    await onAiTranslate("text", { ref: unit.id, collection_id: collectionId });
    setTranslating(false);
  }

  function fillFromOriginal() {
    setDraft(unit.original ?? "");
    setFilled(true);
    setTimeout(() => setFilled(false), 1500);
  }

  const targetLang = languages.find((l) => l.code === language);
  const targetLabel =
    targetLang?.display_name || targetLang?.name || language || "Translation";
  const targetFlag = flagIconUrl(targetLang || { code: language, locale: language });
  const matchesOriginal = text === (unit.original ?? "");

  const header = (
    <InlineStack align="center" gap="300" blockAlign="center" wrap={false}>
      <div style={{ display: "flex", alignItems: "center" }}>
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
      </div>
      {unit.preview_url && (
        <div style={{ display: "flex", alignItems: "center", marginTop: "5px" }}>
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
              items={[{
                content: unit.preview_target === "frontend"
                  ? "Preview on website"
                  : "Edit in WordPress",
                onAction: () => withFlush(() => {
                  setMenuOpen(false);
                  onOpenPreview(
                    unit.preview_url,
                    unit.preview_target,
                    stripMarkers(text || unit.original || ""),
                  );
                }),
              }]}
            />
          </Popover>
        </div>
      )}
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
                <FlagIcon src={sourceFlag} />
                <Text as="span" variant="headingXs" tone="subdued">
                  {sourceLabel}
                </Text>
              </InlineStack>
            }
            right={
              <InlineStack gap="100" blockAlign="center" wrap={false}>
                <Tooltip
                  zIndexOverride={TOOLTIP_Z_INDEX}
                  content={aiAvailable ? "Translate with AI" : "AI translation is not available"}
                >
                  <Button
                    size="micro"
                    icon={MagicIcon}
                    disabled={!aiAvailable}
                    loading={translating}
                    onClick={translate}
                  >
                    Translate
                  </Button>
                </Tooltip>
                <Tooltip
                  zIndexOverride={TOOLTIP_Z_INDEX}
                  content={
                    filled
                      ? "Copied!"
                      : matchesOriginal
                        ? "Translation already matches the original"
                        : "Copy the original into the translation"
                  }
                >
                  <Button
                    size="micro"
                    variant="tertiary"
                    icon={filled ? CheckIcon : DuplicateIcon}
                    disabled={matchesOriginal}
                    accessibilityLabel="Copy the original into the translation"
                    onClick={fillFromOriginal}
                  />
                </Tooltip>
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
                    <FlagIcon src={targetFlag} />
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
