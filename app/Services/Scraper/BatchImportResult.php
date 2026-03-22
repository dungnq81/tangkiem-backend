<?php

declare(strict_types=1);

namespace App\Services\Scraper;

/**
 * Tracks the outcome of a CSV bulk import operation.
 */
class BatchImportResult
{
    /** @var array<int, array{line: int, title: string, url: string, story: string, status: string, job_id: ?int}> */
    public array $rows = [];

    public int $created = 0;

    public int $skipped = 0;

    public int $errors = 0;

    public int $storiesCreated = 0;

    public function addCreated(int $line, string $title, string $url, string $storyLabel, int $jobId): void
    {
        if (str_contains($storyLabel, 'mới')) {
            $this->storiesCreated++;
        }

        $this->rows[] = [
            'line'   => $line,
            'title'  => $title,
            'url'    => $url,
            'story'  => $storyLabel,
            'status' => "✅ Created #{$jobId}",
            'job_id' => $jobId,
        ];
        $this->created++;
    }

    public function addSkipped(int $line, string $title, string $url, string $reason): void
    {
        $this->rows[] = [
            'line'   => $line,
            'title'  => $title,
            'url'    => $url,
            'story'  => '—',
            'status' => "⏭ {$reason}",
            'job_id' => null,
        ];
        $this->skipped++;
    }

    public function addPreview(int $line, string $title, string $url, string $storyLabel): void
    {
        $this->rows[] = [
            'line'   => $line,
            'title'  => $title,
            'url'    => $url,
            'story'  => $storyLabel,
            'status' => '🔍 Preview',
            'job_id' => null,
        ];
        $this->created++; // Count as "would create" for summary
    }

    public function addError(int $line, string $title, string $error): void
    {
        $this->rows[] = [
            'line'   => $line,
            'title'  => $title,
            'url'    => '—',
            'story'  => '—',
            'status' => "❌ {$error}",
            'job_id' => null,
        ];
        $this->errors++;
    }

    public function summary(): string
    {
        $parts = [];

        if ($this->created > 0) {
            $parts[] = "Tạo: {$this->created} tác vụ";
        }

        if ($this->storiesCreated > 0) {
            $parts[] = "{$this->storiesCreated} truyện mới";
        }

        if ($this->skipped > 0) {
            $parts[] = "Bỏ qua: {$this->skipped}";
        }

        if ($this->errors > 0) {
            $parts[] = "Lỗi: {$this->errors}";
        }

        return implode(' | ', $parts);
    }
}
