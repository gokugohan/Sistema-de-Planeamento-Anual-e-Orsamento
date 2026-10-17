<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every interface string written in code (__('…'), trans_choice('…'), Institution::label('…')) must have a
 * Portuguese and a Tetum text, or users of those languages see English in the middle of a screen.
 * Add the new string to lang/pt.json and lang/tet.json when this fails.
 */
class TranslationCompletenessTest extends TestCase
{
    /** @return array<int, string> every literal translation key used in views, app code and config */
    private function usedKeys(): array
    {
        $keys = [];
        $paths = [resource_path('views'), app_path(), config_path()];
        foreach ($paths as $path) {
            foreach (File::allFiles($path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                preg_match_all("/(?:__|trans_choice|Institution::label)\\(\\s*'((?:\\\\.|[^'\\\\])*)'/", File::get($file->getPathname()), $m);
                foreach ($m[1] as $key) {
                    $keys[] = str_replace("\\'", "'", $key);
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return array<int, string> texts the code picks at run time, e.g. __($activity->status) */
    private function runtimeKeys(): array
    {
        $keys = ['Independent', 'General Directorate', 'National Directorate', 'Department', 'Unit', 'Cabinet', 'Regional Office'];
        foreach ([\App\Models\Activity::class] as $class) {
            foreach ((new \ReflectionClass($class))->getConstants() as $name => $value) {
                if (str_starts_with($name, 'STATUS_') && is_string($value)) {
                    $keys[] = $value;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    private function table(string $locale): array
    {
        return json_decode(File::get(base_path("lang/{$locale}.json")), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_every_interface_string_is_translated_to_portuguese_and_tetum(): void
    {
        $pt = $this->table('pt');
        $tet = $this->table('tet');

        $missing = [];
        foreach (array_merge($this->usedKeys(), $this->runtimeKeys()) as $key) {
            if (preg_match('/^[a-z_]+\.[a-z_.]+$/', $key)) {
                continue; // a key of a PHP language group (validation.*, auth.*), not interface text
            }
            foreach (['pt' => $pt, 'tet' => $tet] as $locale => $table) {
                if (! array_key_exists($key, $table)) {
                    $missing[] = "[{$locale}] {$key}";
                }
            }
        }

        $this->assertSame([], $missing, "Strings without a translation:\n".implode("\n", array_slice($missing, 0, 30)));
    }

    public function test_translations_keep_their_placeholders_and_plural_forms(): void
    {
        $problems = [];
        foreach (['pt', 'tet'] as $locale) {
            foreach ($this->table($locale) as $key => $text) {
                if (! is_string($text) || $text === $key) {
                    continue;
                }
                preg_match_all('/:[a-z]+/', $key, $a);
                preg_match_all('/:[a-z]+/', $text, $b);
                sort($a[0]);
                sort($b[0]);
                if ($a[0] !== $b[0]) {
                    $problems[] = "[{$locale}] placeholders differ: {$key}";
                }
                if (substr_count($key, '|') !== substr_count($text, '|')) {
                    $problems[] = "[{$locale}] plural forms differ: {$key}";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", array_slice($problems, 0, 30)));
    }
}
