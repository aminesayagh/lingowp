<?php

namespace LingoWP\Shared\Source\Type;
use LingoWP\Shared\Source\SourceRecord;

use InvalidArgumentException;

/**
 * Type-6 source: a translatable string stored inside wp_options. Identity is the
 * owning option row (option_id) plus the path to the leaf value within a
 * (possibly serialized) option value, e.g. "header.button.text"; empty string
 * for a scalar option. The readable option_name is kept alongside for the board.
 *
 * Hash-link kind (like post/term/html): the translation is keyed by original_hash
 * alone, so two options holding identical text SHARE one translation row —
 * option_id and option_path_hash identify the parent only. The owner FK to
 * wp_options(option_id) cascades the parent away when the option is gone; the
 * hash-link child has no FK and is reclaimed by OrphanTranslationSweep.
 */
final class OptionSource extends SourceRecord
{
    private string $optionName;
    private int    $optionId;
    private string $optionPath;
    private string $text;

    public function __construct(
        int    $optionId,
        string $optionName,
        string $optionPath,
        string $text
    ) {
        $this->optionId   = $optionId;
        $this->optionPath = $optionPath;
        $this->text       = $text;

        if ($optionId <= 0) {
            throw new InvalidArgumentException('OptionSource: option id must be positive.');
        }
        if ($optionName === '') {
            throw new InvalidArgumentException('OptionSource: option name is required.');
        }
        // option_name column is varchar(191).
        $this->optionName = mb_substr($optionName, 0, 191);
    }

    public function optionName(): string
    {
        return $this->optionName;
    }

    public function optionPath(): string
    {
        return $this->optionPath;
    }

    public function table(): string
    {
        return 'option';
    }

    public function originalText(): string
    {
        return $this->text;
    }

    public function identity(): array
    {
        return [
            'option_id'        => $this->optionId,
            'option_path_hash' => hash('sha256', $this->optionPath, true),
            'original_hash'    => $this->originalHash(),
        ];
    }

    public function row(): array
    {
        return $this->identity() + [
            'option_name'   => $this->optionName,
            'option_path'   => $this->optionPath,
            'original_text' => $this->originalText(),
        ];
    }
}
