<?php

namespace LingoWP\Privacy;

final class PrivacyPolicyContent
{
    public function register(): void
    {
        add_action('admin_init', [$this, 'addPolicyContent']);
    }

    public function addPolicyContent(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = <<<'HTML'
<p><em>Last updated: 27 August 2026</em></p>

<h2>1. Operator and scope</h2>
<p>LingoWP is operated by <strong>Mohamed Amine SAYAGH</strong>, an individual registered as an auto-entrepreneur in Morocco, at <strong>1 rue Abou Bakker El Wahrani, esc. B, étg. 3, appt. 17, La Villette H M, Casablanca, Morocco</strong>. Contact us at <strong>contact@lingowp.com</strong> or <strong>+212616137124</strong>. Full registration details appear in the Legal Notice.</p>
<p>This policy covers the legacy service domain lingowp.com, account and support services, and data sent by the LingoWP WordPress plugin to our backend. The operator of a website using our plugin remains responsible for that website's own privacy information and processing.</p>
<p>We determine how account, payment-administration, support and security information is processed. When processing personal data in website content on a customer's instructions, we act as a processor, or a sub-processor if the customer is itself a processor. Public website text can contain personal data, including names and testimonials.</p>

<h2>2. Information processed and its purpose</h2>
<table>
<thead><tr><th>Activity</th><th>Information involved</th><th>Purpose</th></tr></thead>
<tbody>
<tr><td>Website and API access</td><td>IP address, browser or client information, request and connection metadata</td><td>Deliver the service, investigate errors and prevent abuse</td></tr>
<tr><td>Site connection and account management</td><td>Website domain, administrator and owner email addresses, site/account identifiers and confirmation records</td><td>Connect sites, authenticate requests and manage ownership and sensitive account changes</td></tr>
<tr><td>Billing</td><td>Selected plan or word pack, subscription and transaction references, payment status and account-linked word counts</td><td>Administer purchases, entitlements, refunds and records</td></tr>
<tr><td>AI translation</td><td>Submitted source strings, grouping identifiers, source and target languages, selected translation service, writing-style settings and results</td><td>Produce and return requested translations</td></tr>
<tr><td>Bring your own key, or BYOK</td><td>Selected provider, API key and key-validation status</td><td>Validate the credential and authorize requests under the customer's provider account</td></tr>
<tr><td>Contact and support</td><td>Email address, message and information voluntarily included in it</td><td>Answer enquiries and provide assistance</td></tr>
<tr><td>Requested product updates</td><td>Email address and update-subscription request</td><td>Record the request and send the updates requested</td></tr>
<tr><td>Abuse prevention</td><td>Email, domain, IP address, action and timestamp for relevant requests</td><td>Apply limits to abusive or repeated requests</td></tr>
</tbody>
</table>
<p>Information comes from you, your WordPress installation and its administrator, your browser or API client, and payment or provider responses. Information necessary to connect a site, authenticate it or complete a purchase must be provided for that function to work. Optional messages and update requests are voluntary.</p>
<p>Payment details are entered through PayPal. We do not receive or store complete card numbers or card security codes. PayPal applies its own <a href="https://www.paypal.com/webapps/mpp/ua/privacy-full">privacy information</a>.</p>

<h2>3. Local features and external processing</h2>
<p><strong>Local and manual translation.</strong> Translations and settings are stored in the customer's WordPress database. Manual editing does not send that edited text to our translation service. Account, licence, billing and support communications can still occur when AI is disabled; disabling AI does not disconnect the service account.</p>
<p><strong>Browser-language detection.</strong> This feature runs locally on the customer's WordPress server. It compares the browser's Accept-Language header with enabled site languages. It does not call our backend or an AI service and does not transmit a text sample for detection. It is disabled by default and can be enabled by the website administrator. The URL's language prefix takes priority. Visitors do not have a separate plugin control for switching this feature off.</p>
<p><strong>LingoWP-hosted AI.</strong> Text submitted for translation passes through our backend and the translation engine used to provide the service. An external AI service may process the request where used. Results return to the plugin for local storage and display.</p>
<p><strong>BYOK.</strong> Bringing your own provider key does not bypass our backend. We receive the text, use your key to authorize the request and forward it to your selected provider under your provider account. Both the text needed for the request and the authorization credential reach that provider. Its charges, terms, settings and data practices also apply.</p>
<p>BYOK keys are validated with the selected provider and stored encrypted in our database. The last four characters are stored separately for identification. A key is decrypted when needed for a provider request. Removing it deletes the active database record; it does not revoke the key with the provider or guarantee removal from any existing backups. You can revoke the key separately with its provider.</p>

<h2>4. Translation content, logs and training</h2>
<p>We do not store source text or translations in the translation service's application database or maintain a reusable backend translation memory. Aggregate word counts are recorded for usage and billing. The customer's WordPress installation stores its own translations.</p>
<p><strong>Our current application writes submitted source strings and translations to server logs. Content can therefore remain after a translation request has finished.</strong> Log retention and access depend on the operational logging system; we do not currently specify a fixed deletion deadline for those copies. Disabling future content logging would not itself erase existing logs.</p>
<p>LingoWP does not use submitted translation text to train or fine-tune models, build training datasets, or evaluate models for general service improvement. Processing to deliver a translation or investigate a specific service problem is separate from model training. This commitment concerns our own use; external AI services apply their own contractual terms, account settings and retention rules. We do not promise zero retention or a particular training policy on another provider's behalf.</p>

<h2>5. Legal bases, recipients and international processing</h2>
<p>We process information to provide requested services, administer purchases, answer enquiries, protect the service and meet applicable legal obligations. We do not subscribe support contacts to marketing simply because they contacted us.</p>
<p>Where the GDPR applies, the relevant bases are contract performance or pre-contractual steps for individual customers; legitimate interests in business-account administration and service security; legal obligations for required records; and consent for optional product updates. Rights to object or withdraw consent remain available where applicable. Moroccan data-protection requirements also apply to our activities.</p>
<p>Recipients may include hosting and technical-service providers, email-delivery and mailbox providers, the AI services involved in a requested translation, PayPal, and logging or backup providers where used. They receive the data relevant to their role. We may also disclose information when legally required or necessary and lawful to address fraud, security incidents or legal claims. Payment and customer-selected AI providers may process information under their own agreements, rather than solely on our instructions.</p>
<p>We operate from Morocco. Depending on the services involved, information may also be processed outside Morocco or your country of residence. No particular hosting country or data-residency guarantee is included in this service. You may request information about the recipients and international processing applicable to your use by emailing <strong>contact@lingowp.com</strong>. This notice does not represent that a particular international-transfer authorization or contractual safeguard is already in place.</p>
<p>These pages are not a data-processing agreement. If your use requires such an agreement, contact us and agree it in writing before submitting the relevant personal data. A website's public availability does not remove that requirement.</p>

<h2>6. Retention and deletion</h2>
<p>The current system does not automatically delete most database records after a fixed period. Unless removed through an available function or a deletion request that we handle, the following records can remain indefinitely. This describes current system behavior and does not override legal duties to limit retention or act on valid requests.</p>
<table>
<thead><tr><th>Information</th><th>Current retention behavior</th></tr></thead>
<tbody>
<tr><td>Account and site records</td><td>No automatic inactivity expiry. Removing a site through an available owner action deletes that site record, not the entire backend account.</td></tr>
<tr><td>Detached or revoked sites</td><td>Detachment or revocation keeps the record, including domain and administrator email, to preserve the site association. It is not deletion.</td></tr>
<tr><td>BYOK keys</td><td>Retained until removed. Removing a stored key deletes its active database record; provider-side revocation and any backup copies are separate.</td></tr>
<tr><td>Email-confirmation records</td><td>Tokens normally expire after 24 hours, but use or expiry does not delete their records, destination emails or associated payloads.</td></tr>
<tr><td>Abuse-prevention records</td><td>The rate-limit calculation reads a one-hour window; stored emails, domains, IP addresses and request details have no automatic purge.</td></tr>
<tr><td>Billing and account-linked usage</td><td>No automatic retention limit or complete account-deletion function is currently implemented. Records needed by law remain subject to the applicable obligations.</td></tr>
<tr><td>Support and update requests</td><td>No automatic deletion period is implemented. Stopping updates does not itself erase the original request.</td></tr>
<tr><td>Translation-content and other technical logs</td><td>Retention depends on the logging system; no fixed maximum period is currently specified by LingoWP.</td></tr>
<tr><td>Backups, if created</td><td>No verified backup-retention or backup-erasure timeframe is currently specified. Deletion from an active system does not establish that every backup copy has been erased.</td></tr>
</tbody>
</table>
<p>To request access, correction or deletion, email <strong>contact@lingowp.com</strong>. Requests require handling by us; removing a site or cancelling a subscription is not a request to erase all associated data. We must assess any lawful need to retain particular records and explain applicable limits. AI services and PayPal have their own retention arrangements.</p>
<p>Deactivating the plugin preserves its WordPress data. Uninstalling it through WordPress deletes the plugin's local translation tables, settings and related stored options. Neither action automatically deletes backend records or cancels a subscription. The customer controls copies in its own WordPress backups.</p>

<h2>7. Cookies, browser storage and updates</h2>
<p>The current <strong>lingowp.com service landing page</strong> does not set cookies, use analytics or advertising trackers, or load fonts from an external font service. Contact and update forms submit to our own API. This does not describe PayPal's pages or all cookies on a customer's WordPress website.</p>
<p>On a website using the plugin, the following cookie can be set:</p>
<table>
<thead><tr><th>Cookie</th><th>Purpose and contents</th><th>Configured duration</th><th>Control</th></tr></thead>
<tbody>
<tr><td>preferred_lang</td><td>Stores a language code to remember the resolved language across visits; the URL language prefix takes priority</td><td>One year</td><td>Enabled by default; the website administrator can disable it in plugin settings</td></tr>
</tbody>
</table>
<p>Visitors can remove or block this cookie through browser controls. The plugin does not provide a separate visitor preference panel. The cookie is first-party, uses SameSite=Lax and is marked Secure on HTTPS. The public-facing plugin does not otherwise use localStorage or sessionStorage. Website operators must explain their own cookie use and obtain consent where required.</p>
<p>Within WordPress administration only, temporary sessionStorage is used to preserve pending billing actions during the PayPal redirect and is cleared after use. It is not a public visitor-tracking feature.</p>
<p>To withdraw a request for product updates, email <strong>contact@lingowp.com</strong>. There is currently no automated unsubscribe facility. We must handle such requests and stop the relevant marketing; withdrawal does not require agreeing to unrelated processing. Necessary account or transaction messages are separate from marketing.</p>

<h2>8. Security, rights and changes</h2>
<p>Application safeguards include hashed site-access credentials, encryption of stored BYOK keys, email confirmation for sensitive account actions, rate limits, account-scoped access checks and verification of payment notifications. These measures are not a guarantee of absolute security or a claim of independent security certification. Protect your WordPress administrator account, mailbox and provider credentials; do not include secrets in support messages.</p>
<p>Depending on applicable law, you may request access, correction, erasure, restriction or eligible data portability; object to certain processing; or withdraw consent. Contact <strong>contact@lingowp.com</strong>. We may request proportionate identity or authority verification and must handle requests within the applicable legal deadlines. For data on a customer's website, contact its operator first; we assist where we process on its behalf.</p>
<p>You may complain to Morocco's <a href="https://www.cndp.ma/">CNDP</a> or another competent supervisory authority. Changes to this policy will be dated, and material changes will be communicated where required.</p>
HTML;

        wp_add_privacy_policy_content('LingoWP', wp_kses_post($content));
    }
}
