<?php

declare(strict_types=1);

namespace App\Enums;

enum TagType: string
{
    case TAG = 'tag';
    case WARNING = 'warning';
    case ATTRIBUTE = 'attribute';

    public function label(): string
    {
        return config("tag.types.{$this->value}.label", $this->value);
    }

    public function color(): string
    {
        return config("tag.types.{$this->value}.color", 'gray');
    }

    public function icon(): string
    {
        return config("tag.types.{$this->value}.icon", 'heroicon-o-tag');
    }

    public function description(): ?string
    {
        return config("tag.types.{$this->value}.description");
    }

    public static function options(): array
    {
        return collect(config('tag.types'))
            ->mapWithKeys(fn($data, $key) => [$key => $data['label']])
            ->toArray();
    }

    public static function default(): self
    {
        return self::from(config('tag.defaults.type', 'tag'));
    }
}
