````md
# TransLangs Admin UI — Translation Board Requirements

## 1. Main Goal

The admin interface must help the user translate website content by **source type**.

The user should be able to:

- Select a target language.
- Browse translation rows by content/source type.
- See discovered, translated, and review-required texts in the same tab.
- Edit translations inline.
- Open the frontend page where the text appears.
- Translate the same string across all target languages from one drawer.
- See attachment/image previews in the attachment tab.

---

# 2. Global Page Layout

```txt
┌──────────────────────────────────────────────────────────────────────┐
│ TransLangs — Translation Memory                                      │
├──────────────────────────────────────────────────────────────────────┤
│ Target language: [ French ▼ ]                                        │
│ Search original:    [_________________________]                      │
│ Search translation: [_________________________]                      │
│                                                                      │
│ Summary:  Discovered: 42   Translated: 120   Needs review: 8         │
└──────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────┐
│ [ Attachments ] [ Pages ] [ Posts ] [ Products ] [ Menus ]           │
│ [ Blocks ] [ Templates ] [ CPTs ] [ Theme / Plugin strings ]         │
└──────────────────────────────────────────────────────────────────────┘
````

---

# 3. Top Filters

The top filter area is always visible.

```txt
┌──────────────────────────────────────────────────────────────┐
│ Target language      [ French ▼ ]                            │
│ Search original      [____________________________]           │
│ Search translation   [____________________________]           │
└──────────────────────────────────────────────────────────────┘
```

## Rules

* Target language is required.
* The default/source language must never appear as a target option.
* Search original filters by source/original text.
* Search translation filters by translated text.
* Later, advanced filters can be added for context/source/status.

---

# 4. Source Type Tabs

The page contains one tab per source group.

```txt
┌──────────────────────────────────────────────────────────────────────┐
│ Attachments | Pages | Posts | Products | Menus | Blocks | Templates │
│ CPTs | Theme / Plugin strings                                        │
└──────────────────────────────────────────────────────────────────────┘
```

## Recommended Tabs

```txt
Attachments
Pages
Posts
Products
Menus
Blocks
Templates
Custom Post Types
Theme / Plugin strings
```

## Behavior

* Only show tabs that have translation rows.
* Products tab appears only if WooCommerce/product rows exist.
* Theme / Plugin strings appears when gettext/i18n capture exists.

---

# 5. Inside Each Tab

Each tab contains the three status sections on the same screen.

No nested sub-tabs.

```txt
Selected Tab: Pages

