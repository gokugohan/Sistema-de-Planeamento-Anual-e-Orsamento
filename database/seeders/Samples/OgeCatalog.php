<?php

namespace Database\Seeders\Samples;

/**
 * The annual plans of every entity of the State Budget 2027 (proposal), read from the budget books:
 * Orçamento Geral do Estado 2027, Elementos Informativos, Volumes II-A and II-B, Plano de Ação Anual (Anexo K).
 * One file per entity in data/oge_2027 (see its README), and index.json listing them.
 *
 * Each file holds the entity's divisions as printed in the book (its units), its programmes, its activities with
 * indicator, starting point, target, owning division and amount per economic category, and its outcomes and outputs.
 * The units are grouped by their budget code: the divisions of one code group go under that group's general
 * directorate when the book names one; the rest stay at the top. That is a reading of the codes, not of each
 * entity's organic law.
 */
final class OgeCatalog
{
    public const YEAR = 2027;

    public const DIR = __DIR__.'/data/oge_2027';

    /** How the picker groups the entities. */
    public const KINDS = [
        'ministry' => 'Ministries',
        'secretariat' => 'Secretariats of State',
        'body' => 'Organs of State, institutes and agencies',
        'municipal' => 'Municipal and regional authorities',
        'fund' => 'Funds and general appropriations',
    ];

    /** @var array<string, array>|null */
    private static ?array $index = null;

    /** @var array<string, array> */
    private static array $entities = [];

    /** @return array<string, array{code: string, name: string, abbr: string, kind: string, activities: int, units: int, total: float}> by code */
    public static function index(): array
    {
        if (self::$index === null) {
            $rows = json_decode(file_get_contents(self::DIR.'/index.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$index = collect($rows)->keyBy('code')->all();
        }

        return self::$index;
    }

    public static function has(string $code): bool
    {
        return isset(self::index()[$code]);
    }

    /** The entity's whole file (see data/oge_2027/README.md). */
    public static function entity(string $code): array
    {
        if (! self::has($code)) {
            throw new \InvalidArgumentException("Unknown State Budget entity '{$code}'.");
        }

        return self::$entities[$code] ??= json_decode(file_get_contents(self::DIR."/{$code}.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array<string, array>> the entities grouped by kind, in the order of KINDS */
    public static function grouped(): array
    {
        $groups = array_fill_keys(array_keys(self::KINDS), []);
        foreach (self::index() as $code => $e) {
            $groups[$e['kind']][$code] = $e;
        }

        return array_filter($groups);
    }

    /** The unit code that owns an activity: the first of its budget divisions that the entity lists. */
    public static function ownerUnit(string $entity, array $div): string
    {
        $byDiv = collect(self::entity($entity)['units'])->pluck('code', 'div');
        foreach ($div as $d) {
            if (isset($byDiv[$d])) {
                return $byDiv[$d];
            }
        }

        return (string) collect(self::entity($entity)['units'])->first()['code'];
    }
}
