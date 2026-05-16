<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\QaGateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class QaGateCommandTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_file($path)) { @unlink($path); continue; }
            if (is_dir($path)) {
                foreach (new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                ) as $node) {
                    $node->isDir() ? @rmdir($node->getPathname()) : @unlink($node->getPathname());
                }
                @rmdir($path);
            }
        }
        $this->temporaryPaths = [];
    }

    public function testQaGateFailsWhenMasterJsonNotFound(): void
    {
        $tester = new CommandTester(new QaGateCommand());
        $statusCode = $tester->execute(['blueprint-dir' => '/tmp/nonexistent']);
        self::assertSame(1, $statusCode);
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testQaGateFailsWhenHeroBibleMissing(): void
    {
        $dir = $this->createTempDir();
        file_put_contents($dir.'/master.json', json_encode([
            'metadata' => ['slug' => 'test-book'],
            'heroBible' => [],
            'visualBible' => ['style_rules' => ['rule1'], 'palette' => 'blue', 'lighting' => 'warm'],
        ]));
        file_put_contents($dir.'/claude-qa-report.json', json_encode([
            'verdict' => 'GO',
            'scores' => ['editorial' => 10, 'imageability' => 10, 'heroConsistency' => 10, 'localeCompleteness' => 10, 'bedtimeSafety' => 10, 'premiumBelgium' => 10],
            'blockingIssues' => [],
        ]));

        $tester = new CommandTester(new QaGateCommand());
        $statusCode = $tester->execute(['blueprint-dir' => $dir]);
        self::assertSame(1, $statusCode);
        self::assertStringContainsString('heroBible', $tester->getDisplay());
    }

    public function testQaGateFailsWhenVisualBibleMissing(): void
    {
        $dir = $this->createTempDir();
        file_put_contents($dir.'/master.json', json_encode([
            'metadata' => ['slug' => 'test-book'],
            'heroBible' => ['identityRules' => ['rule1'], 'characterDesign' => 'tall', 'forbiddenDrift' => ['no change']],
            'visualBible' => [],
        ]));
        file_put_contents($dir.'/claude-qa-report.json', json_encode([
            'verdict' => 'GO',
            'scores' => ['editorial' => 10, 'imageability' => 10, 'heroConsistency' => 10, 'localeCompleteness' => 10, 'bedtimeSafety' => 10, 'premiumBelgium' => 10],
            'blockingIssues' => [],
        ]));

        $tester = new CommandTester(new QaGateCommand());
        $statusCode = $tester->execute(['blueprint-dir' => $dir]);
        self::assertSame(1, $statusCode);
        self::assertStringContainsString('visualBible', $tester->getDisplay());
    }

    public function testQaGateFailsWhenScoresBelowPremium(): void
    {
        $dir = $this->createTempDir();
        file_put_contents($dir.'/master.json', json_encode([
            'metadata' => ['slug' => 'test-book'],
            'heroBible' => ['identityRules' => ['rule1'], 'characterDesign' => 'tall', 'forbiddenDrift' => ['no change']],
            'visualBible' => ['style_rules' => ['rule1'], 'palette' => 'blue', 'lighting' => 'warm'],
        ]));
        file_put_contents($dir.'/claude-qa-report.json', json_encode([
            'verdict' => 'NO_GO',
            'scores' => ['editorial' => 6, 'imageability' => 5, 'heroConsistency' => 7, 'localeCompleteness' => 6, 'bedtimeSafety' => 5, 'premiumBelgium' => 6],
            'blockingIssues' => ['Generic content'],
        ]));

        $tester = new CommandTester(new QaGateCommand());
        $statusCode = $tester->execute(['blueprint-dir' => $dir]);
        self::assertSame(1, $statusCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('QA GATE FAILURE', $display);
        self::assertStringContainsString('90', $display);
    }

    public function testQaGatePassesWithPremiumScores(): void
    {
        $dir = $this->createTempDir();
        file_put_contents($dir.'/master.json', json_encode([
            'metadata' => ['slug' => 'test-book'],
            'heroBible' => ['identityRules' => ['rule1', 'rule2'], 'characterDesign' => 'brave child', 'forbiddenDrift' => ['no age change', 'no clothing change']],
            'visualBible' => ['style_rules' => ['watercolor'], 'palette' => 'blue and gold', 'lighting' => 'golden hour'],
        ]));
        file_put_contents($dir.'/claude-qa-report.json', json_encode([
            'verdict' => 'GO',
            'scores' => ['editorial' => 9, 'imageability' => 9, 'heroConsistency' => 10, 'localeCompleteness' => 9, 'bedtimeSafety' => 10, 'premiumBelgium' => 9],
            'blockingIssues' => [],
        ]));

        $tester = new CommandTester(new QaGateCommand());
        $statusCode = $tester->execute(['blueprint-dir' => $dir]);
        self::assertSame(0, $statusCode, $tester->getDisplay());
        self::assertStringContainsString('PASSED', $tester->getDisplay());
    }

    public function testQaGateWarnsOnIdenticalScores(): void
    {
        $dir = $this->createTempDir();
        file_put_contents($dir.'/master.json', json_encode([
            'metadata' => ['slug' => 'test-book'],
            'heroBible' => ['identityRules' => ['rule1'], 'characterDesign' => 'tall', 'forbiddenDrift' => ['no change']],
            'visualBible' => ['style_rules' => ['rule1'], 'palette' => 'blue', 'lighting' => 'warm'],
        ]));
        file_put_contents($dir.'/claude-qa-report.json', json_encode([
            'verdict' => 'GO',
            'scores' => ['editorial' => 9, 'imageability' => 9, 'heroConsistency' => 9, 'localeCompleteness' => 9, 'bedtimeSafety' => 9, 'premiumBelgium' => 9],
            'blockingIssues' => [],
        ]));

        $tester = new CommandTester(new QaGateCommand());
        $statusCode = $tester->execute(['blueprint-dir' => $dir]);
        self::assertSame(0, $statusCode, $tester->getDisplay());
        self::assertStringContainsString('identical', $tester->getDisplay());
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir().'/lc-qagate-'.bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->temporaryPaths[] = $dir;
        return $dir;
    }
}
