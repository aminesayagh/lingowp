# Security audit handoff

This is a review checklist, not a claim that the plugin is secure or that any
scanner has passed.

## Reviewed safeguards

- Admin pages and REST mutations are restricted to administrators through
  `manage_options`; the SPA uses a WordPress REST nonce.
- Cloud credentials remain server-side and are not localized into JavaScript.
- Cloud connection is an explicit administrator action.
- The Insights endpoint accepts no browser message or email. After a button
  click, WordPress sends a fixed machine code through the authenticated support
  suggestion route; the backend resolves the account email.
- Online language-package downloads return safely when ZipArchive is absent.
- Deactivation cancels known LingoWP Action Scheduler work; uninstall repeats
  cleanup when Action Scheduler is available and removes known options/caches.

## Manual review targets

- Recheck every REST route for capability checks, validation, sanitization, and
  safe response handling.
- Review all SQL for placeholders and identifier allowlists.
- Review escaping at PHP and React output boundaries, including URLs localized
  from filters.
- Verify backend errors never expose API keys, bearer tokens, payment details,
  or raw provider responses.
- Confirm provider-key retention and deletion behavior with the production
  service and published privacy policy.
- Inspect the final ZIP for secrets, development artifacts, source maps, and
  unexpected executable files.

## Owner-run commands

```bash
composer run security
composer run security:plugin-check
composer audit --locked
```

Record results and dispositions in `release-readiness.md`; do not treat a clean
scanner report as a substitute for manual review.
