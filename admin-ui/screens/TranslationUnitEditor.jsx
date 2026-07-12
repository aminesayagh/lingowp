import { BlockStack, Box, Icon, InlineStack, Text } from "@shopify/polaris";
import {
  AlertTriangleIcon,
  CheckIcon,
  InfoIcon,
  ResetIcon,
  ReturnIcon,
  XCircleIcon,
} from "@shopify/polaris-icons";
import { useEffect, useMemo, useRef, useState } from "@wordpress/element";

/*
 * TranslationUnitEditor — inline-tag token-chip editor (Option A, v1).
 *
 * The stored format is untouched: this parses the codec's unit string
 * ("See {1}this {2}word{/2}{/1} now") into segments, renders {n} markers as
 * atomic chips, and re-serializes to the exact same string. onChange fires with
 * (serialized, isValid) so the host modal can gate its Save button.
 *
 * The pure functions below (parseUnit / normalize / serialize /
 * validateStructure) are the exact mirror of the PHP UnitStructureGuard — keep
 * them in lockstep. v1 cuts: arrow-move, named-chip descriptors (falls back to
 * #n), backend stack-merge.
 */

// ---------- codec-side helpers (pure, mirror of UnitStructureGuard) ----------

const MARKER_RE = /\{(\/?)(\d+)(\/?)\}/g;

/** Parse a unit string into a normalized segment list. Normalized = always
 *  starts/ends with a text segment, and every chip has a text segment on both
 *  sides (possibly empty), so a caret position always exists around any chip. */
function parseUnit(str) {
  const raw = [];
  let last = 0;
  MARKER_RE.lastIndex = 0;
  let m;
  while ((m = MARKER_RE.exec(str))) {
    if (m.index > last) raw.push({ type: "text", value: str.slice(last, m.index) });
    const kind = m[1] === "/" ? "close" : m[3] === "/" ? "void" : "open";
    raw.push({ type: kind, n: Number(m[2]) });
    last = m.index + m[0].length;
  }
  if (last < str.length) raw.push({ type: "text", value: str.slice(last) });
  return normalize(raw);
}

function normalize(segs) {
  const out = [{ type: "text", value: "" }];
  for (const s of segs) {
    if (s.type === "text") out[out.length - 1].value += s.value;
    else {
      out.push({ ...s });
      out.push({ type: "text", value: "" });
    }
  }
  return out;
}

function serialize(segs) {
  return segs
    .map((s) =>
      s.type === "text"
        ? s.value
        : s.type === "open"
        ? `{${s.n}}`
        : s.type === "close"
        ? `{/${s.n}}`
        : `{${s.n}/}`
    )
    .join("");
}

const markerKey = (s) => `${s.type}:${s.n}`;

/** Front-end mirror of the server-side guard: multiset equality + nesting. */
function validateStructure(segs, sourceSegs) {
  const cur = segs.filter((s) => s.type !== "text");
  const src = sourceSegs.filter((s) => s.type !== "text");

  const countBy = (list) => {
    const c = {};
    for (const s of list) c[markerKey(s)] = (c[markerKey(s)] || 0) + 1;
    return c;
  };
  const cm = countBy(cur);
  const sm = countBy(src);

  const missing = [];
  const extra = [];
  for (const k of Object.keys(sm))
    for (let i = 0; i < sm[k] - (cm[k] || 0); i++) missing.push(k);
  for (const k of Object.keys(cm))
    for (let i = 0; i < cm[k] - (sm[k] || 0); i++) extra.push(k);

  // Nesting: reordering whole pairs is allowed, interleaving is not.
  let nestError = null;
  const stack = [];
  for (const s of cur) {
    if (s.type === "open") stack.push(s.n);
    else if (s.type === "close") {
      if (stack.length && stack[stack.length - 1] === s.n) stack.pop();
      else {
        nestError = s.n;
        break;
      }
    }
  }
  if (nestError === null && stack.length) nestError = stack[stack.length - 1];

  // Empty pairs → warning only (renders e.g. an empty <a></a>).
  const emptyPairs = [];
  for (let i = 0; i + 2 < segs.length; i++) {
    if (
      segs[i].type === "open" &&
      segs[i + 1].type === "text" &&
      segs[i + 1].value.trim() === "" &&
      segs[i + 2].type === "close" &&
      segs[i + 2].n === segs[i].n
    )
      emptyPairs.push(segs[i].n);
  }

  return {
    missing,
    extra,
    nestError,
    emptyPairs,
    ok: missing.length === 0 && extra.length === 0 && nestError === null,
  };
}