┌──────────────────────────────────────────────────────────────────────┐
│ Discovered                                                           │
└──────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────┐
│ Translated                                                           │
└──────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────┐
│ Needs Review                                                         │
└──────────────────────────────────────────────────────────────────────┘
```

---

# 6. Standard Table Layout

For most source types:

```txt
┌──────────────────────────────┬──────────────────────────────┬──────────────┐
│ Original text                │ Translation text             │ Action       │
├──────────────────────────────┼──────────────────────────────┼──────────────┤
│ Contact us                   │ [ Contactez-nous ________ ]  │ [Save]       │
│ Page: Home · hero_button     │                              │ [View text]  │
│                              │                              │ [More ▼]     │
└──────────────────────────────┴──────────────────────────────┴──────────────┘
```

## Columns

### Original text

Contains:

```txt
Original text
Source label
Context / field name
```

Example:

```txt
Contact us
Page: Home · Field: hero_button
```

### Translation text

Contains an editable input or textarea.

```txt
[ Contactez-nous________________ ]
```

For long text:

```txt
[ Expand editor ]
```

### Action

Contains main row actions.

```txt
[Save] [View text] [More ▼]
```

---

# 7. Discovered Section

Status:

```txt
status = discovered
```

Layout:

```txt
┌──────────────────────────────────────────────────────────────────────┐
│ Discovered                                                           │
├──────────────────────────────┬──────────────────────────────┬──────────────┤
│ Original text                │ Translation text             │ Action       │
├──────────────────────────────┼──────────────────────────────┼──────────────┤
│ About us                     │ [________________________]   │ [Save]       │
│ Page: About · post_title     │                              │ [View text]  │
│                              │                              │ [More ▼]     │
└──────────────────────────────┴──────────────────────────────┴──────────────┘
```

## Actions

```txt
Save translation
Ignore
Mark as needs review
View text
All languages
```

---

# 8. Translated Section

Statuses:

```txt
status = machine
status = reviewed
```

Layout:

```txt
┌──────────────────────────────────────────────────────────────────────┐
│ Translated                                                           │
├──────────────────────────────┬──────────────────────────────┬──────────────┤
│ Original text                │ Translation text             │ Action       │
├──────────────────────────────┼──────────────────────────────┼──────────────┤
│ Add to cart                  │ [ Ajouter au panier _____ ]  │ [Validate]   │
│ WooCommerce · gettext        │ Badge: Machine               │ [View text]  │
│                              │                              │ [More ▼]     │
└──────────────────────────────┴──────────────────────────────┴──────────────┘
```

## Actions

```txt
Save changes
Validate, if machine translation
Mark as needs review
Ignore
View text
All languages
```

---

# 9. Needs Review Section

Status:

```txt
status = needs_review
```

Layout:

```txt
┌──────────────────────────────────────────────────────────────────────┐
│ Needs Review                                                         │
├──────────────────────────────┬──────────────────────────────┬──────────────┤
│ Original text                │ Translation text             │ Action       │
├──────────────────────────────┼──────────────────────────────┼──────────────┤
│ Free                         │ [ Gratuit _______________ ]  │ [Validate]   │
│ Product template · label     │                              │ [View text]  │
│                              │                              │ [More ▼]     │
└──────────────────────────────┴──────────────────────────────┴──────────────┘
```

## Actions

```txt
Validate
Save changes
Ignore
View text
All languages
```

---

# 10. Attachment Tab Layout

The attachment tab has one additional column: image preview.

```txt
┌──────────┬──────────────────────────────┬──────────────────────────────┬──────────────┐
│ Image    │ Original text                │ Translation text             │ Action       │
├──────────┼──────────────────────────────┼──────────────────────────────┼──────────────┤
│ [thumb]  │ Team photo                   │ [ Photo de l'équipe ____ ]   │ [Save]       │
│          │ Attachment #55 · alt         │                              │ [View text]  │
│          │                              │                              │ [More ▼]     │
└──────────┴──────────────────────────────┴──────────────────────────────┴──────────────┘
```

## Attachment Contexts

```txt
title
alt
caption
description
```

## Image Preview Behavior

* Show thumbnail.
* Clicking the thumbnail can open the WordPress media modal or attachment edit page.
* `View text` should open the frontend page where this image/text was last seen.
* If no frontend page is known, fallback to attachment edit page.

---

# 11. Row Action Model

To keep the interface clean, use:

```txt
Primary button
Secondary button
More dropdown
```

Example:

```txt
[Save] [View text] [More ▼]
```

Dropdown:

```txt
Ignore
Mark as needs review
Translate all languages
Apply same translation elsewhere
```

## Action by Status

| Section             | Main Action | Secondary | More                                     |
| ------------------- | ----------- | --------- | ---------------------------------------- |
| Discovered          | Save        | View text | Ignore, Need review, All languages       |
| Translated machine  | Validate    | View text | Save, Need review, Ignore, All languages |
| Translated reviewed | Save        | View text | Need review, Ignore, All languages       |
| Needs review        | Validate    | View text | Save, Ignore, All languages              |

---

# 12. View Text Action

The `View text` action opens the frontend page where the text appears.

## Goal

```txt
Click View text
→ open frontend page
→ scroll to the text
→ highlight the text
```

## URL Format

Preferred:

```txt
/fr/about/?translangs_focus={translation_id}
```

Example:

```txt
/fr/about/?translangs_focus=802
```

## Frontend Behavior

```txt
1. User clicks View text.
2. A new tab opens the frontend page.
3. The page detects translangs_focus.
4. The plugin checks user permission.
5. The target text receives a temporary DOM marker.
6. JavaScript scrolls to the marker.
7. The text is highlighted.
```

## Highlight Example

```txt
┌──────────────────────────────────────────────┐
│                                              │
│      Contactez-nous                          │
│      ^ highlighted translation target        │
│                                              │
└──────────────────────────────────────────────┘
```

## Locator Priority

```txt
1. data-translangs-row marker
2. stored selector, if available
3. translated text match
4. original text match
5. first visible match fallback
```

---

# 13. Multi-Language Drawer

If the site has more than one target language, show:

```txt
[All languages]
```

This opens a drawer.

```txt
┌──────────────────────────────────────────────┐
│ Translate in all languages                   │
├──────────────────────────────────────────────┤
│ Original text                                │
│ Contact us                                   │
│                                              │
│ French                                       │
│ [ Contactez-nous_____________________ ]      │
│ Status: Reviewed                            │
│                                              │
│ Spanish                                      │
│ [ Contáctenos________________________ ]      │
│ Status: Discovered                          │
│                                              │
│ Arabic                                       │
│ [ اتصل بنا___________________________ ]      │
│ Status: Needs review                        │
│                                              │
│ [Save all] [Cancel]                          │
└──────────────────────────────────────────────┘
```

## Drawer Identity

The drawer groups rows by:

```txt
same source_type
same source_id
same context
same original_hash
different target_lang
```

## Drawer Actions

```txt
Save all
Cancel
Validate all, optional later
Ignore per language, optional later
```

---

# 14. Long Text Handling

For long original or translated text, the row should be collapsed.

```txt
Original text:
We help companies build better digital experiences...
[Expand]
```

Expanded view:

```txt
┌──────────────────────────────────────────────┐
│ Full original text                           │
│                                              │
│ We help companies build better digital...    │
│                                              │
│ Translation                                  │
│ [ Large textarea                         ]   │
│                                              │
│ [Save] [Cancel]                              │
└──────────────────────────────────────────────┘
```

---

# 15. Empty State

If a section has no rows:

```txt
┌──────────────────────────────────────────────┐
│ Discovered                                   │
├──────────────────────────────────────────────┤
│ No discovered texts for this source type.    │
└──────────────────────────────────────────────┘
```

If a tab has no rows:

```txt
┌──────────────────────────────────────────────┐
│ No translations found for this source type.  │
│ Run a scan to discover content.              │
│                                              │
│ [Run scan]                                   │
└──────────────────────────────────────────────┘
```

---

# 16. Final UI Summary

```txt
Translation Memory Page
│
├── Top Filters
│   ├── Target language, required
│   ├── Search original
│   └── Search translation
│
├── Source Tabs
│   ├── Attachments
│   ├── Pages
│   ├── Posts
│   ├── Products
│   ├── Menus
│   ├── Blocks
│   ├── Templates
│   ├── Custom Post Types
│   └── Theme / Plugin strings
│
└── Selected Source Tab
    ├── Discovered section
    │   └── Original text | Translation text | Action
    │
    ├── Translated section
    │   └── Original text | Translation text | Action
    │
    └── Needs Review section
        └── Original text | Translation text | Action
```

Attachment tab:

```txt
Image | Original text | Translation text | Action
```

Core row actions:

```txt
Save
Validate
Ignore
Need review
View text
All languages
```

Important UX rule:

```txt
One source tab.
Three stacked status sections.
Inline translation.
Contextual frontend preview through View text.
Multi-language editing through drawer.
```

```
```
