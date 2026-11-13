<?php

namespace App\Console\Commands;

use App\Support\AppVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Makes a release: raises the number in VERSION, writes what changed since the last release into
 * CHANGELOG.md (from the commit subjects), commits those two files only, and tags the commit vX.Y.Z.
 * Nothing is pushed; push with `git push --follow-tags` when ready.
 */
class MakeRelease extends Command
{
    protected $signature = 'paa:release
        {level=patch : patch (fixes), minor (new features), major (changes that break data or usage), or an exact x.y.z}
        {--notes= : A sentence that sums up the release, written above the list of changes}
        {--dry-run : Show what would be written, change nothing}';

    protected $description = 'Make a release: raise the version, write the changelog, commit and tag it';

    public function handle(): int
    {
        if (! is_dir(base_path('.git'))) {
            $this->error('Releases are made in the git working copy, not on a server.');

            return self::FAILURE;
        }

        $current = AppVersion::declaredRelease();
        $currentTagged = $this->tagExists('v'.$current);

        // the very first release keeps the number already in VERSION
        $level = (string) $this->argument('level');
        try {
            $next = $currentTagged ? AppVersion::next($current, $level) : (AppVersion::isRelease($level) ? $level : $current);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->tagExists('v'.$next)) {
            $this->error("v$next is already released.");

            return self::FAILURE;
        }
        if ($currentTagged && version_compare($next, $current, '<=')) {
            $this->error("v$next is not after the current release v$current.");

            return self::FAILURE;
        }
        if (trim($this->git('status', '--porcelain', '--', 'VERSION', 'CHANGELOG.md')) !== '') {
            $this->error('VERSION or CHANGELOG.md has uncommitted changes. Commit or undo them first.');

            return self::FAILURE;
        }

        $changes = $currentTagged
            ? array_filter(explode("\n", trim($this->git('log', '--no-merges', '--format=%s', 'v'.$current.'..HEAD'))))
            : [];
        if ($currentTagged && ! $changes) {
            $this->error("Nothing has been committed since v$current.");

            return self::FAILURE;
        }

        $entry = $this->entry($next, (string) $this->option('notes'), $changes, ! $currentTagged);

        $this->line($entry);
        if ($this->option('dry-run')) {
            $this->info("Dry run: v$next would be committed and tagged. Nothing was changed.");

            return self::SUCCESS;
        }

        file_put_contents(base_path('VERSION'), $next."\n");
        file_put_contents(base_path('CHANGELOG.md'), $this->withEntry($entry));

        $this->git('add', '--', 'VERSION', 'CHANGELOG.md');
        $this->git('commit', '-m', "Release v$next", '--', 'VERSION', 'CHANGELOG.md');
        $this->git('tag', '-a', 'v'.$next, '-m', "Release v$next");

        AppVersion::refresh();
        $this->info("Released v$next (committed and tagged). Push it with:  git push --follow-tags");

        return self::SUCCESS;
    }

    private function entry(string $version, string $notes, array $changes, bool $first): string
    {
        $lines = ['## ['.$version.'] — '.now()->toDateString(), ''];
        if ($notes !== '') {
            $lines[] = $notes;
            $lines[] = '';
        }
        if ($first && ! $changes) {
            $lines[] = '- First numbered release.';
        }
        foreach ($changes as $subject) {
            $lines[] = '- '.$subject;
        }

        return implode("\n", $lines)."\n";
    }

    private function withEntry(string $entry): string
    {
        $file = base_path('CHANGELOG.md');
        $text = is_file($file) ? (string) file_get_contents($file) : "# Changelog\n\nEvery release of the application, newest first. Numbers follow MAJOR.MINOR.PATCH: PATCH for fixes, MINOR for new features, MAJOR for changes that break data or how people work.\n";

        // newest first: before the first existing release, or at the end of the introduction
        $at = strpos($text, "\n## ");

        return $at === false
            ? rtrim($text)."\n\n".$entry
            : substr($text, 0, $at + 1).$entry."\n".substr($text, $at + 1);
    }

    private function tagExists(string $tag): bool
    {
        return Process::path(base_path())->run(['git', 'rev-parse', '-q', '--verify', 'refs/tags/'.$tag])->successful();
    }

    private function git(string ...$args): string
    {
        return Process::path(base_path())->run(['git', ...$args])->throw()->output();
    }
}
