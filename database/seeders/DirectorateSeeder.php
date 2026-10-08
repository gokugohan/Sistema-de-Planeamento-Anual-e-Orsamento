<?php

namespace Database\Seeders;

use App\Models\Directorate;
use Illuminate\Database\Seeder;

/**
 * Organic structure of the Ministério dos Transportes e Comunicações (MTC),
 * Timor-Leste.
 *
 * Source: Decreto-Lei n.º 75/2023, de 15 de setembro, as amended by
 * Decreto-Lei n.º 12/2026, de 11 de março (Jornal da República, Série I, N.º 10),
 * which extinguished the single Direção-Geral dos Transportes e Comunicações and
 * created the Direção-Geral dos Transportes and the Direção-Geral das Comunicações,
 * plus two new gabinetes (GPIAIAMT and GDSC).
 *
 * The organic law stops at the direção nacional / unidade level; departments are
 * defined later by ministerial diploma and are intentionally NOT invented here.
 * Abbreviations are those given in the law; DNC (Cabotagem) and the unit codes
 * are working abbreviations because the law gives none.
 */
class DirectorateSeeder extends Seeder
{
    private const ORGANIC_LAW_YEAR = 2023; // DL 75/2023
    private const AMENDMENT_YEAR = 2026;   // DL 12/2026

    public function run(): void
    {
        // ── Gabinetes (report directly to the Minister) ──────────────────
        $this->independent('GIGTC', 'Gabinete de Inspeção-Geral dos Transportes e Comunicações', self::ORGANIC_LAW_YEAR);
        $gppc = $this->independent('GPPC', 'Gabinete de Planeamento, Política e Cooperação', self::ORGANIC_LAW_YEAR);
        $this->independent('GJ', 'Gabinete Jurídico', self::ORGANIC_LAW_YEAR);
        $this->independent('GPIAIAMT', 'Gabinete de Prevenção e Investigação de Acidentes e Incidentes Aéreos, Marítimos e Terrestres', self::AMENDMENT_YEAR);
        $gdsc = $this->independent('GDSC', 'Gabinete de Digitalização e Segurança Cibernética', self::AMENDMENT_YEAR);

        $this->units($gppc, self::ORGANIC_LAW_YEAR, [
            ['UPMA', 'Unidade de Planeamento, Monitorização e Avaliação'],
            ['UPC', 'Unidade de Política e Cooperação'],
            ['UA', 'Unidade de Apoio'],
        ]);

        $this->units($gdsc, self::AMENDMENT_YEAR, [
            ['UDIG', 'Unidade para o Digital'],
            ['USC', 'Unidade de Segurança Cibernética'],
        ]);

        // ── Direções-Gerais and their Direções Nacionais ─────────────────
        $dgt = $this->generalDirectorate('DGT', 'Direção-Geral dos Transportes', self::AMENDMENT_YEAR);
        $this->nationalDirectorates($dgt, [
            ['DNTT', 'Direção Nacional dos Transportes Terrestres', self::ORGANIC_LAW_YEAR],
            ['DNTM', 'Direção Nacional dos Transportes Marítimos', self::ORGANIC_LAW_YEAR],
            ['DNC', 'Direção Nacional de Cabotagem', self::AMENDMENT_YEAR],
        ]);

        $dgc = $this->generalDirectorate('DGC', 'Direção-Geral das Comunicações', self::AMENDMENT_YEAR);
        $this->nationalDirectorates($dgc, [
            ['DNMG', 'Direção Nacional de Meteorologia e Geofísica', self::ORGANIC_LAW_YEAR],
            ['DNSP', 'Direção Nacional dos Serviços Postais', self::ORGANIC_LAW_YEAR],
            ['DNIC', 'Direção Nacional de Infraestruturas de Comunicações', self::ORGANIC_LAW_YEAR],
        ]);

        $dgaf = $this->generalDirectorate('DGAF', 'Direção-Geral de Administração e Finanças', self::ORGANIC_LAW_YEAR);
        $this->nationalDirectorates($dgaf, [
            ['DNALP', 'Direção Nacional de Administração, Logística e Património', self::ORGANIC_LAW_YEAR],
            ['DNOGF', 'Direção Nacional do Orçamento e Gestão Financeira', self::ORGANIC_LAW_YEAR],
            ['DNA', 'Direção Nacional de Aprovisionamento', self::ORGANIC_LAW_YEAR],
            ['DNRH', 'Direção Nacional dos Recursos Humanos', self::ORGANIC_LAW_YEAR],
        ]);

        // ── Administração indireta do Estado (own legal personality/budget) ──
        $this->independent('ANATL', 'Administração de Aeroportos e Navegação Aérea de Timor-Leste, E.P.', self::ORGANIC_LAW_YEAR);
        $this->independent('APORTIL', 'Administração dos Portos de Timor-Leste, I.P.', self::ORGANIC_LAW_YEAR);
        $this->independent('AACTL', 'Autoridade da Aviação Civil de Timor-Leste, I.P.', self::ORGANIC_LAW_YEAR);
        $this->independent('ANC', 'Autoridade Nacional de Comunicações, I.P.', self::ORGANIC_LAW_YEAR);
        $this->independent('TICTIMOR', 'Agência de Tecnologias de Informação e Comunicação, I.P. (TIC Timor)', self::ORGANIC_LAW_YEAR);
    }

    private function independent(string $code, string $name, int $since): Directorate
    {
        return $this->upsert($code, $name, 'Independent', null, $since);
    }

    private function generalDirectorate(string $code, string $name, int $since): Directorate
    {
        return $this->upsert($code, $name, 'General Directorate', null, $since);
    }

    /** @param array<int, array{0:string,1:string,2:int}> $items */
    private function nationalDirectorates(Directorate $parent, array $items): void
    {
        foreach ($items as [$code, $name, $since]) {
            $this->upsert($code, $name, 'National Directorate', $parent, $since);
        }
    }

    /** @param array<int, array{0:string,1:string}> $items */
    private function units(Directorate $parent, int $since, array $items): void
    {
        foreach ($items as [$code, $name]) {
            $this->upsert($code, $name, 'Unit', $parent, $since);
        }
    }

    private function upsert(string $code, string $name, string $type, ?Directorate $parent, int $since): Directorate
    {
        return Directorate::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type' => $type,
            'parent_id' => $parent?->id,
            'established_year' => $since,
            'active' => true,
        ]);
    }
}
