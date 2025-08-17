<?php

/**
 * Laravel AI Engine Test Runner
 * 
 * This script provides a comprehensive test runner for the Laravel AI Engine package
 * with detailed reporting, coverage analysis, and test categorization.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Process\Process;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;

class TestRunner
{
    private ConsoleOutput $output;
    private array $testResults = [];
    private float $startTime;

    public function __construct()
    {
        $this->output = new ConsoleOutput();
        $this->startTime = microtime(true);
    }

    public function run(array $options = []): int
    {
        $this->output->writeln('<info>Laravel AI Engine Test Suite</info>');
        $this->output->writeln('<comment>Starting comprehensive test execution...</comment>');
        $this->output->writeln('');

        // Parse options
        $filter = $options['filter'] ?? null;
        $coverage = $options['coverage'] ?? false;
        $verbose = $options['verbose'] ?? false;
        $group = $options['group'] ?? null;

        // Run different test categories
        $testCategories = [
            'Unit Tests' => 'tests/Unit',
            'Feature Tests' => 'tests/Feature',
        ];

        if ($group) {
            $testCategories = array_filter($testCategories, function($path, $name) use ($group) {
                return stripos($name, $group) !== false;
            }, ARRAY_FILTER_USE_BOTH);
        }

        $totalTests = 0;
        $passedTests = 0;
        $failedTests = 0;

        foreach ($testCategories as $categoryName => $testPath) {
            $this->output->writeln("<info>Running {$categoryName}...</info>");
            
            $result = $this->runTestCategory($testPath, $filter, $coverage, $verbose);
            
            $totalTests += $result['total'];
            $passedTests += $result['passed'];
            $failedTests += $result['failed'];
            
            $this->testResults[$categoryName] = $result;
            
            $this->output->writeln('');
        }

        // Display summary
        $this->displaySummary($totalTests, $passedTests, $failedTests);

        // Generate detailed report if requested
        if ($verbose) {
            $this->generateDetailedReport();
        }

        return $failedTests > 0 ? 1 : 0;
    }

    private function runTestCategory(string $testPath, ?string $filter, bool $coverage, bool $verbose): array
    {
        $command = ['./vendor/bin/phpunit'];
        
        // Add test path
        $command[] = $testPath;
        
        // Add filter if specified
        if ($filter) {
            $command[] = '--filter';
            $command[] = $filter;
        }
        
        // Add coverage if requested
        if ($coverage) {
            $command[] = '--coverage-html';
            $command[] = 'coverage-report';
            $command[] = '--coverage-clover';
            $command[] = 'coverage.xml';
        }
        
        // Add verbose output if requested
        if ($verbose) {
            $command[] = '--verbose';
        }
        
        // Add configuration
        $command[] = '--configuration';
        $command[] = 'phpunit.xml';
        
        // Add colors
        $command[] = '--colors=always';

        $process = new Process($command);
        $process->setTimeout(300); // 5 minutes timeout
        
        $output = '';
        $process->run(function ($type, $buffer) use (&$output, $verbose) {
            $output .= $buffer;
            if ($verbose) {
                echo $buffer;
            }
        });

        return $this->parseTestOutput($output, $process->getExitCode());
    }

    private function parseTestOutput(string $output, int $exitCode): array
    {
        $result = [
            'total' => 0,
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
            'exit_code' => $exitCode
        ];

        // Parse PHPUnit output for test counts
        if (preg_match('/Tests: (\d+), Assertions: (\d+)/', $output, $matches)) {
            $result['total'] = (int)$matches[1];
            $result['passed'] = $exitCode === 0 ? $result['total'] : 0;
            $result['failed'] = $exitCode !== 0 ? $result['total'] : 0;
        }

        // Parse for specific failure information
        if (preg_match_all('/FAILURES!\s*Tests: (\d+), Assertions: (\d+), Failures: (\d+)/', $output, $matches)) {
            $result['total'] = (int)$matches[1][0];
            $result['failed'] = (int)$matches[3][0];
            $result['passed'] = $result['total'] - $result['failed'];
        }

        // Parse for errors
        if (preg_match_all('/ERRORS!\s*Tests: (\d+), Assertions: (\d+), Errors: (\d+)/', $output, $matches)) {
            $result['total'] = (int)$matches[1][0];
            $result['failed'] = (int)$matches[3][0];
            $result['passed'] = $result['total'] - $result['failed'];
        }

        // Extract error messages
        if (preg_match_all('/^\d+\) (.+?)$/m', $output, $matches)) {
            $result['errors'] = $matches[1];
        }

        return $result;
    }

    private function displaySummary(int $total, int $passed, int $failed): void
    {
        $executionTime = round(microtime(true) - $this->startTime, 2);
        
        $this->output->writeln('<info>Test Execution Summary</info>');
        $this->output->writeln(str_repeat('=', 50));

        $table = new Table($this->output);
        $table->setHeaders(['Metric', 'Value']);
        $table->addRows([
            ['Total Tests', $total],
            ['Passed', "<info>{$passed}</info>"],
            ['Failed', $failed > 0 ? "<error>{$failed}</error>" : "<info>{$failed}</info>"],
            ['Success Rate', $total > 0 ? round(($passed / $total) * 100, 1) . '%' : '0%'],
            ['Execution Time', "{$executionTime}s"],
        ]);
        $table->render();

        $this->output->writeln('');

        // Display category breakdown
        if (count($this->testResults) > 1) {
            $this->output->writeln('<info>Category Breakdown</info>');
            $categoryTable = new Table($this->output);
            $categoryTable->setHeaders(['Category', 'Total', 'Passed', 'Failed', 'Status']);
            
            foreach ($this->testResults as $category => $result) {
                $status = $result['failed'] === 0 ? '<info>✓ PASS</info>' : '<error>✗ FAIL</error>';
                $categoryTable->addRow([
                    $category,
                    $result['total'],
                    $result['passed'],
                    $result['failed'],
                    $status
                ]);
            }
            
            $categoryTable->render();
            $this->output->writeln('');
        }

        // Final status
        if ($failed === 0) {
            $this->output->writeln('<info>🎉 All tests passed successfully!</info>');
        } else {
            $this->output->writeln("<error>❌ {$failed} test(s) failed. Please review the output above.</error>");
        }
    }

    private function generateDetailedReport(): void
    {
        $this->output->writeln('<info>Detailed Test Report</info>');
        $this->output->writeln(str_repeat('-', 50));

        foreach ($this->testResults as $category => $result) {
            $this->output->writeln("<comment>{$category}:</comment>");
            
            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $this->output->writeln("  <error>• {$error}</error>");
                }
            } else {
                $this->output->writeln("  <info>• All tests passed</info>");
            }
            
            $this->output->writeln('');
        }
    }
}

// Command line interface
function showHelp(): void
{
    echo "Laravel AI Engine Test Runner\n";
    echo "Usage: php run-tests.php [options]\n\n";
    echo "Options:\n";
    echo "  --filter=<pattern>    Run only tests matching the pattern\n";
    echo "  --coverage           Generate code coverage report\n";
    echo "  --verbose            Show detailed output\n";
    echo "  --group=<group>      Run only specific test group (unit|feature)\n";
    echo "  --help               Show this help message\n\n";
    echo "Examples:\n";
    echo "  php run-tests.php                          # Run all tests\n";
    echo "  php run-tests.php --filter=CreditManager   # Run only CreditManager tests\n";
    echo "  php run-tests.php --group=unit --coverage  # Run unit tests with coverage\n";
    echo "  php run-tests.php --verbose                # Run with detailed output\n\n";
}

// Parse command line arguments
$options = [];
$args = array_slice($argv, 1);

foreach ($args as $arg) {
    if ($arg === '--help') {
        showHelp();
        exit(0);
    } elseif ($arg === '--coverage') {
        $options['coverage'] = true;
    } elseif ($arg === '--verbose') {
        $options['verbose'] = true;
    } elseif (strpos($arg, '--filter=') === 0) {
        $options['filter'] = substr($arg, 9);
    } elseif (strpos($arg, '--group=') === 0) {
        $options['group'] = substr($arg, 8);
    }
}

// Run tests
$runner = new TestRunner();
$exitCode = $runner->run($options);

exit($exitCode);
