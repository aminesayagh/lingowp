Yes. Before implementation, I would describe the **multi-step parsing plan** as a controlled pipeline, not as “parse every page and hope we catch everything.”

The goal is:

```txt id="s7fof5"
Scan structured sources first.
Parse rendered pages only to discover what structured scanning missed.
Classify every discovered text by source_type + source_id + context.
Avoid requiring the user to manually visit 1000 products.
```

This fits your approved architecture: the plugin keeps WordPress content as the source of truth, uses URL-based translated pages, captures rendered HTML, extracts strings, and stores translations locally. 

---

# 1. The global idea

Your parser should not be the first tool.
It should be the **final auditor**.

The correct order is:

```txt id="7vpkxi"
1. Source scan
2. Structured content scan
3. Template archetype scan
4. Rendered page audit
5. SEO/URL scan
6. Dynamic integration scan
7. Final missing-string report
```

Meaning:

```txt id="m7jgm7"
The database is filled first from known WordPress sources.
Then the HTML parser detects only the remaining visible strings.
```

This avoids parsing thousands of similar pages.

---

# 2. Step 1 — Source scan: reusable/global elements

## Objective

Scan elements that are reused across the whole site.

These should be translated before posts/products/pages because many content objects reference them.

## Sources

```txt id="a2jh6v"
Media library
Taxonomies
Menus
Global options
Widgets
Reusable blocks
Navigation blocks
Footer/header builder global sections
```

## Examples

### Media

```txt id="7fwzic"
source_type = media
source_id   = attachment_id
context     = alt
```

Other contexts:

```txt id="24469o"
title
caption
description
```

### Taxonomies

```txt id="obyq2a"
source_type = taxonomy
source_id   = term_id
context     = category.name
```

Other contexts:

```txt id="50c3g2"
taxonomy.description
product_cat.name
product_tag.name
pa_color.name
```

### Menus

```txt id="mhd2ua"
source_type = menu_item
source_id   = menu_item_id
context     = title
```

### Options/widgets/global blocks

```txt id="k3b70o"
source_type = global_option
source_id   = 0
context     = site_tagline
```

```txt id="qvlxb3"
source_type = widget
source_id   = widget_instance_id
context     = title
```

## Result

Your translation table now contains reusable references.

This means when you scan products later, product categories/media references do not need to create new translation work.

---

# 3. Step 2 — Structured content scan: pages, posts, products, CPTs, ACF

## Objective

Scan all content objects directly from WordPress data, without visiting frontend URLs.

This is where you avoid the “1000 product pages” problem.

## Sources

```txt id="m0tmuw"
Pages
Posts
Products
CPT_{name}
Post meta
ACF fields
WooCommerce fields
Excerpts
Custom fields
```

## Examples

### Page

```txt id="vwnbqy"
source_type = page
source_id   = 42
context     = post_title
```

```txt id="gwnnri"
source_type = page
source_id   = 42
context     = post_content
```

### Post

```txt id="uubt4d"
source_type = post
source_id   = 88
context     = post_excerpt
```

### Product

```txt id="uygpkh"
source_type = product
source_id   = 125
context     = post_title
```

```txt id="rl2w5h"
source_type = product
source_id   = 125
context     = short_description
```

```txt id="mky3os"
source_type = product
source_id   = 125
context     = product_description
```

### ACF

```txt id="snrc59"
source_type = acf
source_id   = 125
context     = field_product_subtitle
```

## Important rule

Do not add media/taxonomy/menu text again as product text.

Example:

```txt id="j5uoup"
Product has category: Shoes
Product has image alt: Black leather shoes
```

Those belong to:

```txt id="o5m1wk"
taxonomy
media
```

not to:

```txt id="e2w1sx"
product
```

So your scanner should separate:

```txt id="018zvc"
owned fields
referenced fields
```

The product owns:

```txt id="klf8nx"
title
description
short description
custom fields
ACF fields
```

The product references:

```txt id="nnbbbs"
media
taxonomies
attributes
brand terms
```

Referenced objects are already scanned separately.

---

# 4. Step 3 — Template archetype scan

## Objective

Do not render every single post/product.
Render a small number of representative pages per layout type.

This is the key optimization.

## Why

If you have 1000 products, most product pages share the same template.

So you do not need:

```txt id="lnu3q3"
Parse 1000 product pages
```

You need:

```txt id="yo8o8s"
Parse 3 to 8 representative product pages
```

## Archetypes

Create a list like:

```txt id="8xateq"
front_page
standard_page
single_post
post_archive
category_archive
search
404
single_product
product_archive
product_category
cart
checkout
my_account
single_cpt_{type}
archive_cpt_{type}
```

## Product archetype samples

For WooCommerce:

```txt id="wdlb1o"
simple product
variable product
sale product
out-of-stock product
product with reviews
product with grouped/bundled behavior, if exists
```

If a string appears on these pages but was not found in product fields, it probably belongs to:

```txt id="co5tu1"
template
theme
WooCommerce gettext
page builder template
plugin output
```

The architecture already notes that page builders usually output HTML, so the output-buffer approach can catch rendered strings, while dynamic JavaScript content needs REST/AJAX/dynamic handling later. 

---

# 5. Step 4 — Rendered page parser/auditor

## Objective

Parse final HTML and detect remaining visible strings.

This step answers:

```txt id="nmb0lp"
What text is visible on the public site but was not already discovered from structured sources?
```

## Parser should extract

```txt id="w6tv1v"
Text nodes
Button labels
Menu labels
Form labels
Input placeholders
Image alt attributes
Title attributes
Meta title
Meta description
Open Graph title
Open Graph description
WooCommerce visible strings
```

This matches the string extractor responsibilities we defined in the architecture. 

## Parser should skip

```txt id="5zbe62"
<script>
<style>
<code>
<pre>
<noscript>
<svg>
<img>
<canvas>
[data-no-translate]
.notranslate
.wp-admin-bar
<!-- every thing in relation to wp admin -->
<!-- no media -->
```

## For each extracted text

Normalize it:

```txt id="s8l7xt"
Trim spaces
Decode HTML entities
Collapse multiple spaces
Remove non-useful whitespace
```

Hash it:

```txt id="rs083k"
original_hash = sha256(normalized_text)
```

Then compare against your existing table.

---

# 6. Step 5 — Classify parser-discovered strings

This is the most important part.

When the parser finds a visible string, it should try to classify it.

## Classification priority

```txt id="2lyfx9"
1. Exact structured source match
2. Known current object field
3. Known referenced object
4. Template/layout string
5. Gettext/plugin/theme string
6. Unknown rendered string
```

## Example

Rendered product page shows:

```txt id="t34ctv"
Black Leather Shoes
Add to cart
Related products
Shoes
Free delivery
```

Classification:

```txt id="akuvsg"
Black Leather Shoes → product.post_title
Shoes               → taxonomy.product_cat.name
Add to cart          → woocommerce/gettext/template
Related products     → woocommerce/template
Free delivery        → template_string
```

So the parser does not blindly add everything as page text.

It tries to ask:

```txt id="951w9k"
Is this text already owned by a known source?
```

If yes, ignore it or update `last_seen_at`.

If no, insert it as a rendered/template string.

---

# 7. Step 6 — Template-string detection rule

Your idea is correct:

> If it appears on a product page but was not in product fields, it is likely product template text.

More precisely:

```txt id="d1g1gm"
If rendered string appears on single_product archetype
AND it is not product-owned field
AND it is not taxonomy/media/menu/global known source
THEN classify as product_template or woocommerce_string.
```

Example records:

```txt id="qhlhh4"
source_type = product
source_id   = 0 -- not owned by a specific product so it template
context     = single_product.related_products_title
original_text = Related products
```

```txt id="ejm979"
source_type = product
source_id   = 0 -- not owned by a specific product so it template
context     = single_product.free_delivery_cta
original_text = Free delivery
```

```txt id="ckgl1e"
source_type = page
source_id   = 0 -- not owned by a specific page so it template
context     = woocommerce.add_to_cart
original_text = Add to cart
```

For CPT pages:

```txt id="zt90qe"
source_type = cpt_portfolio
source_id   = 0 -- not owned by a specific portfolio item so it template
context     = single_cpt_portfolio.view_project_label
```

---

# 8. Step 7 — Avoid scanning too many URLs

Build a **URL sampling engine**.

## For pages

Scan all static pages if the count is reasonable.

```txt id="ptu9fg"
Home
About
Contact
Landing pages
Legal pages
```

These pages are often unique, so scanning all pages is acceptable.

## For posts

Scan:

```txt id="vufp98"
1 recent post
1 old post
1 post with featured image
1 post with category/tag
1 post with comments, if enabled
```

## For products

Scan archetypes, not all products:

```txt id="41epgd"
simple
variable
sale
out-of-stock
with reviews
with custom ACF fields
```

## For CPTs

Scan:

```txt id="swslpm"
1 to 3 representative objects per CPT type
```

## For archives

Scan:

```txt id="rrzval"
blog archive
category archive
product archive
product category archive
CPT archive
search page
404 page
```

This gives broad coverage without rendering the whole site.

---

# 9. Step 8 — Parser insertion logic

When the parser finds a string, use this decision tree:

```txt id="4vwdnn"
1. Normalize text
2. Generate original_hash
3. Detect source candidate
4. Build source_type/source_id/context
5. Check unique key
6. If row exists:
      update last_seen_at
   Else:
      insert new row as discovered
```

Status:

```txt id="0xrrab"
0 = discovered
1 = machine translated
2 = manually reviewed
3 = ignored
4 = needs review
```

For rendered parser strings, use:

```txt id="rci1df"
status = 0
provider = NULL
```

---

# 10. Step 9 — Suggested `source_type` values

Since you use one table, define strict source types.

I would use short but clear values:

```txt id="9zklxx"
media
taxonomy
menu
option
widget
block
page
post
product
cpt_{name}
acf
template
gettext
seo
slug
schema
```


or increase `source_type` to `varchar(50)`.

---

# 14. Step 13 — How to avoid false positives

Your parser should not add everything.

Skip:

```txt id="gfazzs"
single-character strings
numbers-only strings
currency-only strings
dates-only strings, at first
email addresses
phone numbers
URLs
tracking IDs
CSS classes
JS variables
nonce values
cart totals
stock numbers
order numbers
```

Be careful with:

```txt id="vyeg9x"
SKU
brand names
product codes
legal names
company names
```

Maybe mark them as:

```txt id="xg7b9b"
status = ignored
```

or:

```txt id="i0w39f"
translatable = false
```

if you later add that column.

---