// ---------- presentation helpers ----------

// ponytail: fixed light-tint palette — wp-admin renders Polaris light-only, so
// no dark-theme token wiring is needed. Add token-based hues if dark mode lands.
const HUES = [
  { bg: "#e0f2fe", border: "#38bdf8", text: "#075985" },
  { bg: "#fef3c7", border: "#fbbf24", text: "#92400e" },
  { bg: "#d1fae5", border: "#34d399", text: "#065f46" },
  { bg: "#ede9fe", border: "#a78bfa", text: "#5b21b6" },
  { bg: "#ffe4e6", border: "#fb7185", text: "#9f1239" },
  { bg: "#cffafe", border: "#22d3ee", text: "#155e75" },
];
const hueFor = (n) => HUES[(n - 1) % HUES.length];

const TAG_LABELS = {
  a: "link", strong: "bold", b: "bold", em: "italic", i: "italic",
  span: "span", code: "code", img: "image", br: "break", cite: "cite",
  abbr: "abbr", sup: "sup", sub: "sub", mark: "mark", u: "underline",
};
const labelFor = (tags, n) => {
  const t = tags?.[n]?.tag;
  return t ? TAG_LABELS[t] || t : `#${n}`;
};
const chipText = (seg, tags) => {
  const base = labelFor(tags, seg.n);
  if (seg.type === "close") return `/${base}`;
  return base;
};

const chipBaseStyle = (hue) => ({
  all: "unset",
  boxSizing: "border-box",
  display: "inline-flex",
  alignItems: "center",
  gap: "4px",
  margin: "2px",
  padding: "0 6px",
  height: "20px",
  borderRadius: "6px",
  border: `1px solid ${hue.border}`,
  background: hue.bg,
  color: hue.text,
  fontSize: "11px",
  fontFamily: "var(--p-font-family-mono, monospace)",
  lineHeight: "18px",
  userSelect: "none",
  verticalAlign: "middle",
});

// ---------- Chip ----------

function Chip({ seg, tags, readOnly, selected, onSelect, onKeyDown, onRemove }) {
  const hue = hueFor(seg.n);
  return (
    <button
      type="button"
      draggable={false}
      tabIndex={readOnly ? -1 : 0}
      onClick={readOnly ? undefined : onSelect}
      onKeyDown={readOnly ? undefined : onKeyDown}
      title={
        readOnly
          ? `Formatting tag ${seg.n}`
          : `Formatting tag ${seg.n} — keep it, don't retype it. Backspace removes it.`
      }
      style={{
        ...chipBaseStyle(hue),
        cursor: readOnly ? "default" : "pointer",
        opacity: readOnly ? 0.9 : 1,
        outline: selected ? `2px solid ${hue.text}` : "none",
        outlineOffset: selected ? "1px" : 0,
      }}
    >
      <span
        style={{
          width: "6px",
          height: "6px",
          borderRadius: "50%",
          background: hue.border,
        }}
      />
      <span>{chipText(seg, tags)}</span>
      {!readOnly && selected && (
        <span
          onClick={(e) => {
            e.stopPropagation();
            onRemove();
          }}
          style={{ marginInlineStart: "2px", opacity: 0.6, fontWeight: 700 }}
          title="Remove tag"
        >
          ×
        </span>
      )}
    </button>
  );
}

// ---------- read-only source line ----------

function SourceLine({ segs, tags, dir }) {
  return (
    <div dir={dir} style={{ fontSize: "13px", lineHeight: "26px", color: "var(--p-color-text)" }}>
      {segs.map((s, i) =>
        s.type === "text" ? (
          <span key={i}>{s.value}</span>
        ) : (
          <Chip key={i} seg={s} tags={tags} readOnly />
        )
      )}
    </div>
  );
}

// ---------- editor ----------

