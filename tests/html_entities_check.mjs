/**
 * Standalone check for the board's display text (issue #11) — no framework.
 * The plugin stores post titles texturized the way a visitor sees them, so
 * "Shipping & Returns" is stored as "Shipping &#038; Returns". The board must
 * show the character, never the code.
 * Run: node tests/html_entities_check.mjs
 * Exits non-zero if any case fails.
 */

import { decodeEntities } from "../admin-ui/screens/htmlEntities.mjs";

let failed = 0;
const check = (label, input, expected) => {
  const got = decodeEntities(input);
  if (got === expected) {
    console.log(`PASS  ${label}`);
    return;
  }
  failed++;
  console.log(`FAIL  ${label}`);
  console.log(`        expected: ${JSON.stringify(expected)}`);
  console.log(`        got:      ${JSON.stringify(got)}`);
};

check("texturized ampersand (the #11 title)", "Shipping &#038; Returns", "Shipping & Returns");
check("texturized quotes and dash", "&#8220;Olive&#8221; &#8211; it&#8217;s", "“Olive” – it’s");
check("hex entity", "Caf&#xe9;", "Café");
check("named entities", "a &amp; b &lt;c&gt; &quot;d&quot; e&nbsp;f", 'a & b <c> "d" e f');
check("decoded once, not twice", "&amp;#038;", "&#038;");
check("unknown name left as-is", "&bogus; &", "&bogus; &");
check("plain text untouched", "Visit the shop", "Visit the shop");
check("null passes through", null, null);

console.log(failed === 0 ? "\nall passed" : `\n${failed} FAILED`);
process.exit(failed === 0 ? 0 : 1);
