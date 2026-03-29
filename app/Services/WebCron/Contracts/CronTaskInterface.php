<?php

declare(strict_types=1);

namespace App\Services\WebCron\Contracts;

/**
 * Contract for web cron tasks.
 *
 * Each task is responsible for a single scheduled operation
 * (publish chapters, refresh rankings, process queue, etc.).
 *
 * To add a new task:
 * 1. Create a class implementing this interface (extend AbstractTask for convenience)
 * 2. Add it to WebCronManager::TASKS constant
 */
interface CronTaskInterface
{
    /**
     * Unique identifier for this task (used in logs and cache keys).
     * Example: 'chapters:publish-scheduled', 'rankings:refresh'
     */
    public function name(): string;

    /**
     * Whether this task should run in the current cycle.
     *
     * Use this for throttling (e.g., "only run every 15 minutes").
     * Tasks that run every cycle should return true.
     */
    public function shouldRun(): bool;

    /**
     * Execute the task.
     *
     * @return string|null Output message, or null for silent success.
     */
    public function execute(): ?string;
}
