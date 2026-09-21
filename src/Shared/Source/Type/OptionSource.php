<?php

namespace LingoWP\Shared\Source\Type;
use LingoWP\Shared\Source\SourceRecord;

use InvalidArgumentException;

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
