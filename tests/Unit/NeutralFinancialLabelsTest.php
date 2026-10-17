<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Planned amounts are not called an authorised "budget" in screens and reports: whether the institution
 * holds an authorised allocation, and what to call it, is for the institution to decide (docs/improvement R-FIN-02).
 */
class NeutralFinancialLabelsTest extends TestCase
{
    public function test_no_screen_or_report_calls_planned_amounts_an_approved_budget_or_a_budget_proposal(): void
    {
        $root = dirname(__DIR__, 2).'/resources/views';
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $text = file_get_contents($file->getPathname());
            if (preg_match('/approved budget|budget proposal/i', $text)) {
                $found[] = str_replace($root, 'resources/views', $file->getPathname());
            }
        }

        $this->assertSame([], $found, 'Use the neutral wording ("approved plan amount", "planned resources").');
    }
}
