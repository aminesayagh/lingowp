import { Box, Icon, InlineStack, Text } from "@shopify/polaris";
import {
  AlertTriangleIcon,
  CheckIcon,
  ReturnIcon,
  XCircleIcon,
} from "@shopify/polaris-icons";
import {
  Fragment,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "@wordpress/element";
import {
  parseUnit,
  normalize,
  serialize,
  validateStructure,
  groupCoincident,
  hasMarkers,
  stripMarkers,
} from "./inlineTagCodec.mjs";

// Re-exported so existing importers (CompareOverlay.jsx, Dashboard.jsx) keep
// resolving these from "./TranslationUnitEditor".
export { hasMarkers, stripMarkers };

/*
 * Inline-tag token-chip editor (Option A, v1), split into the two pieces the
 * text-editor overlay composes: SourceChips (read-only, left column) and
 * ChipField (editable, right column).
 *
 * The stored format is untouched: this parses the codec's unit string
 * ("See {1}this {2}word{/2}{/1} now") into segments, renders {n} markers as
 * atomic chips, and re-serializes to the exact same string. onChange fires with
 * (serialized, isValid) so the host can gate autosave.
 *
 * The pure functions below (parseUnit / normalize / serialize /
 * validateStructure) are the exact mirror of the PHP UnitStructureGuard — keep
 * them in lockstep. v1 cuts: arrow-move, named-chip descriptors (falls back to
 * #n), backend stack-merge.
 */

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

// Label for a collapsed coincident stack. Descriptor-aware, but tag names are
// not wired to the editor yet (see plan) — so it falls back to a neutral
// "formatting" / "formatting +N" and upgrades automatically if `tags` lands.
const groupLabel = (group, tags) => {
  const names = group.ids.map((n) =>
    tags?.[n]?.tag ? TAG_LABELS[tags[n].tag] || tags[n].tag : null
  );
  if (names.length && names.every(Boolean)) {
    return names.length <= 2 ? names.join("+") : `${names[0]} (+${names.length - 1})`;
  }
  return group.ids.length > 1 ? `formatting +${group.ids.length - 1}` : "formatting";
};
const groupTitle = (group, tags) => {
  const path = group.ids
    .map((n) => (tags?.[n]?.tag ? TAG_LABELS[tags[n].tag] || tags[n].tag : `#${n}`))
    .join(" › ");
  return `${path} — formatting stack, keep it wrapping this text`;
};

const endsSpace = (s) => /\s$/.test(s || "");
const startsSpace = (s) => /^\s/.test(s || "");

const ordinalSuffix = (n) => {
  const t = n % 100;
  if (t >= 11 && t <= 13) return "th";
  return { 1: "st", 2: "nd", 3: "rd" }[n % 10] || "th";
};
const truncate = (s, n) => (s.length > n ? `${s.slice(0, n - 1)}…` : s);

// A faint dot marking that the source had a space on this side of a chip, so the
// translator can see whether the space belongs inside or outside the formatting.
function SpaceDot() {
  return (
    <span
      aria-hidden="true"
      title="the source has a space here"
      style={{
        display: "inline-block",
        width: "3px",
        height: "3px",
        margin: "0 1px",
        borderRadius: "50%",
        background: "var(--p-color-text-subdued)",
        opacity: 0.45,
        verticalAlign: "middle",
      }}
    />
  );
}

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

function Chip({
  seg,
  tags,
  readOnly,
  selected,
  onSelect,
  onKeyDown,
  onRemove,
  onDragStart,
  onDragEnd,
  label,
  hueN,
  title,
}) {
  const hue = hueFor(hueN ?? seg.n);
  const text = label ?? chipText(seg, tags);
  return (
    <button
      type="button"
      draggable={!readOnly && !!onDragStart}
      onDragStart={onDragStart}
      onDragEnd={onDragEnd}
      tabIndex={readOnly ? -1 : 0}
      onClick={readOnly ? undefined : onSelect}
      onKeyDown={readOnly ? undefined : onKeyDown}
      title={
        title ??
        (readOnly
          ? `Formatting tag ${seg.n}`
          : `Formatting tag ${seg.n} — keep it, don't retype it. Backspace removes it.`)
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
        className="w-1.5 h-1.5 rounded-full"
        style={{ background: hue.border }}
      />
      <span>{text}</span>
      {!readOnly && selected && (
        <span
          onClick={(e) => {
            e.stopPropagation();
            onRemove();
          }}
          className="ms-0.5 opacity-60 font-bold"
          title="Remove tag"
        >
          ×
        </span>
      )}
    </button>
  );
}

// ---------- read-only source (left overlay column) ----------

export function SourceChips({ unit, tags, dir = "ltr" }) {
  const segs = useMemo(() => parseUnit(unit ?? ""), [unit]);
  const tree = useMemo(() => groupCoincident(segs), [segs]);
  return (
    <div
      dir={dir}
      style={{
        flex: 1,
        overflowY: "auto",
        padding: "8px 4px",
        fontSize: "13px",
        lineHeight: "26px",
        color: "var(--p-color-text)",
      }}
    >
      {tree.map((node, i) =>
        node.type === "text" ? (
          <span key={i}>{node.value}</span>
        ) : node.type === "group" ? (
          <Fragment key={i}>
            <Chip
              tags={tags}
              readOnly
              label={groupLabel(node, tags)}
              hueN={node.ids[0]}
              title={groupTitle(node, tags)}
            />
            <span>{node.inner.value}</span>
          </Fragment>
        ) : (
          <Chip key={i} seg={node} tags={tags} readOnly />
        )
      )}
    </div>
  );
}

// ---------- editable field (right overlay column) ----------

export function ChipField({ sourceUnit, value, tags, dir = "ltr", onChange }) {
  const sourceSegs = useMemo(() => parseUnit(sourceUnit ?? ""), [sourceUnit]);
  const [segs, setSegs] = useState(() => parseUnit(value ?? ""));
  const [selectedChip, setSelectedChip] = useState(null);
  const [flash, setFlash] = useState(null);
  const caretRef = useRef(null); // { segIdx, pos }
  const flashTimer = useRef(null);
  const lastSent = useRef(null);
  const dragRef = useRef(null); // indices of the stack being dragged

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

  // Adopt a value that changed outside the field (AI translate, copy source,
  // a refetch after autosave). Deliberately keyed on `value` only: while the
  // user types, the host's value lags our serialization and must not reparse.
  useEffect(() => {
    if ((value ?? "") !== serialize(segs)) setSegs(parseUnit(value ?? ""));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  const showFlash = (msg) => {
    setFlash(msg);
    clearTimeout(flashTimer.current);
    flashTimer.current = setTimeout(() => setFlash(null), 3200);
  };
  useEffect(() => () => clearTimeout(flashTimer.current), []);

  const update = (next) => setSegs(normalize(next));

  // Render from the collapsed tree; edits still resolve to true-segment indices.
  const tree = useMemo(() => groupCoincident(segs), [segs]);
  const idxOf = useMemo(() => new Map(segs.map((s, i) => [s, i])), [segs]);
  const sourceTree = useMemo(() => groupCoincident(sourceSegs), [sourceSegs]);

  // -- text editing (markers can never be typed: paste guard) --
  const onTextChange = (idx, raw) => {
    const cleaned = raw.replace(/\{\/?\d+\/?\}/g, "");
    if (cleaned !== raw)
      showFlash("Tag markers can't be typed or pasted — use the chips.");
    update(segs.map((s, i) => (i === idx ? { ...s, value: cleaned } : s)));
  };

  const rememberCaret = (idx, e) =>
    (caretRef.current = { segIdx: idx, pos: e.target.selectionStart ?? 0 });

  // Remove one or more chip segments as a unit, keeping surrounding text; a
  // collapsed group passes all its member+closer indices so a stack is atomic.
  const removeStack = (indices) => {
    const drop = new Set(indices);
    setSelectedChip(null);
    update(segs.filter((_, i) => !drop.has(i)));
  };
  const removeChip = (idx) => removeStack([idx]);

  // Move a stack over one adjacent word by shifting that word across the block:
  // only the two bracketing text segments change value, so the block (and every
  // marker's relative order) is untouched — the move can't break structure.
  // ponytail: word-hop only; hopping an adjacent chip when the between-text is
  // empty is left out — arrow-repeat or drag covers that. Arrow keys are logical
  // (not RTL-mirrored) in v1.
  const moveStack = (indices, dir, jump) => {
    const lo = Math.min(...indices);
    const hi = Math.max(...indices);
    const bi = lo - 1;
    const ai = hi + 1;
    if (bi < 0 || ai >= segs.length) return;
    const next = segs.map((s) => ({ ...s }));
    const B = next[bi].value;
    const A = next[ai].value;
    if (dir === "right") {
      if (jump) {
        next[bi].value = B + A;
        next[ai].value = "";
      } else {
        const m = A.match(/^(\s*\S+)([\s\S]*)$/);
        if (!m) return;
        next[bi].value = B + m[1];
        next[ai].value = m[2];
      }
    } else {
      if (jump) {
        next[ai].value = B + A;
        next[bi].value = "";
      } else {
        const m = B.match(/^([\s\S]*?)(\S+\s*)$/);
        if (!m) return;
        next[bi].value = m[1];
        next[ai].value = m[2] + A;
      }
    }
    update(next);
  };

  const onChipKeyDown = (sel) => (e) => {
    const k = e.key;
    if (k === "Backspace" || k === "Delete") {
      e.preventDefault();
      // Two-press delete: first press selects, second removes.
      if (selectedChip === sel.anchor) removeStack(sel.indices);
      else setSelectedChip(sel.anchor);
    } else if (k === "ArrowLeft" || k === "ArrowRight") {
      e.preventDefault();
      setSelectedChip(sel.anchor);
      moveStack(sel.indices, k === "ArrowRight" ? "right" : "left", e.ctrlKey || e.metaKey);
    } else if (k === "Escape") {
      setSelectedChip(null);
    }
  };

  // Insert chips at the last caret position (or at the end). A re-inserted stack
  // passes all its markers so it lands atomically.
  const insertChipsAtCaret = (chips) => {
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
      ...chips.map((c) => ({ ...c })),
      { type: "text", value: t.value.slice(pos) }
    );
    update(next);
  };
  const insertMissing = (key) => {
    const [type, nStr] = key.split(":");
    insertChipsAtCaret([{ type, n: Number(nStr) }]);
  };
  // Re-insert a whole coincident stack (members then closers) from the source.
  const insertStack = (group) =>
    insertChipsAtCaret([
      ...group.members.map((m) => ({ type: "open", n: m.n })),
      ...group.closers.map((c) => ({ type: "close", n: c.n })),
    ]);

  // Drag a stack to a caret slot in any text segment; markers reorder but the
  // block stays intact (self-drop is a no-op).
  const dropStackAt = (targetNode, pos) => {
    const indices = dragRef.current;
    dragRef.current = null;
    if (!indices) return;
    const lo = Math.min(...indices);
    const hi = Math.max(...indices);
    const block = segs.slice(lo, hi + 1).map((s) => ({ ...s }));
    const rest = [...segs.slice(0, lo), ...segs.slice(hi + 1)];
    const ti = rest.indexOf(targetNode); // -1 if the target was inside the block
    if (ti < 0) return;
    const t = rest[ti];
    const at = Math.max(0, Math.min(pos ?? t.value.length, t.value.length));
    rest.splice(
      ti,
      1,
      { type: "text", value: t.value.slice(0, at) },
      ...block,
      { type: "text", value: t.value.slice(at) }
    );
    setSelectedChip(null);
    update(rest);
  };

  const textInput = (node) => {
    const i = idxOf.get(node);
    return (
      <input
        key={`t${i}`}
        type="text"
        value={node.value}
        onChange={(e) => onTextChange(i, e.target.value)}
        onSelect={(e) => rememberCaret(i, e)}
        onFocus={(e) => rememberCaret(i, e)}
        onClick={(e) => e.stopPropagation()}
        onDragOver={(e) => dragRef.current && e.preventDefault()}
        onDrop={(e) => {
          e.preventDefault();
          dropStackAt(node, e.target.selectionStart);
        }}
        placeholder={segs.length === 1 ? "Type the translation…" : undefined}
        spellCheck={false}
        aria-label="Translation text"
        style={{
          all: "unset",
          minWidth: "8px",
          padding: "2px 0",
          color: "var(--p-color-text)",
          width: `${Math.max(node.value.length + 1, segs.length === 1 ? 22 : 1)}ch`,
        }}
      />
    );
  };

  // Which source stacks are absent now — the missing-formatting dock. Neutral
  // labels alone are ambiguous, so each entry carries an ordinal + a source-text
  // preview of the phrase it wrapped.
  const currentOpenIds = useMemo(
    () => new Set(segs.filter((s) => s.type === "open").map((s) => s.n)),
    [segs]
  );
  const dock = useMemo(() => {
    const groups = sourceTree.filter((n) => n.type === "group");
    const seen = {};
    return groups
      .map((g) => {
        const label = groupLabel(g, tags);
        seen[label] = (seen[label] || 0) + 1;
        return { g, label, ordinal: seen[label], preview: g.inner.value.trim() };
      })
      .filter(({ g }) => g.ids.some((n) => !currentOpenIds.has(n)));
  }, [sourceTree, currentOpenIds, tags]);
  // Missing markers not covered by a docked stack (voids, non-collapsed chips).
  const looseMissing = useMemo(() => {
    const docked = new Set(dock.flatMap(({ g }) => g.ids));
    return validation.missing.filter((k) => !docked.has(Number(k.split(":")[1])));
  }, [validation.missing, dock]);

  // Source boundary spaces per stack (keyed by outermost id): shows the dot and
  // drives the "space ran together" warning — never rewrites whitespace (§7.2).
  const srcSpaces = useMemo(() => {
    const sIdx = new Map(sourceSegs.map((s, i) => [s, i]));
    const map = new Map();
    for (const g of sourceTree.filter((n) => n.type === "group")) {
      const sLo = sIdx.get(g.members[0]);
      const sHi = sIdx.get(g.closers[g.closers.length - 1]);
      map.set(g.ids[0], {
        left: sLo - 1 < 0 || endsSpace(sourceSegs[sLo - 1]?.value),
        right: sHi + 1 >= sourceSegs.length || startsSpace(sourceSegs[sHi + 1]?.value),
      });
    }
    return map;
  }, [sourceTree, sourceSegs]);
  const spaceWarn = useMemo(() => {
    for (const cg of tree.filter((n) => n.type === "group")) {
      const src = srcSpaces.get(cg.ids[0]);
      if (!src) continue;
      const cLo = idxOf.get(cg.members[0]);
      const cHi = idxOf.get(cg.closers[cg.closers.length - 1]);
      const before = segs[cLo - 1]?.value ?? "";
      const after = segs[cHi + 1]?.value ?? "";
      if (src.left && before !== "" && !endsSpace(before)) return true;
      if (src.right && after !== "" && !startsSpace(after)) return true;
    }
    return false;
  }, [tree, srcSpaces, idxOf, segs]);

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
    <div
      style={{
        flex: 1,
        minHeight: 0,
        display: "flex",
        flexDirection: "column",
        gap: "8px",
      }}
    >
      <div
        dir={dir}
        onClick={() => setSelectedChip(null)}
        style={{
          flex: 1,
          minHeight: "120px",
          overflowY: "auto",
          display: "flex",
          flexWrap: "wrap",
          alignContent: "flex-start",
          padding: "4px 8px",
          borderRadius: "8px",
          border: `1px solid ${
            validation.ok ? "var(--p-color-border)" : "var(--p-color-border-critical)"
          }`,
          background: validation.ok
            ? "var(--p-color-bg-surface)"
            : "var(--p-color-bg-surface-critical)",
          fontSize: "13px",
          lineHeight: "26px",
        }}
      >
          {tree.map((node) => {
            if (node.type === "text") return textInput(node);
            if (node.type === "group") {
              const i = idxOf.get(node.members[0]);
              const indices = [...node.members, ...node.closers].map((o) =>
                idxOf.get(o)
              );
              const sel = { anchor: i, indices };
              const sp = srcSpaces.get(node.ids[0]);
              return (
                <Fragment key={`g${i}`}>
                  <span onClick={(e) => e.stopPropagation()}>
                    {sp?.left && <SpaceDot />}
                    <Chip
                      tags={tags}
                      label={groupLabel(node, tags)}
                      hueN={node.ids[0]}
                      title={groupTitle(node, tags)}
                      selected={selectedChip === i}
                      onSelect={() => setSelectedChip(i)}
                      onKeyDown={onChipKeyDown(sel)}
                      onRemove={() => removeStack(indices)}
                      onDragStart={(e) => {
                        e.dataTransfer.setData("text/plain", "");
                        dragRef.current = indices;
                      }}
                      onDragEnd={() => (dragRef.current = null)}
                    />
                  </span>
                  {textInput(node.inner)}
                  {sp?.right && <SpaceDot />}
                </Fragment>
              );
            }
            const i = idxOf.get(node);
            const sel = { anchor: i, indices: [i] };
            return (
              <span key={`c${i}`} onClick={(e) => e.stopPropagation()}>
                <Chip
                  seg={node}
                  tags={tags}
                  selected={selectedChip === i}
                  onSelect={() => setSelectedChip(i)}
                  onKeyDown={onChipKeyDown(sel)}
                  onRemove={() => removeChip(i)}
                  onDragStart={(e) => {
                    e.dataTransfer.setData("text/plain", "");
                    dragRef.current = [i];
                  }}
                  onDragEnd={() => (dragRef.current = null)}
                />
              </span>
            );
          })}
      </div>

      {/* status + missing-formatting dock */}
      <Box padding="200" background="bg-surface-secondary" borderRadius="200">
        <div style={{ display: "flex", flexDirection: "column", gap: "6px" }}>
          <InlineStack gap="300" blockAlign="center" align="start">
            {validation.ok ? (
              <InlineStack gap="100" blockAlign="center">
                <span className="w-4 h-4">
                  <Icon source={CheckIcon} tone="success" />
                </span>
                <Text as="span" variant="bodySm" tone="success">
                  Structure OK
                </Text>
              </InlineStack>
            ) : (
              <InlineStack gap="100" blockAlign="center">
                <span className="w-4 h-4">
                  <Icon source={XCircleIcon} tone="critical" />
                </span>
                <Text as="span" variant="bodySm" tone="critical">
                  {problem}
                </Text>
              </InlineStack>
            )}

            {validation.ok && validation.emptyPairs.length > 0 && (
              <InlineStack gap="100" blockAlign="center">
                <span className="w-3.5 h-3.5">
                  <Icon source={AlertTriangleIcon} tone="caution" />
                </span>
                <Text as="span" variant="bodyXs" tone="caution">
                  "{labelFor(tags, validation.emptyPairs[0])}" tag is empty — it will render with no text.
                </Text>
              </InlineStack>
            )}

            {spaceWarn && (
              <InlineStack gap="100" blockAlign="center">
                <span className="w-3.5 h-3.5">
                  <Icon source={AlertTriangleIcon} tone="caution" />
                </span>
                <Text as="span" variant="bodyXs" tone="caution">
                  Missing space around formatting — text may run together.
                </Text>
              </InlineStack>
            )}

            {flash && (
              <Text as="span" variant="bodyXs" tone="caution">
                {flash}
              </Text>
            )}
          </InlineStack>

          {(dock.length > 0 || looseMissing.length > 0) && (
            <InlineStack gap="200" blockAlign="center" align="start">
              <Text as="span" variant="bodyXs" tone="subdued">
                {dock.length + looseMissing.length} formatting missing — put back:
              </Text>
              {dock.map(({ g, label, ordinal, preview }) => {
                const hue = hueFor(g.ids[0]);
                return (
                  <button
                    key={`d${g.ids[0]}`}
                    type="button"
                    onClick={() => insertStack(g)}
                    title="Insert this formatting stack at the cursor"
                    style={{
                      ...chipBaseStyle(hue),
                      cursor: "pointer",
                      borderStyle: "dashed",
                      height: "auto",
                      padding: "1px 6px",
                    }}
                  >
                    <span className="w-2.5 h-2.5">
                      <Icon source={ReturnIcon} />
                    </span>
                    <span>
                      {label} — {ordinal}
                      {ordinalSuffix(ordinal)}
                    </span>
                    {preview && (
                      <span style={{ opacity: 0.7 }}>· “{truncate(preview, 24)}”</span>
                    )}
                  </button>
                );
              })}
              {looseMissing.map((k) => {
                const [type, nStr] = k.split(":");
                const seg = { type, n: Number(nStr) };
                const hue = hueFor(seg.n);
                return (
                  <button
                    key={`l${k}`}
                    type="button"
                    onClick={() => insertMissing(k)}
                    title="Insert at cursor position"
                    style={{
                      ...chipBaseStyle(hue),
                      cursor: "pointer",
                      borderStyle: "dashed",
                    }}
                  >
                    <span className="w-2.5 h-2.5">
                      <Icon source={ReturnIcon} />
                    </span>
                    {chipText(seg, tags)}
                  </button>
                );
              })}
            </InlineStack>
          )}
        </div>
      </Box>
    </div>
  );
}
