/**
 * Standalone check for the display-only chip collapse — no framework.
 * Run: node tests/inline_chip_grouping_check.mjs
 * Exits non-zero if any case fails. The two round-trip identity assertions are
 * the corruption-critical gate: collapse must never change the stored string.
 */

import {
  parseUnit,
  serialize,
  validateStructure,
  groupCoincident,
  hideStructure,
  serializeGrouped,
  normalize,
} from "../admin-ui/screens/inlineTagCodec.mjs";

let failed = 0;
const ok = (label, cond) => {
  if (cond) {
    console.log(`  ok   ${label}`);
  } else {
    console.error(`  FAIL ${label}`);
    failed++;
  }
};

const countGroups = (tree) => tree.filter((n) => n.type === "group").length;

// Fixtures ------------------------------------------------------------------
const TWO_PHRASE = "{1}{2}View my cart{/2}{/1} {3}{4}Go to checkout{/4}{/3}";
const NESTED = "{1}{2}Team{/2}{/1}";
const SINGLE_WRAP = "See {1}this{/1} now";
const VOID_BETWEEN = "{14}{15/}{16}{17/}{/16}{/14}";
const MULTI_CHILD = "{51}{52/}{53}-{/53}{54}+{/54}{/51}";
const EMPTY_PAIR = "before {20}{/20} after";
const WS_JOINERS = "{1} {2} Hi {/2} {/1}"; // whitespace inside/around the stack
const EMPTY_MERGED = "Hi {1}{2}{/2}{/1} {3}{4} {/4}{/3} bye"; // merged, no words inside

// html:26 — a real WooCommerce cart table flattened into one unit (63 markers).
const CART_TABLE =
  "{1} {2} Products in cart {/2} {/1} {3} {4} {5} {6}Product{/6} {/5} {7} {8}Details{/8} {/7} {9} {10}Total{/10} {/9} {/4} {/3} {11} {12} {13} {14} {15/} {16} {17/} {/16} {/14} {18} {19} {20}{/20} {21}{/21} {22} Available on backorder {/22} {23} {24} {25}{/25} {26} Previous price: {/26} {27}{/27} {28} Discounted price: {/28} {29}{/29} {30}{/30} {/24} {31} {32}{/32} {33} {/33} {34}{/34} {/31} {/23} {35} {36} {37}{/37} {/36} {38} {39} {40} {41}{/41} {42}{/42} {43} / {/43} {/40} {/39} {/38} {44} {45} {46} {47}{/47} {48}{/48} {49} / {/49} {/46} {/45} {/44} {/35} {50} {51} {52/} {53} − {/53} {54} ＋ {/54} {/51} {55} {56} {57}{/57} {/56} {/55} {/50} {/19} {/18} {58} {59} {60} {61} {/61} {/60} {62} Save {63}{/63} {/62} {/59} {/58} {/13} {/12} {/11}";

const fixtures = [
  TWO_PHRASE, NESTED, SINGLE_WRAP, VOID_BETWEEN, MULTI_CHILD, EMPTY_PAIR, WS_JOINERS,
  EMPTY_MERGED, CART_TABLE,
];

const hidden = (x) => hideStructure(groupCoincident(parseUnit(x)));
const visibleText = (tree) =>
  tree
    .filter((n) => n.type === "text" || n.type === "group")
    .map((n) => (n.type === "group" ? n.inner.value : n.value))
    .join("")
    .replace(/\s+/g, " ")
    .trim();

// 1. THE critical test: byte-for-byte round-trip, plain, grouped, and hidden ---
console.log("round-trip identity (the gate):");
for (const x of fixtures) {
  const tag = x.length > 48 ? `${x.slice(0, 45)}…` : x;
  ok(`serialize(parseUnit) === x  |  ${tag}`, serialize(parseUnit(x)) === x);
  ok(
    `serializeGrouped(groupCoincident) === x  |  ${tag}`,
    serializeGrouped(groupCoincident(parseUnit(x))) === x
  );
  ok(
    `serializeGrouped(hideStructure(...)) === x  |  ${tag}`,
    serializeGrouped(hidden(x)) === x
  );
}

// 1b. Structure hiding on the cart table ------------------------------------
console.log("structure hiding (cart table):");
{
  const tree = hidden(CART_TABLE);
  const loneChips = tree.filter(
    (n) => n.type === "open" || n.type === "close" || n.type === "void"
  );
  ok("no bare structural chip survives at top level", loneChips.length === 0);
  const words = visibleText(tree);
  ok(
    "the real phrases stay visible",
    ["Products in cart", "Product", "Details", "Total", "Available on backorder",
     "Previous price:", "Discounted price:", "Save"].every((w) => words.includes(w))
  );
}

// 2. Collapse counts ---------------------------------------------------------
console.log("collapse decisions:");
ok("two-phrase → exactly 2 groups", countGroups(groupCoincident(parseUnit(TWO_PHRASE))) === 2);
ok("nested wrappers → exactly 1 group", countGroups(groupCoincident(parseUnit(NESTED))) === 1);
ok("single wrapper → exactly 1 group", countGroups(groupCoincident(parseUnit(SINGLE_WRAP))) === 1);
ok("ws-joined stack → exactly 1 group", countGroups(groupCoincident(parseUnit(WS_JOINERS))) === 1);
ok(
  "empty/whitespace merged stacks are hidden (0 visible groups)",
  countGroups(hidden(EMPTY_MERGED)) === 0
);
ok(
  "empty merged: real words stay visible",
  visibleText(hidden(EMPTY_MERGED)) === "Hi bye"
);

// 3. NO-collapse cases -------------------------------------------------------
console.log("no-collapse cases:");
ok("void between opens → 0 groups", countGroups(groupCoincident(parseUnit(VOID_BETWEEN))) === 0);
ok("multi-child branch → outer not collapsed (only the 2 inner pairs)",
  countGroups(groupCoincident(parseUnit(MULTI_CHILD))) === 2);
ok("lone empty pair → 0 groups", countGroups(groupCoincident(parseUnit(EMPTY_PAIR))) === 0);

// 4. Remove a collapsed stack → all member+closer markers go together --------
console.log("atomic remove:");
{
  const segs = parseUnit(TWO_PHRASE);
  const tree = groupCoincident(segs);
  const group = tree.find((n) => n.type === "group");
  const dropped = new Set([...group.members, ...group.closers]); // same object refs
  const kept = normalize(segs.filter((s) => !dropped.has(s)));
  ok("remove first stack keeps inner text, drops wrapper",
    serialize(kept) === "View my cart {3}{4}Go to checkout{/4}{/3}");

  const v = validateStructure(kept, segs);
  const miss = v.missing.slice().sort().join(",");
  ok("validation reports the removed stack as missing (1,2 open+close)",
    miss === "close:1,close:2,open:1,open:2");

  // re-insert the exact markers → structure valid again
  const restored = normalize([...segs]);
  ok("restoring the exact markers is valid again",
    validateStructure(restored, segs).ok === true);
}

console.log(failed === 0 ? "\nALL PASS" : `\n${failed} FAILED`);
process.exit(failed === 0 ? 0 : 1);
