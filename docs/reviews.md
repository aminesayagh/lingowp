  🟡 Order-dependency risk: Classification only works if the structured scan has already run. On the very first render of a product page — before any scan
  — findStructuredIdentitiesByHashes() returns nothing, so "Black Shoes" (the post_title) falls to Priority 3 and is stored as (product, 0, h1) template
  chrome. When the structured scan later runs and creates (product, 123, post_title), both rows coexist — the old chrome row gets its own discovered entry
  that will never match the structured translation. This is not a code bug but the recommended operational order is: run structured scan before first
  front-end visit or before the archetype scan.

  --------------------------------


    🟡 Minor gap: readFilters() exposes source_lang, target_lang, and text search — but NOT source_type or context. The repository's buildWhere() supports
  both, so the admin table can't filter by "show only media strings" or "show only product fields" via URL. Not a bug, but a UX gap for Phase 9 (the admin
  scan page should add these filter params).