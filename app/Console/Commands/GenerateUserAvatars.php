<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Awcodes\Curator\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateUserAvatars extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:generate-avatars {--force : Overwrite existing avatars}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate placeholder avatars for users without avatars using UI Avatars API';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = User::query();

        if (!$this->option('force')) {
            $query->whereNull('avatar_id');
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->info('No users need avatar generation.');
            return self::SUCCESS;
        }

        $this->info("Generating avatars for {$users->count()} users...");
        $bar = $this->output->createProgressBar($users->count());
        $bar->start();

        foreach ($users as $user) {
            try {
                $this->generateAvatar($user);
            } catch (\Exception $e) {
                $this->error("\nFailed to generate avatar for {$user->name}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Avatar generation completed!');

        return self::SUCCESS;
    }

    /**
     * Generate avatar for a single user.
     */
    private function generateAvatar(User $user): void
    {
        // Generate avatar URL from UI Avatars
        $avatarUrl = $this->getAvatarUrl($user->name);

        // Download the image using file_get_contents with SSL bypass for local development
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $imageContent = @file_get_contents($avatarUrl, false, $context);

        if ($imageContent === false) {
            throw new \Exception('Failed to download avatar from UI Avatars');
        }

        // Generate unique filename
        $filename = 'avatars/' . Str::slug($user->name) . '-' . $user->id . '.png';

        // Store the image
        $disk = config('curator.disk', 'public');
        Storage::disk($disk)->put($filename, $imageContent);

        // Create Curator Media record
        $media = Media::create([
            'disk' => $disk,
            'directory' => 'avatars',
            'visibility' => 'public',
            'name' => Str::slug($user->name) . '-' . $user->id,
            'path' => $filename,
            'type' => 'image',
            'ext' => 'png',
            'size' => strlen($imageContent),
            'width' => 128,
            'height' => 128,
            'alt' => "Avatar của {$user->name}",
            'title' => "Avatar - {$user->name}",
        ]);

        // Update user with avatar_id
        $user->update(['avatar_id' => $media->id]);
    }

    /**
     * Get avatar URL from UI Avatars service.
     */
    private function getAvatarUrl(string $name): string
    {
        $params = http_build_query([
            'name' => $name,
            'size' => 128,
            'background' => 'random',
            'color' => 'ffffff',
            'bold' => 'true',
            'format' => 'png',
        ]);

        return "https://ui-avatars.com/api/?{$params}";
    }
}
