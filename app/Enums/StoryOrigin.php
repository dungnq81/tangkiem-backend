<?php

declare(strict_types=1);

namespace App\Enums;

enum StoryOrigin: string
{
    case VIETNAM = 'vietnam';
    case CHINA = 'china';
    case KOREA = 'korea';
    case JAPAN = 'japan';
    case WESTERN = 'western';
    case OTHER = 'other';

    public function label(): string
    {
        return config("story.origins.{$this->value}.label", $this->value);
    }

    public function flag(): string
    {
        return config("story.origins.{$this->value}.flag", '🌐');
    }

    public function language(): ?string
    {
        return config("story.origins.{$this->value}.language");
    }

    public static function options(): array
    {
        return collect(config('story.origins'))
            ->mapWithKeys(fn($data, $key) => [$key => $data['label']])
            ->toArray();
    }

    public static function default(): self
    {
        return self::from(config('story.defaults.origin', 'china'));
    }
}
