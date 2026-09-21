<?php

namespace LingoWP\Extraction;

use LingoWP\Shared\Source\Type\OptionSource;
use LingoWP\Database\Repository\WpDbSourceRepository;

final class OptionSourceScanner
{
    private const ALLOWLIST = [
        'blogname'        => [''],
        'blogdescription' => [''],
    ];

    private WpDbSourceRepository $repository;
    private \wpdb $wpdb;

    public function __construct(
        WpDbSourceRepository $repository,
        \wpdb $wpdb
    ) {
        $this->repository = $repository;
        $this->wpdb       = $wpdb;
    }

    public function scan(?string $onlyOption = null): void
    {
        foreach ($this->allowlist() as $optionName => $paths) {
            $optionName = (string) $optionName;
            if ($onlyOption !== null && $optionName !== $onlyOption) {
                continue;
            }

            $optionId = $this->optionId($optionName);
            if ($optionId === null) {
                continue;
            }

            $value   = maybe_unserialize(get_option($optionName));

            if ($optionName === 'theme_mods_' . get_stylesheet() && is_array($value)) {
                unset($value['sidebars_widgets']);
            }

            $records = [];

            foreach ((array) $paths as $path) {
                $node = $this->valueAtPath($value, (string) $path);
                foreach ($this->collectStrings($node, (string) $path) as [$leafPath, $text]) {
                    if (!$this->looksTranslatable($text)) {
                        continue;
                    }
                    $records[] = new OptionSource($optionId, $optionName, $leafPath, $text);
                }
            }

            $this->repository->reconcileOption($optionId, $records);
        }
    }

    public function isDynamicallyAllowlisted(string $optionName): bool
    {
        return $optionName === 'theme_mods_' . get_stylesheet()
            || str_starts_with($optionName, 'widget_');
    }

    private function allowlist(): array
    {
        $list = self::ALLOWLIST;

        $list['theme_mods_' . get_stylesheet()] = [''];
        foreach ($this->widgetOptionNames() as $name) {
            $list[$name] = [''];
        }

        $list = apply_filters('lingowp_option_paths', $list);

        return is_array($list) ? $list : self::ALLOWLIST;
    }

    private function widgetOptionNames(): array
    {
        $names = $this->wpdb->get_col( // nosemgrep: wpdb-interpolated-sql -- table name only, from the TranslationMemorySchema map / $wpdb prefix; no values interpolated
            "SELECT option_name FROM {$this->wpdb->options} WHERE option_name LIKE 'widget\\_%'"
        );

        return is_array($names) ? array_map('strval', $names) : [];
    }

    private function optionId(string $optionName): ?int
    {
        $id = $this->wpdb->get_var( // phpcs:ignore WordPress.DB
            $this->wpdb->prepare(
                "SELECT option_id FROM {$this->wpdb->options} WHERE option_name = %s",
                $optionName
            )
        );

        return ($id !== null && (int) $id > 0) ? (int) $id : null;
    }

    private function valueAtPath($value, string $path)
    {
        if ($path === '') {
            return $value;
        }

        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } elseif (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
            } else {
                return null;
            }
        }

        return $value;
    }

    private function collectStrings($node, string $prefix): array
    {
        if (is_string($node)) {
            return [[$prefix, $node]];
        }

        $out = [];
        if (is_array($node) || is_object($node)) {
            foreach ((array) $node as $key => $child) {
                $childPath = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                array_push($out, ...$this->collectStrings($child, $childPath));
            }
        }

        return $out;
    }

    private function looksTranslatable(string $text): bool
    {
        $t = trim($text);

        if ($t === '' || mb_strlen($t) < 2) {
            return false;
        }
        if (!preg_match('/\p{L}/u', $t)) {
            return false;
        }
        if (preg_match('/<[!\/a-z]/i', $t)) {
            return false;
        }
        if ($t[0] === '/' || preg_match('#^https?://#i', $t)) {
            return false;
        }
        if (filter_var($t, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $t)) {
            return false;
        }
        if (preg_match('/^\d+(\.\d+)?\s*(px|em|rem|%|pt|vh|vw)?$/i', $t)) {
            return false;
        }

        return true;
    }
}