export default function TranslationUnitEditor({
  sourceUnit,
  initialTranslation,
  tags,
  dir = "ltr",
  sourceDir = "ltr",
  onChange,
}) {
  const sourceSegs = useMemo(() => parseUnit(sourceUnit ?? ""), [sourceUnit]);
  const [segs, setSegs] = useState(() => parseUnit(initialTranslation ?? ""));
  const [selectedChip, setSelectedChip] = useState(null);
  const [flash, setFlash] = useState(null);
  const caretRef = useRef(null); // { segIdx, pos }
  const flashTimer = useRef(null);
  const lastSent = useRef(null);

  const validation = useMemo(
    () => validateStructure(segs, sourceSegs),
    [segs, sourceSegs]
  );

  // Keep the host in sync (serialized value + validity) on real changes + mount.
  // Guarded by a ref so an inline host onChange can't drive a render loop.
  const serialized = useMemo(() => serialize(segs), [segs]);
  useEffect(() => {
    const sig = `${validation.ok ? 1 : 0}|${serialized}`;
    if (sig === lastSent.current) return;
    lastSent.current = sig;
    onChange?.(serialized, validation.ok);
  }, [serialized, validation.ok, onChange]);

  const showFlash = (msg) => {
    setFlash(msg);
    clearTimeout(flashTimer.current);
    flashTimer.current = setTimeout(() => setFlash(null), 3200);
  };
  useEffect(() => () => clearTimeout(flashTimer.current), []);

  const update = (next) => setSegs(normalize(next));

  // -- text editing (markers can never be typed: paste guard) --
  const onTextChange = (idx, raw) => {
    const cleaned = raw.replace(/\{\/?\d+\/?\}/g, "");
    if (cleaned !== raw)
      showFlash("Tag markers can't be typed or pasted — use the chips.");
    update(segs.map((s, i) => (i === idx ? { ...s, value: cleaned } : s)));
  };

  const rememberCaret = (idx, e) =>
    (caretRef.current = { segIdx: idx, pos: e.target.selectionStart ?? 0 });

  const removeChip = (idx) => {
    const next = [...segs];
    next[idx - 1] = { type: "text", value: next[idx - 1].value + next[idx + 1].value };
    next.splice(idx, 2);
    setSelectedChip(null);
    update(next);
  };

  const onChipKeyDown = (idx) => (e) => {
    if (e.key === "Backspace" || e.key === "Delete") {
      e.preventDefault();
      removeChip(idx);
    } else if (e.key === "Escape") {
      setSelectedChip(null);
    }
  };

  /** Insert a missing chip at the last caret position (or at the end). */
  const insertMissing = (key) => {
    const [type, nStr] = key.split(":");
    const chip = { type, n: Number(nStr) };
    const next = segs.map((s) => ({ ...s }));
    let { segIdx, pos } = caretRef.current ?? {
      segIdx: next.length - 1,
      pos: next[next.length - 1].value.length,
    };
    if (next[segIdx]?.type !== "text") {
      segIdx = next.length - 1;
      pos = next[segIdx].value.length;
    }
    const t = next[segIdx];
    next.splice(
      segIdx,
      1,
      { type: "text", value: t.value.slice(0, pos) },
      chip,
      { type: "text", value: t.value.slice(pos) }
    );
    update(next);
  };

  const resetToSource = () => {
    setSegs(sourceSegs.map((s) => ({ ...s })));
    setSelectedChip(null);
    showFlash("Source copied — translate the text, keep the chips.");
  };

  const problem =
    validation.nestError !== null
      ? `Tags are crossed: "${labelFor(tags, validation.nestError)}" closes outside the tag it opened in — the rendered HTML would be invalid.`
      : validation.missing.length || validation.extra.length
      ? [
          validation.missing.length ? `${validation.missing.length} tag(s) missing` : "",
          validation.extra.length ? `${validation.extra.length} extra tag(s)` : "",
        ]
          .filter(Boolean)
          .join(" · ")
      : null;

  return (
    <BlockStack gap="300">
      {/* source pane */}
      <Box
        padding="300"
        background="bg-surface-secondary"
        borderRadius="200"
        borderWidth="025"
        borderColor="border"
      >
        <BlockStack gap="150">
          <Text as="span" variant="headingXs" tone="subdued">
            Source
          </Text>
          <SourceLine segs={sourceSegs} tags={tags} dir={sourceDir} />
        </BlockStack>
      </Box>

      {/* editable pane */}
      <BlockStack gap="150">
        <InlineStack align="space-between" blockAlign="center">
          <Text as="span" variant="headingXs" tone="subdued">
            Translation
          </Text>
          <button
            type="button"
            onClick={resetToSource}
            style={{
              all: "unset",
              cursor: "pointer",
              display: "inline-flex",
              alignItems: "center",
              gap: "4px",
              fontSize: "12px",
              color: "var(--p-color-text-subdued)",
            }}
          >
            <span style={{ width: 14, height: 14 }}>
              <Icon source={ResetIcon} tone="subdued" />
            </span>
            Copy source
          </button>
        </InlineStack>

        <div
          dir={dir}
          onClick={() => setSelectedChip(null)}
          style={{
            minHeight: "44px",
            display: "flex",
            flexWrap: "wrap",
            alignItems: "center",
            padding: "4px 8px",
            borderRadius: "8px",
            border: `1px solid ${
              validation.ok ? "var(--p-color-border)" : "var(--p-color-border-critical)"
            }`,
            background: validation.ok ? "var(--p-color-bg-surface)" : "var(--p-color-bg-surface-critical)",
            fontSize: "13px",
            lineHeight: "26px",
          }}
        >
          {segs.map((s, i) =>
            s.type === "text" ? (
              <input
                key={i}
                type="text"
                value={s.value}
                onChange={(e) => onTextChange(i, e.target.value)}
                onSelect={(e) => rememberCaret(i, e)}
                onFocus={(e) => rememberCaret(i, e)}
                onClick={(e) => e.stopPropagation()}
                placeholder={segs.length === 1 ? "Type the translation…" : undefined}
                spellCheck={false}
                aria-label="Translation text"
                style={{
                  all: "unset",
                  minWidth: "8px",
                  padding: "2px 0",
                  color: "var(--p-color-text)",
                  width: `${Math.max(s.value.length + 1, segs.length === 1 ? 22 : 1)}ch`,
                }}
              />
            ) : (
              <span key={i} onClick={(e) => e.stopPropagation()}>
                <Chip
                  seg={s}
                  tags={tags}
                  selected={selectedChip === i}
                  onSelect={() => setSelectedChip(i)}
                  onKeyDown={onChipKeyDown(i)}
                  onRemove={() => removeChip(i)}
                />
              </span>
            )
          )}
        </div>

        <InlineStack gap="100" blockAlign="center">
          <span style={{ width: 14, height: 14 }}>
            <Icon source={InfoIcon} tone="subdued" />
          </span>
          <Text as="span" variant="bodyXs" tone="subdued">
            Chips are formatting — keep them, never retype them. Click a chip to select, then Backspace to remove.
          </Text>
        </InlineStack>
      </BlockStack>

      {/* validation + repair */}
      <Box padding="200" background="bg-surface-secondary" borderRadius="200">
        <InlineStack gap="300" blockAlign="center" align="start">
          {validation.ok ? (
            <InlineStack gap="100" blockAlign="center">
              <span style={{ width: 16, height: 16 }}>
                <Icon source={CheckIcon} tone="success" />
              </span>
              <Text as="span" variant="bodySm" tone="success">
                Structure OK
              </Text>
            </InlineStack>
          ) : (
            <InlineStack gap="100" blockAlign="center">
              <span style={{ width: 16, height: 16 }}>
                <Icon source={XCircleIcon} tone="critical" />
              </span>
              <Text as="span" variant="bodySm" tone="critical">
                {problem}
              </Text>
            </InlineStack>
          )}

          {validation.missing.length > 0 && (
            <InlineStack gap="100" blockAlign="center">
              <Text as="span" variant="bodyXs" tone="subdued">
                insert:
              </Text>
              {validation.missing.map((k, i) => {
                const [type, nStr] = k.split(":");
                const seg = { type, n: Number(nStr) };
                const hue = hueFor(seg.n);
                return (
                  <button
                    key={i}
                    type="button"
                    onClick={() => insertMissing(k)}
                    title="Insert at cursor position"
                    style={{
                      ...chipBaseStyle(hue),
                      cursor: "pointer",
                      borderStyle: "dashed",
                    }}
                  >
                    <span style={{ width: 10, height: 10 }}>
                      <Icon source={ReturnIcon} />
                    </span>
                    {chipText(seg, tags)}
                  </button>
                );
              })}
            </InlineStack>
          )}

          {validation.ok && validation.emptyPairs.length > 0 && (
            <InlineStack gap="100" blockAlign="center">
              <span style={{ width: 14, height: 14 }}>
                <Icon source={AlertTriangleIcon} tone="caution" />
              </span>
              <Text as="span" variant="bodyXs" tone="caution">
                "{labelFor(tags, validation.emptyPairs[0])}" tag is empty — it will render with no text.
              </Text>
            </InlineStack>
          )}

          {flash && (
            <Text as="span" variant="bodyXs" tone="caution">
              {flash}
            </Text>
          )}
        </InlineStack>
      </Box>
    </BlockStack>
  );
}
