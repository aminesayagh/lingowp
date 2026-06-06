<?php

namespace TransLangs\TranslationMemory\Domain;

/**
 * Translation table route used by admin row references and repository writes.
 *
 * The backing value matches the table name suffix in wp_tl_translations_*.
 */
enum SourceRoute: string
{
    case Post     = 'post';
    case Postmeta = 'postmeta';
    case Terms    = 'terms';
    case I18n     = 'i18n';

    public function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tl_translations_' . $this->value;
    }
}
