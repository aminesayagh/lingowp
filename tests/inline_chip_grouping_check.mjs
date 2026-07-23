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

const fixtures = [
  TWO_PHRASE, NESTED, SINGLE_WRAP, VOID_BETWEEN, MULTI_CHILD, EMPTY_PAIR, WS_JOINERS,
];

// 1. THE critical test: byte-for-byte round-trip, plain and grouped ----------
console.log("round-trip identity (the gate):");
for (const x of fixtures) {
  ok(`serialize(parseUnit) === x  |  ${x}`, serialize(parseUnit(x)) === x);
  ok(
    `serializeGrouped(groupCoincident(parseUnit)) === x  |  ${x}`,
    serializeGrouped(groupCoincident(parseUnit(x))) === x
  );
}

// 2. Collapse counts ---------------------------------------------------------
console.log("collapse decisions:");
ok("two-phrase → exactly 2 groups", countGroups(groupCoincident(parseUnit(TWO_PHRASE))) === 2);
ok("nested wrappers → exactly 1 group", countGroups(groupCoincident(parseUnit(NESTED))) === 1);
ok("single wrapper → exactly 1 group", countGroups(groupCoincident(parseUnit(SINGLE_WRAP))) === 1);
ok("ws-joined stack → exactly 1 group", countGroups(groupCoincident(parseUnit(WS_JOINERS))) === 1);

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
