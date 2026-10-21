<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Only the workflow service moves an activity along the approval chain, so that scope, approver lists, signing in
 * order and budget confirmation apply everywhere. A screen that writes such a status itself skips all of that
 * (the Review button once did). Seeders and tests are not scanned; the Simulator, which only handles simulation
 * records, is allowed.
 */
class NoDirectStatusUpdateTest extends TestCase
{
    /** @return array<int, string> the files, relative to the project, that write an activity status themselves */
    public static function offenders(string $root): array
    {
        $root = str_replace('\\', '/', $root);
        $allowed = [
            $root.'/app/Services/ActivityWorkflow.php',
            // the Simulator works only on simulation records (Activity::testData()), which reports and the audit leave out
            $root.'/resources/views/livewire/testing/simulation-list.blade.php',
        ];
        $patterns = [
            // an approval-chain status written as a constant, on any model call
            '/(update|forceFill|fill)\(\[[^\]]*\'status\'\s*=>\s*Activity::STATUS_(SUBMITTED|REVIEWED|VALIDATED|APPROVED|WAITING_APPROVAL)/',
            // any status written straight onto an activity (the old Review button did this with a variable)
            '/\$activity->(update|forceFill|fill)\(\[[^\]]*\'status\'\s*=>/',
            '/Activity::[^;]*->update\(\[[^\]]*\'status\'\s*=>/',
        ];
        $found = [];

        foreach (['app', 'resources/views'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if ($file->getExtension() !== 'php' || in_array($path, $allowed, true)) {
                    continue;
                }
                $text = file_get_contents($path);
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $text)) {
                        $found[] = substr($path, strlen($root) + 1);
                        break;
                    }
                }
            }
        }

        return $found;
    }

    public function test_no_code_outside_the_workflow_service_writes_an_approval_chain_status(): void
    {
        $this->assertSame([], self::offenders(dirname(__DIR__, 2)), 'Move activities through App\Services\ActivityWorkflow instead.');
    }
}
