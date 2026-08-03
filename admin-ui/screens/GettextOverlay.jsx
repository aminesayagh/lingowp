import { Button, ButtonGroup, InlineStack, Modal, Text } from "@shopify/polaris";
import { ChevronLeftIcon, ChevronRightIcon } from "@shopify/polaris-icons";
import { useEffect, useRef, useState } from "@wordpress/element";
import { api } from "../api";

/*
 * Gettext string editor overlay — same two-pane shell as Content's
 * CompareOverlay (original read-only left, editable translation right,
 * prev/next through the current list), trimmed for what a gettext string
 * actually needs: no language picker (the board is already scoped to one
 * working language), no AI translate, no composed/chip editing (gettext
 * strings are plain text, never HTML with markers).
 */

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

function PaneHeader({ children }) {
  return (
    <div style={{ display: "grid", alignItems: "center", height: "24px", minHeight: "24px", flex: "0 0 24px" }}>
      {children}
    </div>
  );
}

export default function GettextOverlay({
  open,
  unit,
  row,
  domainLabel,
  locale,
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
  const savedRef = useRef("");

  useEffect(() => {
    if (!open || !unit) {
      return;
    }
    savedRef.current = unit.msgstr;
    setText(unit.msgstr);
  }, [open, unit?.msgid, unit?.context]);

  if (!unit) {
    return null;
  }

  async function flush() {
    if (text === savedRef.current) {
      return;
    }
    setSaving(true);
    const res = await api.post("/gettext-domains/save-unit", {
      type: row.type,
      domain: row.domain,
      locale,
      msgid: unit.msgid,
      context: unit.context,
      msgstr: text,
    });
    setSaving(false);
    if (res.ok) {
      savedRef.current = text;
      setSavedTick((t) => t + 1);
      onSaved(text);
    }
  }

  async function withFlush(fn) {
    await flush();
    fn();
  }

  const header = (
    <InlineStack align="center" gap="300" blockAlign="center" wrap={false}>
      <ButtonGroup variant="segmented">
        <Button
          icon={ChevronLeftIcon}
          disabled={!hasPrev}
          onClick={() => withFlush(onPrev)}
          accessibilityLabel="Previous string"
        />
        <Button
          icon={ChevronRightIcon}
          disabled={!hasNext}
          onClick={() => withFlush(onNext)}
          accessibilityLabel="Next string"
        />
      </ButtonGroup>
      <Text as="span" variant="headingXs" tone="subdued">
        {domainLabel}
      </Text>
    </InlineStack>
  );

  return (
    <Modal open={open} onClose={() => withFlush(onClose)} title={header} size="large" noScroll>
      <div style={{ display: "flex", height: PANE_HEIGHT, alignItems: "stretch" }}>
        <div className={PANE_CLASS}>
          <PaneHeader>
            <Text as="span" variant="headingXs" tone="subdued">Original</Text>
          </PaneHeader>
          <div style={textareaStyle(false)}>{unit.msgid}</div>
        </div>

        <div style={{ width: 1, background: "var(--p-color-border)" }} />

        <div className={PANE_CLASS}>
          <PaneHeader>
            <InlineStack align="space-between" blockAlign="center" wrap={false}>
              <Text as="span" variant="headingXs" tone="subdued">{locale}</Text>
              <Text as="span" variant="bodyXs" tone="subdued">
                {saving ? "Saving…" : savedTick > 0 ? "Saved" : ""}
              </Text>
            </InlineStack>
          </PaneHeader>
          <textarea
            value={text}
            onChange={(e) => setText(e.target.value)}
            onBlur={flush}
            aria-label="Translation"
            style={{ ...textareaStyle(false), resize: "none" }}
          />
        </div>
      </div>
    </Modal>
  );
}
