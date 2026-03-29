<?php

declare(strict_types=1);

namespace App\Support\Curator;

use Awcodes\Curator\PathGenerators\Contracts\PathGenerator;
use Illuminate\Support\Carbon;

/**
 * WordPress-style path generator for Curator.
 *
 * Generates paths like: uploads/2026/01/filename.jpg
 */
class WordPressPathGenerator implements PathGenerator
{
    public function getPath(?string $baseDir = null): string
    {
        $year = Carbon::now()->format('Y');
        $month = Carbon::now()->format('m');

        $basePath = $baseDir ?? 'uploads';

        return "{$basePath}/{$year}/{$month}";
    }
}
