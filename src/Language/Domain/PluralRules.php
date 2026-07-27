<?php

namespace TransLangs\Language\Domain;

/**
 * Per-language gettext plural metadata for the supported target languages.
 *
 * Each language has `nplurals` forms and a `Plural-Forms` expression (the part
 * after `plural=`). The expression is evaluated by WordPress's own
 * `\Plural_Forms` (wp-includes/pomo/plural-forms.php) — so we reuse core's CLDR
 * evaluator and never hand-roll the plural arithmetic. `indexFor()` turns a count
 * into the gettext msgstr index (0..N-1); `forms()` describes the inputs the admin
 * editor must show (a labelled box per form with an example count).
 *
 * Most supported languages are 2-form; Arabic is the only 6-form one. Unknown
 * codes fall back to the English 2-form rule.
 */
final class PluralRules
{
    /**
     * lang => [nplurals, plural expression, forms]. Each form is [CLDR label,
     * an example count that selects it].
     *
     * @var array<string, array{nplurals:int, expr:string, forms:array<int,array{0:string,1:int}>}>
     */
    private const RULES = [
        'en' => ['nplurals' => 2, 'expr' => 'n != 1', 'forms' => [['one', 1], ['other', 2]]],
        'de' => ['nplurals' => 2, 'expr' => 'n != 1', 'forms' => [['one', 1], ['other', 2]]],
        'es' => ['nplurals' => 2, 'expr' => 'n != 1', 'forms' => [['one', 1], ['other', 2]]],
        'it' => ['nplurals' => 2, 'expr' => 'n != 1', 'forms' => [['one', 1], ['other', 2]]],
        'nl' => ['nplurals' => 2, 'expr' => 'n != 1', 'forms' => [['one', 1], ['other', 2]]],
        'fr' => ['nplurals' => 2, 'expr' => 'n > 1', 'forms' => [['one', 1], ['other', 2]]],
        'pt' => ['nplurals' => 2, 'expr' => 'n > 1', 'forms' => [['one', 1], ['other', 2]]],
        'ar' => [
            'nplurals' => 6,
            'expr'     => 'n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5',
            'forms'    => [['zero', 0], ['one', 1], ['two', 2], ['few', 3], ['many', 11], ['other', 100]],
        ],
    ];

    /** @var array<string, \Plural_Forms> compiled expression cache, per language */
    private static array $compiled = [];

    private function __construct() {}

    public static function nplurals(string $lang): int
    {
        return self::rule($lang)['nplurals'];
    }

    /**
     * Form descriptors for the editor: index + CLDR category label + an example
     * count that selects that form.
     *
     * @return array<int, array{index:int, label:string, example:int}>
     */
    public static function forms(string $lang): array
    {
        $out = [];
        foreach (self::rule($lang)['forms'] as $i => [$label, $example]) {
            $out[] = ['index' => $i, 'label' => $label, 'example' => $example];
        }

        return $out;
    }

    /** gettext msgstr index (0..nplurals-1) for $number in $lang. */
    public static function indexFor(string $lang, int $number): int
    {
        $rule = self::rule($lang);
        $max  = $rule['nplurals'] - 1;
        $n    = max(0, $number);

        if (class_exists('\Plural_Forms')) {
            if (!isset(self::$compiled[$lang])) {
                self::$compiled[$lang] = new \Plural_Forms($rule['expr']);
            }

            return max(0, min($max, (int) self::$compiled[$lang]->get($n)));
        }

        // pomo unavailable (should not happen in WP): degrade to a 2-form split.
        return $n === 1 ? 0 : min(1, $max);
    }

    /** @return array{nplurals:int, expr:string, forms:array<int,array{0:string,1:int}>} */
    private static function rule(string $lang): array
    {
        return self::RULES[$lang] ?? self::RULES['en'];
    }
}
