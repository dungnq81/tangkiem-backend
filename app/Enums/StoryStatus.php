<?php

declare(strict_types=1);

namespace App\Enums;

enum StoryStatus: string
{
    case ONGOING = 'ongoing';
    case COMPLETED = 'completed';
    case HIATUS = 'hiatus';
    case DROPPED = 'dropped';

    public function label(): string
    {
        return config("story.statuses.{$this->value}.label", $this->value);
    }

    public function color(): string
    {
        return config("story.statuses.{$this->value}.color", 'gray');
    }

    public function icon(): string
    {
        return config("story.statuses.{$this->value}.icon", 'heroicon-o-question-mark-circle');
    }

    public static function options(): array
    {
        return collect(config('story.statuses'))
            ->mapWithKeys(fn($data, $key) => [$key => $data['label']])
            ->toArray();
    }

    public static function default(): self
    {
        return self::from(config('story.defaults.status', 'ongoing'));
    }
}
