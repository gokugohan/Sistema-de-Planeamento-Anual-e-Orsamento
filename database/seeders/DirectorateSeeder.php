<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Directorate;

class DirectorateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Direção-Geral dos Serviços Corporativos
        $dgsc = Directorate::updateOrCreate(['code' => 'DGSC'], [
            'name' => 'Direção-Geral dos Serviços Corporativos',
            'type' => 'General Directorate',
            'active' => true
        ]);

        $this->seedDN($dgsc, 'DNAF', 'Direção Nacional de Administração e Finanças', [
            ['DPEO', 'Departamento de Programação e Execução Orçamental'],
            ['DRPC', 'Departamento de Receitas, Pagamentos e Contabilidade'],
            ['DAEG', 'Departamento de Administração e Expediente Geral'],
        ]);

        $this->seedDN($dgsc, 'DNRH', 'Direção Nacional dos Recursos Humanos', [
            ['DRP', 'Departamento de Recrutamento de Pessoal'],
            ['DGP', 'Departamento de Gestão de Pessoal'],
            ['DADP', 'Departamento Avaliação de Desempenho de Pessoal'],
        ]);

        $this->seedDN($dgsc, 'DGPL', 'Direção Nacional de Gestão do Património e Logística', [
            ['DPAT', 'Departamento do Património'],
            ['DLOG', 'Departamento de Logística'],
        ]);

        $this->seedDN($dgsc, 'DNPA', 'Direção Nacional de Planeamento e de Avaliação', [
            ['DPLA', 'Departamento de Planeamento'],
            ['DAVA', 'Departamento de Avaliação'],
        ]);


        // 2. Direção-Geral da Descentralização e do Governo Local
        $dgdgl = Directorate::updateOrCreate(['code' => 'DGDGL'], [
            'name' => 'Direção-Geral da Descentralização e do Governo Local',
            'type' => 'General Directorate',
            'active' => true
        ]);

        $this->seedDN($dgdgl, 'DNGL', 'Direção Nacional do Governo Local', [
            ['DGLoc', 'Departamento de Governação Local'],
            ['DIAL', 'Departamento de Informação à Administração Local'],
            ['DDOT', 'Departamento de Demografia e Organização Territorial'],
        ]);

        $this->seedDN($dgdgl, 'DNAIPDI', 'Direção Nacional de Apoio à Implementação do Planeamento e Desenvolvimento Integrado', [
            ['DAPIM', 'Departamento de Apoio à Programação de Investimentos Municipais'],
            ['DAEIM', 'Departamento de Apoio à Execução de Investimentos Municipais'],
            ['DMAIM', 'Departamento de Monitorização e Avaliação dos Investimentos Municipais'],
        ]);

        $this->seedDN($dgdgl, 'DNAPAS', 'Direção Nacional de Apoio à Administração dos Sucos', [
            ['DAJPRC', 'Departamento de Assuntos Jurídicos e de Prevenção e Resolução de Conflitos'],
            ['DGIMFLC', 'Departamento de Gestão dos Incentivos Materiais e Financeiros às Lideranças Comunitárias'],
            ['DCLC', 'Departamento de Capacitação das Lideranças Comunitárias'],
        ]);

        $this->seedDN($dgdgl, 'DNPFM', 'Direção Nacional do Planeamento e das Finanças Municipais', [
            ['DAGFM', 'Departamento de Apoio à Gestão Financeira Municipal'],
            ['DCGFM', 'Departamento de Capacitação para Gestão Financeira Municipal'],
        ]);

        $this->seedDN($dgdgl, 'DNAIM', 'Direção Nacional de Apoio à Instalação dos Municípios', [
            ['DPL', 'Departamento de Política e Legislação'],
            ['DAOC', 'Departamento de Apoio aos Órgãos de Coordenação e Consulta'],
            ['DECC', 'Departamento de Educação Cívica e Comunicação'],
        ]);


        // 3. Direção-Geral da Simplificação e Modernização da Administração Local
        $dgsmal = Directorate::updateOrCreate(['code' => 'DGSMAL'], [
            'name' => 'Direção-Geral da Simplificação e Modernização da Administração Local',
            'type' => 'General Directorate',
            'active' => true
        ]);

        $this->seedDN($dgsmal, 'DNSAPE', 'Direção Nacional para a Simplificação Administrativa e Plataforma Electrónica', [
            ['DSA', 'Departamento para a Simplificação Administrativa'],
            ['DPMBU', 'Departamento para o Portal Municipal e Balcão Único'],
        ]);

        $this->seedDN($dgsmal, 'DNMAAL', 'Direção Nacional de Monitorização e Avaliação da Administração Local', [
            ['DMAL', 'Departamento de Monitorização da Administração Local'],
            ['DAAL', 'Departamento de Avaliação da Administração Local'],
        ]);

        $this->seedDN($dgsmal, 'DNFC', 'Direção Nacional para a Formação e a Cooperação', [
            ['DFOR', 'Departamento de Formação'],
            ['DCOP', 'Departamento de Cooperação'],
        ]);


        // 4. Direção-Geral da Toponímia e da Organização Urbana
        $dgtou = Directorate::updateOrCreate(['code' => 'DGTOU'], [
            'name' => 'Direção-Geral da Toponímia e da Organização Urbana',
            'type' => 'General Directorate',
            'active' => true
        ]);

        $this->seedDN($dgtou, 'DNTOPO', 'Direção Nacional de Toponímia', [
            ['DRNT', 'Departamento de Registo Nacional de Topónimos'],
            ['DAST', 'Departamento de Apoio aos Serviços de Toponímia'],
        ]);

        $this->seedDN($dgtou, 'DNSOU', 'Direção Nacional para os Serviços da Organização Urbana', [
            ['DASGMC', 'Departamento de Apoio aos Serviços de Gestão de Mercados e Comércio'],
            ['DASGEP', 'Departamento de Apoio aos Serviços de Gestão e Espaços Públicos'],
        ]);

        $this->seedDN($dgtou, 'DNGHOP', 'Direção Nacional para a Gestão de Higiene e Ordem Pública', [
            ['DASHOP', 'Departamento de Apoio aos Serviços de Higiene e Ordem Pública'],
            ['DSRTRS', 'Departamento de Sistemas de Recolha e Tratamento de Resíduos Sólidos'],
        ]);


        // 5. Direção-Geral do Desenvolvimento Local
        $dgdl = Directorate::updateOrCreate(['code' => 'DGDL'], [
            'name' => 'Direção-Geral do Desenvolvimento Local',
            'type' => 'General Directorate',
            'active' => true
        ]);

        $this->seedDN($dgdl, 'DNADM', 'Direção Nacional de Apoio ao Desenvolvimento Municipal', [
            ['DAPEM', 'Departamento de Apoio ao Planeamento Estratégico Municipal'],
            ['DMEPEM', 'Departamento de Monitorização da Execução do Planeamento Estratégico Municipal'],
        ]);

        $this->seedDN($dgdl, 'DNADC', 'Direção Nacional de Apoio ao Desenvolvimento Comunitário', [
            ['DAPC', 'Departamento de Apoio ao Planeamento Comunitário'],
            ['DMEPC', 'Departamento de Monitorização da Execução do Planeamento Comunitário'],
        ]);

        $this->seedDN($dgdl, 'DNPEAL', 'Direção Nacional para a Promoção da Eficiência da Administração Local', [
            ['DMEAL', 'Departamento de Monitorização da Eficiência da Administração Local'],
            ['DPEAL_DEP', 'Departamento de Promoção da Eficiência da Administração Local'],
        ]);


        // Independent Entities
        Directorate::updateOrCreate(['code' => 'GIGA'], ['name' => 'GABINETE DA INSPEÇÃO-GERAL E AUDITORIA', 'type' => 'Independent']);
        Directorate::updateOrCreate(['code' => 'STPNDS'], ['name' => 'SECRETARIADO TÉCNICO DO PROGRAMA NACIONAL DE DESENVOLVIMENTO DOS SUCOS', 'type' => 'Independent']);
        Directorate::updateOrCreate(['code' => 'UPCS'], ['name' => 'UNIDADE DE PROTOCOLO E COMUNICAÇÃO SOCIAL', 'type' => 'Independent']);
        Directorate::updateOrCreate(['code' => 'UAD'], ['name' => 'UNIDADE DE APROVISIONAMENTO DESCENTRALIZADO', 'type' => 'Independent']);
        Directorate::updateOrCreate(['code' => 'UIT'], ['name' => 'UNIDADE DE INFORMÁTICA E TECNOLOGIA', 'type' => 'Independent']);
        Directorate::updateOrCreate(['code' => 'UCSARA'], ['name' => 'UNIDADE DE COORDENAÇÃO DE SERVIÇOS ADMINISTRATIVOS DA REGIÃO ADMINISTRATIVA ESPECIAL DE OE-CUSSE AMBENO E DA AUTORIDADE ADMINISTRATIVA DE ATAÚRO', 'type' => 'Independent']);
    }

    private function seedDN($dg, $code, $name, $departments = []): void
    {
        $dn = Directorate::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type' => 'National Directorate',
            'parent_id' => $dg->id,
            'active' => true
        ]);

        foreach ($departments as $dept) {
            Directorate::updateOrCreate(['code' => $dept[0]], [
                'name' => $dept[1],
                'type' => 'Department',
                'parent_id' => $dn->id,
                'active' => true
            ]);
        }
    }
}
