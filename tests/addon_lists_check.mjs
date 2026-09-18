/**
 * Standalone check for the Integrations tab's list logic and the add-on
 * toasts — no framework.
 * Run: node tests/addon_lists_check.mjs
 * Exits non-zero if any case fails.
 */

import {
  partitionAddons,
  activationToasts,
  subscriptionToasts,
  tierLabel,
} from "../admin-ui/screens/addonLists.mjs";

let failed = 0;
const ok = (label, cond) => {
  if (cond) {
    console.log(`PASS  ${label}`);
  } else {
    failed++;
    console.log(`FAIL  ${label}`);
  }
};
const ids = (list) => list.map((a) => a.addon_id).join(",");

const A = (addon_id, relevant, included, installed, detects = [], tier = "starter") => ({
  addon_id,
  title: addon_id,
  relevant,
  included,
  installed,
  detects,
  tier,
});

const addons = [
  A("installed_v1", true, true, true, ["elementor/elementor.php"]),
  A("ready_v1", true, true, false, ["woocommerce/woocommerce.php"], "pro"),
  A("upgrade_v1", true, false, false, ["advanced-custom-fields-pro/acf.php", "acf/acf.php"], "pro"),
  A("irrelevant_ready_v1", false, true, false, ["seo/seo.php"]),
  A("irrelevant_upgrade_v1", false, false, false, ["theme:bricks"]),
  // Installed wins even if the host plugin has since been deactivated.
  A("installed_irrelevant_v1", false, false, true, ["theme:Divi"]),
];

const p = partitionAddons(addons);
ok("installed list = installed add-ons regardless of relevance", ids(p.installed) === "installed_v1,installed_irrelevant_v1");
ok("ready = relevant && included && !installed", ids(p.ready) === "ready_v1");
ok("upgrade = relevant && !included && !installed", ids(p.upgrade) === "upgrade_v1");
ok("irrelevant add-ons appear nowhere", !ids(p.ready).includes("irrelevant") && !ids(p.upgrade).includes("irrelevant"));

ok("no activations -> no toasts", activationToasts(addons, []).length === 0 && activationToasts(addons, undefined).length === 0);
const t1 = activationToasts(addons, ["woocommerce/woocommerce.php"]);
ok("activation matching an included add-on -> install toast", t1.length === 1 && t1[0].addon.addon_id === "ready_v1" && t1[0].kind === "install");
const t2 = activationToasts(addons, ["acf/acf.php"]);
ok("activation matching a non-included add-on -> upgrade toast", t2.length === 1 && t2[0].addon.addon_id === "upgrade_v1" && t2[0].kind === "upgrade");
ok("activation matching an installed add-on -> no toast", activationToasts(addons, ["elementor/elementor.php"]).length === 0);
ok("activation matching nothing -> no toast", activationToasts(addons, ["unknown/unknown.php"]).length === 0);
const t3 = activationToasts(addons, ["woocommerce/woocommerce.php", "acf/acf.php"]);
ok("several activations -> one toast each", t3.length === 2);

ok("subscription toast = ready list", ids(subscriptionToasts(addons)) === "ready_v1");
ok("tierLabel capitalizes, empty-safe", tierLabel("pro") === "Pro" && tierLabel(null) === "" && tierLabel("") === "");

console.log(failed === 0 ? "\nall passed" : `\n${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
