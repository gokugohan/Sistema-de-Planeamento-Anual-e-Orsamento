<?php

namespace Database\Seeders\Samples;

/**
 * The budget's own "Div" (divisão) codes and the unit each one is loaded into.
 *
 * Every activity in the official annual plans (Livro Orçamental, Volume II, Anexo K) names the
 * division that carries it out, using a 7-digit code: the first three digits are the entity
 * (025 = Ministério dos Transportes e Comunicações, 056 = APORTIL, 059 = TIC Timor, 063 = AACTL,
 * 066 = ANC). The names below are the ones printed in those plans.
 *
 * Most divisions are units of the organic law and map to the same unit code the directorate seeder
 * already creates (DNTT, DNTM, …). Divisions that only exist in the budget (the Minister's office,
 * the municipal directorates, the divisions of AACTL and APORTIL's ferry operations) are added as
 * units too, so every real activity has a real owner.
 */
final class MtcCostCentres
{
    /** Municipal directorates of the ministry: div code => [unit code, municipality] */
    public const MUNICIPAL = [
        '0250207' => ['DMTC-AIL', 'Aileu'], '0250208' => ['DMTC-AIN', 'Ainaro'], '0250209' => ['DMTC-BAU', 'Baucau'],
        '0250210' => ['DMTC-BOB', 'Bobonaro'], '0250211' => ['DMTC-COV', 'Covalima'], '0250212' => ['DMTC-ERM', 'Ermera'],
        '0250213' => ['DMTC-LAU', 'Lautém'], '0250214' => ['DMTC-LIQ', 'Liquiçá'], '0250215' => ['DMTC-MAT', 'Manatuto'],
        '0250216' => ['DMTC-MAF', 'Manufahi'], '0250217' => ['DMTC-VIQ', 'Viqueque'],
    ];

    /** Divisions of the Autoridade da Aviação Civil (AACTL): div code => [unit code, name] */
    public const AACTL = [
        '0630101' => ['AACTL-GCA', 'Gabinete de Apoio ao Conselho de Administração'],
        '0630102' => ['AACTL-DNA', 'Direção de Navegação Aérea'],
        '0630103' => ['AACTL-DIA', 'Direção de Infraestrutura Aeroportuária'],
        '0630104' => ['AACTL-DSO', 'Direção de Segurança Operacional'],
        '0630105' => ['AACTL-DFS', 'Direção de Facilitação e Segurança'],
        '0630106' => ['AACTL-DLAM', 'Direção de Licenciamento e Acesso ao Mercado'],
        '0630107' => ['AACTL-DAF', 'Direção de Administração e Finanças'],
        '0630108' => ['AACTL-SA', 'Serviços de Aprovisionamento'],
    ];

    /** Every division code and the unit it is loaded into. */
    public static function unitCode(string $div): ?string
    {
        static $map = null;
        $map ??= [
            '0250101' => 'GM', '0250103' => 'GIGTC', '0250104' => 'GJ', '0250105' => 'GPPC', '0250106' => 'UPC',
            '0250201' => 'DGT', '0250202' => 'DNTM', '0250203' => 'DNMG', '0250204' => 'DNIC', '0250205' => 'DNTT', '0250206' => 'DNSP',
            '0250301' => 'DGAF', '0250302' => 'DNA', '0250303' => 'DNALP', '0250304' => 'DNOGF', '0250305' => 'DNRH',
            '0250401' => 'UPMA', '0250500' => 'DGC', '0250600' => 'GPIAIAMT', '0250700' => 'GDSC', '0250701' => 'UDIG', '0250702' => 'USC',
            '0560101' => 'APORTIL', '0560201' => 'APORTIL-BN', '0590101' => 'TICTIMOR', '0660101' => 'ANC',
        ] + array_map(fn ($m) => $m[0], self::MUNICIPAL) + array_map(fn ($m) => $m[0], self::AACTL);

        return $map[$div] ?? null;
    }

    /** The unit that stands for a whole entity when a line names no (or no known) division. */
    public const ENTITY_UNIT = ['025' => 'GPPC', '056' => 'APORTIL', '059' => 'TICTIMOR', '063' => 'AACTL', '066' => 'ANC'];

    /**
     * The unit that owns an activity: its single division, or, when it is carried out by several
     * (for instance every municipal directorate at once), the head of the area.
     *
     * @param  array<int, string>  $divisions
     */
    public static function ownerUnit(string $entity, array $divisions): string
    {
        $units = array_values(array_unique(array_filter(array_map(fn ($d) => self::unitCode($d), $divisions))));

        if (count($units) === 1) {
            return $units[0];
        }
        if ($units && ! array_diff($divisions, array_keys(self::MUNICIPAL))) {
            return 'DGT'; // all the municipal directorates together: the directorate-general that supervises them
        }

        return $units[0] ?? self::ENTITY_UNIT[$entity];
    }
}
