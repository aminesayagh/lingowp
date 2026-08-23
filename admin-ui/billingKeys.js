// Shared between BillingSubscription.jsx and BillingUsage.jsx — both can
// initiate a word-pack purchase, but only BillingSubscription.jsx's
// redirect-return handler reads this back: PayPal's pack-purchase redirect
// always lands on tab=subscription, regardless of which tab started the
// order (see reference-billing-api-integration.md §4). Keep this one
// constant in sync rather than duplicating the literal — a mismatch here
// would silently break the poll-until-settled "words increased" check.
export const PENDING_WORDS_KEY = 'translangs_pending_words_before'
