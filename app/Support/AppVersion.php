<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * The application's version.
 *
 *  - release: MAJOR.MINOR.PATCH from the VERSION file, raised with `php artisan paa:release`
 *    (PATCH = fixes, MINOR = new features, MAJOR = changes that break data or how people work).
 *  - build:   the release plus the commits made since it, e.g. 1.4.0+3.e54f2f1 (3 commits after
 *    v1.4.0, at commit e54f2f1), so every change has its own number. A tagged release is just 1.4.0.
 *
 * Where .git exists the build comes from `git describe` and is kept in bootstrap/cache/version.json,
 * worked out again only when a commit or a tag changes. Without git (a Docker image, Railway) the
 * commit comes from APP_COMMIT or the host's variable (config app.commit).
 */
final class AppVersion
{
    private static ?array $info = null;

    /** @return array{release: string, build: string, commit: ?string, commits_since: ?int, date: ?string, source: string} */
    public static function info(): array
    {
        return self::$info ??= self::resolve();
    }

    public static function release(): string
    {
        return self::info()['release'];
    }

    public static function build(): string
    {
        return self::info()['build'];
    }

    /** Forget what was worked out and, where git exists, work it out again now. */
    public static function refresh(): array
    {
        self::$info = null;
        @unlink(self::stampFile());

        return self::info();
    }

    public static function stampFile(): string
    {
        return base_path('bootstrap/cache/version.json');
    }

    /** The release in the VERSION file (0.0.0 when there is none). */
    public static function declaredRelease(): string
    {
        $v = is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : '';

        return self::isRelease($v) ? $v : '0.0.0';
    }

    public static function isRelease(string $v): bool
    {
        return (bool) preg_match('/^\d+\.\d+\.\d+$/', $v);
    }

    /** The release after $current: 'patch', 'minor', 'major', or an exact x.y.z. */
    public static function next(string $current, string $level): string
    {
        if (self::isRelease($level)) {
            return $level;
        }
        [$major, $minor, $patch] = array_map('intval', explode('.', $current));

        return match ($level) {
            'major' => ($major + 1).'.0.0',
            'minor' => $major.'.'.($minor + 1).'.0',
            'patch' => $major.'.'.$minor.'.'.($patch + 1),
            default => throw new \InvalidArgumentException("Unknown release level [$level]: use patch, minor, major or x.y.z."),
        };
    }

    /** 1.4.0 at the release itself; 1.4.0+3.e54f2f1 after it; 1.4.0+e54f2f1 when the count is unknown. */
    public static function compose(string $release, ?int $commitsSince, ?string $commit): string
    {
        if ($commitsSince === 0 || ! $commit) {
            return $release;
        }

        return $release.'+'.($commitsSince !== null ? $commitsSince.'.' : '').$commit;
    }

    private static function resolve(): array
    {
        $release = self::declaredRelease();
        $signature = self::gitSignature();

        if ($signature !== null) {
            $stamp = self::readStamp();
            if ($stamp && ($stamp['signature'] ?? null) === $signature && ($stamp['release'] ?? null) === $release) {
                return array_diff_key($stamp, ['signature' => true]);
            }
            $info = self::fromGit($release);
            if ($info) {
                @file_put_contents(self::stampFile(), json_encode($info + ['signature' => $signature], JSON_PRETTY_PRINT));

                return $info;
            }
        }

        $commit = config('app.commit') ? substr((string) config('app.commit'), 0, 7) : null;

        return [
            'release' => $release,
            'build' => self::compose($release, null, $commit),
            'commit' => $commit,
            'commits_since' => null,
            'date' => null,
            'source' => $commit ? 'environment' : 'VERSION file',
        ];
    }

    private static function fromGit(string $release): ?array
    {
        try {
            $git = fn (string ...$args) => Process::path(base_path())->timeout(10)->run(['git', ...$args]);

            $head = $git('rev-parse', '--short=7', 'HEAD');
            if (! $head->successful()) {
                return null;
            }
            $commit = trim($head->output());
            $date = trim($git('log', '-1', '--format=%cs')->output()) ?: null;

            // commits since the tag of this release (v1.4.0); unknown when that tag does not exist yet
            $since = null;
            $describe = $git('describe', '--tags', '--long', '--abbrev=7', '--match', 'v'.$release);
            if ($describe->successful() && preg_match('/-(\d+)-g[0-9a-f]+$/', trim($describe->output()), $m)) {
                $since = (int) $m[1];
            }

            return [
                'release' => $release,
                'build' => self::compose($release, $since, $commit),
                'commit' => $commit,
                'commits_since' => $since,
                'date' => $date,
                'source' => 'git',
            ];
        } catch (\Throwable) {
            return null; // git missing or not runnable here
        }
    }

    /** Changes whenever a commit, checkout or tag changes; null where there is no .git. */
    private static function gitSignature(): ?string
    {
        $dir = base_path('.git');
        if (! is_dir($dir)) {
            return null;
        }
        $parts = [];
        foreach (['logs/HEAD', 'HEAD', 'packed-refs', 'refs/tags'] as $p) {
            $parts[] = @filemtime($dir.'/'.$p) ?: 0;
        }
        foreach (glob($dir.'/refs/tags/*') ?: [] as $tag) {
            $parts[] = basename($tag);
        }

        return md5(implode('|', $parts));
    }

    private static function readStamp(): ?array
    {
        $file = self::stampFile();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : null;
    }
}
