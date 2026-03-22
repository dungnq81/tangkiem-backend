<?php

declare(strict_types=1);

namespace App\Services\WebCron\Tasks;

use App\Services\WebCron\Contracts\CronTaskInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Base class for cron tasks.
 *
 * Provides throttle helper and Artisan runner utility.
 * Subclasses can override execute() with custom logic,
 * or use runArtisan() for simple command delegation.
 */
abstract class AbstractTask implements CronTaskInterface
{
    /**
     * Throttle interval in seconds.
     * 0 = run every cycle (no throttle).
     */
    protected int $throttleSeconds = 0;

    /**
     * Output strings to suppress (reduce log noise).
     * If Artisan output exactly matches one of these, execute() returns null.
     *
     * @var string[]
     */
    protected array $suppressOutput = [];

    // ═══════════════════════════════════════════════════════════════
    // Throttle
    // ═══════════════════════════════════════════════════════════════

    public function shouldRun(): bool
    {
        if ($this->throttleSeconds <= 0) {
            return true;
        }

        // Atomic: returns true only if key didn't exist
        return Cache::add($this->throttleCacheKey(), true, $this->throttleSeconds);
    }

    protected function throttleCacheKey(): string
    {
        return 'web_cron:task:' . str_replace([':', ' '], '_', $this->name());
    }

    // ═══════════════════════════════════════════════════════════════
    // Artisan Helper
    // ═══════════════════════════════════════════════════════════════

    /**
     * Run an Artisan command and return its trimmed output.
     * Suppresses output matching $this->suppressOutput.
     *
     * @param  string               $command    e.g., 'chapters:publish-scheduled'
     * @param  array<string, mixed>  $params     Artisan command parameters
     */
    protected function runArtisan(string $command, array $params = []): ?string
    {
        Artisan::call($command, $params);
        $output = trim(Artisan::output());

        // Suppress known noisy output
        if (in_array($output, $this->suppressOutput, true)) {
            return null;
        }

        return $output ?: null;
    }
}
