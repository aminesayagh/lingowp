# Add-on catalog images

`GET /billing/addons` returns each entry's `image` as a bare filename
(`lingowp-<slug>.png`) — the backend no longer hosts these. `IntegrationsApp.jsx`
builds the URL as `cfg.addons_asset_url + addon.image` and falls back to the
LingoWP logo on load failure, so a missing file here degrades gracefully.

Drop the real PNGs in this directory as they're delivered (converted from the
SVGs on the design side) — filenames must match what the backend sends
exactly. As of the last backend handoff, still missing:
`lingowp-seo.png`, `lingowp-contact-form-7.png`, and the 10 LingoWP-tools
icons (`lingowp-translation-studio.png`, `-translation-qa`, `-glossary`,
`-review-workflow`, `-multilingual-search`, `-multilingual-emails`,
`-translation-sync`, `-agency-toolkit`, `-csv-io`, `-localized-media`).
