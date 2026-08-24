<?php

namespace TransLangs\Privacy;

/** Adds suggested text to WordPress's Privacy Policy Guide. */
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

        $content = '<p>' . esc_html__(
            'TransLangs stores translation content and configuration in this WordPress database. It may use a preferred-language cookie and the browser Accept-Language header to choose which locally stored translation to display.',
            'translangs'
        ) . '</p>';

        $content .= '<p>' . esc_html__(
            'TransLangs Cloud is optional. When an administrator chooses to connect, the site domain and WordPress administrator email address are sent to the service. When AI translation is requested, source text, source and target language identifiers, and translation grouping identifiers are sent. AI provider keys entered in the plugin are sent to TransLangs Cloud for encrypted storage and provider validation.',
            'translangs'
        ) . '</p>';

        $content .= '<p>' . esc_html__(
            'The Insights preview does not send data when it is opened. On a connected site, clicking “Notify me at launch” submits a fixed Insights product-interest code using the existing license authentication and site-domain header. The plugin does not send an email address in that request; TransLangs Cloud associates the suggestion with the verified license owner, falling back to the site administrator email. Billing and account actions send the information required to perform the requested transaction.',
            'translangs'
        ) . '</p>';

        $content .= '<p>' . esc_html__(
            'Site owners should update this disclosure with the TransLangs Cloud terms and privacy-policy links configured for their installation and describe their own retention and deletion practices.',
            'translangs'
        ) . '</p>';

        wp_add_privacy_policy_content('TransLangs', wp_kses_post($content));
    }
}
