# TransLangs — Admin Experience Plan (Wireframes + Use Cases) — v2

Audience for the UI: **business owners and marketers**, not developers. Labels
use plain content language (Pages, Products, "Theme & button text", "Check for
new text", "Don't translate"). Architecture terms appear only in parentheses
for the implementer.

### What changed from v1 (read first)
- **The list IS the editor.** No per-row "Translate" button that opens a
  separate screen. Each string is a row: **original on the left, translation
  input on the right, equal width, edited inline**, confirmed with a per-row
  **Valid** button. The user stays on one page and works straight down.
- **No before/after diff, no versioning.** The database stores only whether
  the original changed (`current_hash` vs `translated_against_hash`), never
  the old wording. "Original changed" is shown as a small **⚠ changed** chip
  on the row — nothing more. (Old wireframe A5 is deleted.)
- **Minimise page changes.** Everything routine happens in the list. Modals
  exist only for genuinely cross-cutting actions (all-languages,
  apply-everywhere, import) — never for normal translating.
- **Sizing is specified** on every component (see Layout Rules).

### Global layout rules (apply to every screen)
```
• Sidebar: width 240px FIXED. Main panel: flex:1 (fills the rest).
• The two editing columns (Original | Translation): each flex:1 1 50%,
  ALWAYS equal width; 16px gutter between them.
• Short string row: single line; white-space:nowrap; overflow → ellipsis;
  text-decoration:underline; full text on hover (title attr) or ⌄ expand.
• Long string row (post_content etc.): adaptive paired box — both sides grow
  to equal height together; ⌄ expand toggles taller IN PLACE, never a page
  change.  [DEFAULT — confirm: adaptive vs expand-on-demand]
• [Valid] per row = save this translation AND mark it done/reviewed in one
  click.  [DEFAULT — confirm: one-click-done vs save-as-machine]
• Row state chip: ● new (untranslated) · ✓ done · ⚠ changed (original moved).
• Inline formatting always shows as readable chips ⟦bold⟧ ⟦link⟧ ⟦code⟧ on
  BOTH sides — never raw {1}…{/1} placeholders.
• Group menu [⋯] holds per-item actions (Preview · All languages · Don't
  translate · Apply everywhere) at the GROUP level, not on every tiny row.
```

Four product defaults remain assumed (Part C); flagged where they appear.

---

## PART A — ASCII WIREFRAMES

### A1. Shell (240px sidebar + main)

```
┌─ 240px ───────────┬────────────────────────────────────────────────────────┐
│ TransLangs        │                                          [Settings][Help]│
│                   │   (main panel — changes per section)                     │
│ CONTENT           │                                                          │
│  ▸ Pages          │                                                          │
│  ▸ Posts          │                                                          │
│  ▸ Products       │                                                          │
│  ▸ Categories     │                                                          │
│  ▸ Media          │                                                          │
│  ▸ Theme &        │                                                          │
│    button text    │                                                          │
│  ▸ Templates      │                                                          │
│                   │                                                          │
│ SITE              │                                                          │
│  ▸ Menus &        │                                                          │
│    Branding       │                                                          │
│                   │                                                          │
│ MANAGE            │                                                          │
│  ▸ Don't-translate│                                                          │
│  ▸ Import / Export│                                                          │
└───────────────────┴────────────────────────────────────────────────────────┘
   FIXED 240px         FLEX:1
```

### A2. Content list = inline editor (e.g. Pages) — the main screen

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Pages                                                  [ Check for new text ] │
├────────────────────────────────────────────────────────────────────────────┤
│ Translate to:[French▼]   Search original[________]   Search translated[_____] │
│ Origin:[All▼]  Sort:[Recently changed▼]                                       │
├────────────────────────────────────────────────────────────────────────────┤
│ ● To translate (38)   ○ To review (12)   ○ Done (84)                          │
│ ███████████░░░░░  84 of 134 done · 12 to review   (French)                    │
├────────────────────────────────────────────────────────────────────────────┤
│ ABOUT US  (page)                                                  [ ⋯ group ] │
│ ┌──────────────────────────────────┬──────────────────────────────────┐      │
│ │ ORIGINAL                 (50%)   │ FRENCH                    (50%)   │      │
│ ├──────────────────────────────────┼──────────────────────────────────┤      │
│ │ About Us                         │ [ À propos____________________ ] │ ✓done│
│ │ ‹nowrap · underline›             │                         [ Valid ]│      │
│ ├──────────────────────────────────┼──────────────────────────────────┤      │
│ │ Get in touch                     │ [ Contactez-nous_____________ ]  │ ●new │
│ │                                  │                         [ Valid ]│      │
│ ├──────────────────────────────────┼──────────────────────────────────┤      │
│ │ We are a team of ⟦bold⟧designers │ Nous sommes une équipe de        │      │
│ │ ⟦/bold⟧ and builders based in    │ ⟦bold⟧designers⟦/bold⟧ et de     │      │
│ │ Lyon.                     ⌄ less │ bâtisseurs à Lyon.               │      │
│ │ ‹adaptive paired box, equal H›   │ [ ……………………………………… ]              │      │
│ │                                  │            ⟦bold⟧ ✓   [ Valid ]  │ ✓done│
│ └──────────────────────────────────┴──────────────────────────────────┘      │
│                                                                              │
│ PRICING  (page)                                                   [ ⋯ group ] │
│ ┌──────────────────────────────────┬──────────────────────────────────┐      │
│ │ Pricing                          │ [ Tarifs__________________ ]     │ ●new │
│ │                                  │                         [ Valid ]│      │
│ └──────────────────────────────────┴──────────────────────────────────┘      │
│                                                                              │
│ ◀ Prev    Page 1 of 4    Next ▶                       Showing 1–10 of 38     │
└────────────────────────────────────────────────────────────────────────────┘

[ ⋯ group ] menu:  Preview on site · Translate all languages ·
                   Apply a translation everywhere · Don't translate
```

### A3. "To review" tab — inline, changed-state is just a chip (NO diff)

```
├────────────────────────────────────────────────────────────────────────────┤
│ ○ To translate (38)   ● To review (12)   ○ Done (84)                          │
├────────────────────────────────────────────────────────────────────────────┤
│ PRICING  (page)                                                   [ ⋯ group ] │
│ ┌──────────────────────────────────┬──────────────────────────────────┐      │
│ │ Our Pricing            ⚠ changed │ [ Tarifs__________________ ]     │ ⚠    │
│ │ ‹current original only›          │   [ Keep & approve ]    [ Valid ]│      │
│ └──────────────────────────────────┴──────────────────────────────────┘      │
│ ABOUT US  (page)                                                  [ ⋯ group ] │
│ ┌──────────────────────────────────┬──────────────────────────────────┐      │
│ │ Get in touch        flagged by 👤│ [ Contactez-nous_____________ ]  │ ⚠    │
│ │                                  │                         [ Valid ]│      │
│ └──────────────────────────────────┴──────────────────────────────────┘      │
└────────────────────────────────────────────────────────────────────────────┘
  ⚠ changed  = original moved since translation (current_hash ≠ translated_against_hash)
  flagged 👤 = a person sent it to review (status needs_review)
  Left side ALWAYS shows the CURRENT original. There is no "was: …" line.
  Keep & approve = accept current translation for the new wording (one click).
```

### A4. Not-yet-scanned vs truly empty

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Products                                               [ Check for new text ] │
├────────────────────────────────────────────────────────────────────────────┤
│            We haven't found any text in Products yet.                         │
│                       [  Check for new text  ]                                │
│                                                                              │
│   (truly empty instead:  "Everything in Products is translated. 🎉")          │
└────────────────────────────────────────────────────────────────────────────┘
```

### A5. Translate-into-all-languages modal (per record, from [⋯])

Intentionally ignores the top language filter — this view is all-languages.

```
        ┌──────────────────────────────────────────────────────────────┐
        │ Translate into all languages                             [ × ]│
        ├──────────────────────────────────────────────────────────────┤
        │ Original:  Get in touch                                       │
        │ ───────────────────────────────────────────────────────────  │
        │ French   [ Contactez-nous______________________ ]            │
        │ Spanish  [ Ponte en contacto_________________ ]              │
        │ German   [ ____________________________________ ] (empty)    │
        │ Arabic   [ تواصل معنا______________________ ] (rtl)        │
        │ ───────────────────────────────────────────────────────────  │
        │ Empty languages stay untranslated. Saved → "to review".       │
        │ [DEFAULT — confirm Q2a]                  [ Cancel ] [Save all] │
        └──────────────────────────────────────────────────────────────┘
        width ~560px, centred; fields full width; per-field dir for rtl
```

### A6. Apply-a-translation-everywhere modal (from [⋯])

```
        ┌──────────────────────────────────────────────────────────────┐
        │ Apply a translation everywhere                           [ × ]│
        ├──────────────────────────────────────────────────────────────┤
        │ Original:    Read more                                        │
        │ Translation: Lire la suite   (French)                         │
        │                                                              │
        │ This exact text appears in 7 other places in Pages.          │
        │ [DEFAULT — confirm Q1: same words + same content area]        │
        │ Existing different translations:  ( ) keep   (•) overwrite    │
        │                                          [ Cancel ] [ Apply ] │
        └──────────────────────────────────────────────────────────────┘
```

### A7. Media list — same inline editor + preview column

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Media                                                  [ Check for new text ] │
│ Translate to:[French▼]  Search original[______]                              │
│ ● To translate (9)  ○ To review (1)  ○ Done (24)                              │
├────────────────────────────────────────────────────────────────────────────┤
│ ┌────┐ hero-team.jpg                                              [ ⋯ group ] │
│ │IMG │ ┌─────────────────────────────┬─────────────────────────────┐         │
│ │64px│ │ Our team in Lyon            │ [ Notre équipe à Lyon____ ] │ ✓done   │
│ └────┘ │                             │                    [ Valid ]│         │
│        ├─────────────────────────────┼─────────────────────────────┤         │
│        │ Team photo, office          │ [ ______________________ ]  │ ●new    │
│        │ ‹alt text›                  │                    [ Valid ]│         │
│        └─────────────────────────────┴─────────────────────────────┘         │
│ ┌────┐ brochure.pdf                                               [ ⋯ group ] │
│ │ 📄 │ ┌─────────────────────────────┬─────────────────────────────┐         │
│ │icon│ │ 2025 Brochure               │ [ Brochure 2025_________ ]  │ ●new    │
│ └────┘ └─────────────────────────────┴─────────────────────────────┘         │
└────────────────────────────────────────────────────────────────────────────┘
  preview cell: 64px square; image thumbnail or file-type icon
```

### A8. Menus & Branding (own page, same inline pattern)

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Menus & Branding                                       Translate to:[French▼] │
├────────────────────────────────────────────────────────────────────────────┤
│ SITE IDENTITY                                                                 │
│ ┌──────────────────────────────────┬──────────────────────────────────┐      │
│ │ Acme Co                          │ [ Acme Co_____________________ ] │ ✓done│
│ │ Build better                     │ [ Construisez mieux___________ ] │ ✓done│
│ └──────────────────────────────────┴──────────────────────────────────┘      │
│ MAIN MENU                                                                     │
│ ┌──────────────────────────────────┬──────────────────────────────────┐      │
│ │ Home                             │ [ Accueil_____________________ ] │ ✓done│
│ │ Shop                             │ [ Boutique____________________ ] │ ●new │
│ │ Contact                          │ [ Contact_____________________ ] │ ✓done│
│ └──────────────────────────────────┴──────────────────────────────────┘      │
│ FOOTER MENU                                                                   │
│ ┌──────────────────────────────────┬──────────────────────────────────┐      │
│ │ Privacy                          │ [ Confidentialité_____________ ] │ ●new │
│ └──────────────────────────────────┴──────────────────────────────────┘      │
│                                                                 [ Save all ]  │
└────────────────────────────────────────────────────────────────────────────┘
```

### A9. Don't-translate page

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Don't-translate    These texts always show in their original form.            │
├────────────────────────────────────────────────────────────────────────────┤
│ ☐  Acme Co          (brand name)            [ Put back into translation ]     │
│ ☐  #FF6600          (Pricing › meta)        [ Put back into translation ]     │
│ ☐  SKU-12345        (Product › meta)        [ Put back into translation ]     │
│ ──────────────────────────────────────────────────────────────────────────  │
│ [ Select all ]                 [ Put selected back into translation ]         │
└────────────────────────────────────────────────────────────────────────────┘
```

### A10. Import / Export page

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Import / Export                                                               │
├────────────────────────────────────────────────────────────────────────────┤
│ EXPORT TO EXCEL                                                               │
│  Content area:[All▼]   Languages:[☑French ☑Spanish ☐German]                  │
│  One row per original text · one column per language.   [ Download Excel ]    │
│ ──────────────────────────────────────────────────────────────────────────  │
│ IMPORT FROM EXCEL                                                             │
│  Upload the same file with translations filled in.                            │
│  [ Choose file ]  brochure_translations.xlsx                                  │
│  Imported translations → "to review". [DEFAULT — confirm Q2a]                 │
│                                          [ Preview import ]  [ Import ]        │
└────────────────────────────────────────────────────────────────────────────┘
```

### A11. Bulk actions bar (appears when rows are checked, in-list)

```
├────────────────────────────────────────────────────────────────────────────┤
│ ☑ 14 selected   [ Machine-translate ] [ Mark reviewed ] [ Don't translate ]  │
│                 [ Clear ]                                   [ Deselect all ]  │
└────────────────────────────────────────────────────────────────────────────┘
  selection checkbox at the left edge of each row; bar is sticky to the top
```

### A12. Unsaved-changes guard (inline editing → guard on navigation)

```
        ┌──────────────────────────────────────────────────────────────┐
        │ You have unsaved changes                                 [ × ]│
        │ Your translation of "Intro text" isn't saved yet.            │
        │                          [ Stay ]   [ Leave without saving ] │
        └──────────────────────────────────────────────────────────────┘
  Triggered on tab switch, language switch, pagination, or leaving the page
  while any row has an unconfirmed (not-yet-Valid) edit.
```

---

## PART B — CONSOLIDATED USE-CASE LIST

Status: **[CORE]** approved · **[DEFAULT — confirm]** assumed pending Q1–Q3.
Architecture mapping in (parentheses).

### B1. Choosing what to work on
- UC-AD1 **[CORE]** Choose a content area: Pages, Posts, Products, Categories
  & groupings, Media, Theme & button text, Templates. (types 1–5 + media)
- UC-AD2 **[CORE]** Open a specific item/group within an area.
- UC-AD3 **[CORE]** Records grouped by the item they belong to — one block per
  page/product, title + all its fields together. (group by owner_id; media by
  attachment_id)
- UC-AD4 **[CORE]** Three tabs per area, paginated, each with a count: To
  translate / To review / Done. (To translate = no translation row; To review
  = needs_review OR original-changed; Done = machine/reviewed and not changed)
- UC-AD5 **[CORE]** Menus & Branding is a separate page (site name, tagline,
  menu labels) — same inline editor pattern, not mixed with content.
- UC-AD6 **[CORE]** Don't-translate items live on their own page.
- UC-AD7 **[CORE]** Empty areas self-explain: "not found yet → Check for new
  text" vs "everything translated."

### B2. Finding text
- UC-AD8 **[CORE]** Persistent filter bar: target language (always set),
  search original, search translated. (LIKE on the two text columns)
- UC-AD9 **[CORE]** "Check for new text" runs a fresh scan for text not yet
  listed. (on-demand discovery; pipeline Path A/B + marking-mode parse)
- UC-AD10 **[CORE]** Filter To review by reason: ⚠ original changed vs flagged
  by a person. (current_hash≠translated_against_hash vs status=needs_review)
- UC-AD11 **[CORE]** Filter machine vs human; sort by recently changed.
  (provider_id, status, updated_at)

### B3. Translating (all inline — no page change)
- UC-AD12 **[CORE]** Translate inline in the list: original left, translation
  input right, equal width, per-row **Valid** to confirm. No separate editor
  screen.
- UC-AD13 **[CORE]** **(revised — no versioning)** An item whose original
  changed since translation shows a **⚠ changed** chip; the left side shows
  the *current* original. There is NO before/after and NO old text stored.
- UC-AD14 **[CORE]** Inline formatting shows as locked chips ⟦bold⟧⟦link⟧⟦code⟧
  on both sides; can be moved, not broken; save validates all are present.
  (the {n} placeholder contract — type-5 spec §5)
- UC-AD15 **[CORE]** One-click machine-translate **suggestion** drafts into the
  right-hand field for the human to edit then Valid.
- UC-AD16 **[CORE]** Per-record "Translate into all languages" modal (from
  [⋯]): one original, a field per language; saves each filled language; empties
  left untranslated; **intentionally ignores the top language filter**.

### B4. Actions on a piece of text
- UC-AD17 **[DEFAULT — confirm Q1]** "Apply a translation everywhere": reuse
  one translation across records with the **same exact original words + same
  content area**; modal shows count and keep-vs-overwrite. (match by
  current_hash + type)
- UC-AD18 **[CORE]** "Preview on site" (from [⋯]): open the live page where the
  text appears; type-5 highlights by selector. (permalink / source_url+selector)
- UC-AD19 **[CORE]** "Don't translate": text stays original in all languages.
  (source status=ignored; deletes its translation rows)
- UC-AD20 **[DEFAULT — confirm Q2b]** "Keep & approve" on a ⚠ changed row:
  accept the current translation for the new wording in one click; live site
  keeps showing it meanwhile. (set translated_against_hash=current_hash,
  status→reviewed)
- UC-AD21 **[CORE]** "Send to review": flag a finished translation. (status→
  needs_review)
- UC-AD22 **[CORE]** "Clear translation": remove it; back to untranslated.
  (delete the (source,lang) row)
- UC-AD23 **[CORE]** "Copy from another language": prefill the field from an
  already-translated language.

### B5. Working in bulk
- UC-AD24 **[CORE]** Multi-select rows → machine-translate / mark reviewed /
  don't-translate / clear together. (batched Action Scheduler)
- UC-AD25 **[CORE]** Bulk "put back into translation" from the Don't-translate
  page.
- UC-AD26 **[CORE]** Per-area progress line for the selected language: "84 of
  134 done · 12 to review."

### B6. Working outside the tool (Excel)
- UC-AD27 **[CORE]** Export to Excel: one row per original, first column
  original, one column per language. (includes a hidden source_key column)
- UC-AD28 **[DEFAULT — confirm Q3]** Import the filled Excel back, with a
  preview of changes before applying; imported rows → to review.

### B7. Media
- UC-AD29 **[CORE]** Media area shows a 64px preview thumbnail (image) or
  file-type icon beside each item's title/alt/caption.

### B8. Safety & consistency
- UC-AD30 **[CORE]** Warn before leaving / switching tab / switching language /
  paginating while any row has an unconfirmed edit.
- UC-AD31 **[CORE]** Warn when two people edit the same string (don't silently
  overwrite). (compare updated_at on save → 409)
- UC-AD32 **[CORE]** Everything respects the currently selected language;
  switching language keeps the current area/scroll. Exception: the per-record
  all-languages modal (UC-AD16).

### Out of scope for v1 (parked)
Translation memory / fuzzy reuse · glossary enforcement · translator roles &
permissions · translation version history. (The last is explicitly excluded —
the schema stores no previous text, by design.)

---

## PART C — Open decisions baked as defaults (flip any)

| Q | Default in this plan | Alternative |
|---|---|---|
| Long-text rows | Adaptive paired box, both sides grow equally (Layout Rules, A2) | Expand-on-demand only |
| [Valid] button | Save **and** mark done/reviewed in one click | Save, leave as machine until separate review |
| Q1 — Apply-everywhere scope | Same words **+ same content area** (UC-AD17, A6) | Same words across ALL areas |
| Q2a — modal/bulk/import save status | **To review** (A5, A10, A11, UC-AD16/24/28) | Mark **Done** directly |
| Q2b — ⚠ changed on live site | **Keep showing** old translation until approved (UC-AD20, A3) | Show original until approved |
| Q3 — Excel | **Export + Import** round trip (B6, A10) | Export only |

Confirm or flip and the plan is final.
