<?php

namespace LingoWP\GettextDomains\Scanner;

/**
 * Finds WordPress translation calls (__(), _x(), _n(), esc_html__(), …) in PHP
 * source with PHP's own tokenizer, token_get_all() — the approach of WP-CLI's
 * `wp i18n make-pot` and of the potx extractor TranslatePress ships. It
 * replaced gettext/php-scanner and its nikic/php-parser dependency (1.6.8):
 * WordPress.org asked for that library to go, and another plugin's copy of
 * it (Plugin Check ships an older one) could silently replace ours at
 * runtime and make every scan return nothing.
 *
 * Only literal text is extracted: a quoted string, or quoted strings joined
 * with `.`. A call whose text contains a variable, a constant or a function
 * call cannot be translated by a .mo file and is skipped, as WordPress's own
 * tools skip it.
 */
final class PhpGettextScanner
{
    /**
     * Function => [text, context|null, domain] argument positions, as in
     * WordPress's own i18n functions.
     */
    private const FUNCTIONS = [
        '__'          => [0, null, 1],
        '_e'          => [0, null, 1],
        'esc_attr__'  => [0, null, 1],
        'esc_attr_e'  => [0, null, 1],
        'esc_html__'  => [0, null, 1],
        'esc_html_e'  => [0, null, 1],
        '_x'          => [0, 1, 2],
        'esc_attr_x'  => [0, 1, 2],
        'esc_html_x'  => [0, 1, 2],
        '_n'          => [0, null, 3],
        '_n_noop'     => [0, null, 2],
        '_nx'         => [0, 3, 4],
        '_nx_noop'    => [0, 2, 3],
    ];

    /** Tokens that never matter between two meaningful ones. */
    private const SKIP = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * Every translation call in $code whose domain is $domain, or that names
     * no literal domain (a call without one belongs to the scanned component).
     *
     * @return array<int, array{msgid: string, context: ?string}> unique by (context, msgid)
     */
    public function scan(string $code, string $domain): array
    {
        $tokens = $this->significantTokens($code);
        $count  = count($tokens);
        $found  = [];

        for ($i = 0; $i < $count - 1; $i++) {
            $name = $this->functionName($tokens, $i);
            if ($name === null || $tokens[$i + 1] !== '(') {
                continue;
            }

            // Not skipping past the call: a translation call nested in
            // another one's arguments is found on a later iteration.
            $args = $this->arguments($tokens, $i + 2);

            [$textAt, $contextAt, $domainAt] = self::FUNCTIONS[$name];
            $text = $this->literal($args[$textAt] ?? []);
            if ($text === null) {
                continue;
            }

            $callDomain = $this->literal($args[$domainAt] ?? []);
            if ($callDomain !== null && $callDomain !== $domain) {
                continue;
            }

            $context = $contextAt === null ? null : $this->literal($args[$contextAt] ?? []);
            $found[($context === null ? '' : $context . "\x04") . $text] = ['msgid' => $text, 'context' => $context];
        }

        return array_values($found);
    }

    /**
     * The tokens without whitespace and comments: each one either a one-char
     * string ('(', ',', '.') or an [id, text] pair.
     *
     * @return array<int, string|array{0: int, 1: string}>
     */
    private function significantTokens(string $code): array
    {
        $out = [];
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], self::SKIP, true)) {
                continue;
            }
            $out[] = is_array($token) ? [$token[0], $token[1]] : $token;
        }

        return $out;
    }

    /**
     * The translation function named at $i, or null: a plain function call
     * only — not a method or static call ($obj->__(), Foo::__()), not a
     * function definition (function __()), and not another namespace's
     * function of the same name (Foo\__()).
     *
     * @param array<int, string|array{0: int, 1: string}> $tokens
     */
    private function functionName(array $tokens, int $i): ?string
    {
        $token = $tokens[$i];
        if (!is_array($token)) {
            return null;
        }

        $name = $token[1];
        if (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === constant('T_NAME_FULLY_QUALIFIED')) { // PHP 8+
            $name = ltrim($name, '\\'); // \__() is the global function
        } elseif ($token[0] !== T_STRING) {
            return null;
        }
        if (!isset(self::FUNCTIONS[$name])) {
            return null;
        }

        $prev = $tokens[$i - 1] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
            return null;
        }
        if (is_array($prev) && defined('T_NULLSAFE_OBJECT_OPERATOR') && $prev[0] === constant('T_NULLSAFE_OBJECT_OPERATOR')) { // $obj?->__(), PHP 8+
            return null;
        }
        // PHP 7 tokenizes \__() as T_NS_SEPARATOR + T_STRING: global only if
        // nothing namespaced comes right before the separator.
        if (is_array($prev) && $prev[0] === T_NS_SEPARATOR) {
            $before = $tokens[$i - 2] ?? null;
            if (is_array($before) && $before[0] === T_STRING) {
                return null;
            }
        }

        return $name;
    }

    /**
     * Split the argument list that starts at $start (just after the opening
     * parenthesis) into one token list per argument.
     *
     * @param array<int, string|array{0: int, 1: string}> $tokens
     * @return array<int, array<int, string|array{0: int, 1: string}>>
     */
    private function arguments(array $tokens, int $start): array
    {
        $args  = [];
        $arg   = [];
        $depth = 0;
        $count = count($tokens);

        for ($j = $start; $j < $count; $j++) {
            $token = $tokens[$j];
            $text  = is_array($token) ? $token[1] : $token;

            if ($depth === 0 && ($text === ')' || $text === ',')) {
                $args[] = $arg;
                $arg    = [];
                if ($text === ')') {
                    return $args;
                }
                continue;
            }

            if (in_array($text, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }
            $arg[] = $token;
        }

        // Unterminated call (truncated file): take what was read.
        $args[] = $arg;

        return $args;
    }

    /**
     * The string an argument evaluates to when it is made only of quoted
     * strings joined with `.`, or null for anything else (a variable, a
     * constant, a call, an interpolated string, a heredoc, a number).
     *
     * @param array<int, string|array{0: int, 1: string}> $arg
     */
    private function literal(array $arg): ?string
    {
        if ($arg === []) {
            return null;
        }

        $value     = '';
        $expectStr = true;
        foreach ($arg as $token) {
            if ($expectStr) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    return null;
                }
                $value    .= self::decode($token[1]);
                $expectStr = false;
            } else {
                if ($token !== '.') {
                    return null;
                }
                $expectStr = true;
            }
        }

        return $expectStr ? null : $value; // a trailing '.' is not a value
    }

    /** A quoted PHP string literal's value, with PHP's own escape rules. */
    public static function decode(string $quoted): string
    {
        if ($quoted !== '' && ($quoted[0] === 'b' || $quoted[0] === 'B')) {
            $quoted = substr($quoted, 1); // binary-string prefix: same value
        }

        $inner = substr($quoted, 1, -1);

        if ($quoted[0] === "'") {
            return (string) preg_replace_callback('/\\\\([\\\\\'])/', static fn(array $m): string => $m[1], $inner);
        }

        return (string) preg_replace_callback(
            '/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
            static function (array $m): string {
                if (($m[1] ?? '') !== '') {
                    return ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'][$m[1]];
                }
                if (($m[2] ?? '') !== '') {
                    return chr(octdec($m[2]) & 0xFF);
                }
                if (($m[3] ?? '') !== '') {
                    return chr((int) hexdec($m[3]));
                }

                return (string) mb_chr((int) hexdec($m[4]), 'UTF-8');
            },
            $inner
        );
    }
}
