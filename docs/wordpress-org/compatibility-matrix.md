# Compatibility matrix

Populate this table with actual owner-run results. The versions below are test
targets, not claims of compatibility.

| WordPress | PHP | Environment | Scenarios | Result |
| --- | --- | --- | --- | --- |
| 7.1 | 8.4 | Development environment (WooCommerce, ACF, Elementor, three SEO plugins active) | Day-to-day development use | Exercised continuously, not a formal pass |
| 6.2 | 7.4 | Single site | Activate, configure languages, manual translation, frontend rendering | Not run |
| 6.2 | 8.1 | Single site | Activate, configure languages, manual translation, frontend rendering | Not run |
| Latest supported | 8.2 | Single site | Full smoke test, explicit cloud opt-in, Insights notification | Not run |
| Latest supported | 8.3 | Single site | Full smoke test, background discovery, deactivation/reactivation | Not run |
| Latest supported | 8.4 | Single site | Full smoke test and gettext package import | Not run |
| Latest supported | Supported PHP | Multisite, network activated | Activation, per-site data behavior, uninstall | Not run |
| Latest supported | Supported PHP | WooCommerce active | Prices, translated product content, checkout smoke test | Not run |

The development environment is not a clean-room test: WooCommerce supplies its
own Action Scheduler there, which is precisely what hid LingoWP's missing copy.
At least one row must be run with no other plugin active.

Also verify RTL admin rendering, pretty and plain permalinks, a site without
ZipArchive, a site without cloud connection, disconnected/revoked credentials,
and uninstall with pending Action Scheduler jobs.

Update `Tested up to` in both plugin metadata and `readme.txt` only after the
corresponding WordPress version has actually been tested.
