<?php

declare(strict_types=1);

/** @purpose Compliance tests - enforce spec.md sections 4.1, 4.3, 4.5, 6 */

namespace UniversalDataAbstraction\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SpecComplianceTest extends TestCase
{
    private const SRC_DIR = __DIR__ . '/../src/UDA';
    
    /**
     * @testgroup compliance
     * @test
     */
    public function testNoConnectionDomain(): void
    {
        $this->assertFileDoesNotExist(
            self::SRC_DIR . '/Core/Connection.php',
            'spec 4.5: Connection domain must not exist'
        );
        
        $this->assertFileDoesNotExist(
            self::SRC_DIR . '/Core/ConnectionManager.php',
            'spec 4.5: ConnectionManager must not exist'
        );
    }
    
    /**
     * @testgroup compliance
     * @test
     */
    public function testQueryDomainDoesNotExecute(): void
    {
        $queryFiles = glob(self::SRC_DIR . '/Query/*Query.php');
        
        foreach ($queryFiles as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);
            
            // Check for execution methods
            $forbidden = ['->driver->', '->pdo->', 'prepare(', 'execute(', 'rowCount()'];
            
            foreach ($forbidden as $pattern) {
                $this->assertDoesNotMatch(
                    '/' . preg_quote($pattern, '/') . '/',
                    $content,
                    "spec 4.1: Query '$basename' must not contain '$pattern'"
                );
            }
            
            // Must have toSql method
            $this->assertMatches(
                '/public function toSql\(\): Sql/',
                $content,
                "spec 4.1: Query '$basename' must have toSql(): Sql"
            );
        }
    }
    
    /**
     * @testgroup compliance
     * @test
     */
    public function testSingleExecutionHotPath(): void
    {
        $driverContent = file_get_contents(self::SRC_DIR . '/Driver.php');
        
        // Count occurrences of $this->pdo->prepare
        preg_match_all('/\$this->pdo->prepare/', $driverContent, $matches);
        $prepareCount = count($matches[0]);
        
        $this->assertEquals(
            1,
            $prepareCount,
            "spec 4.3: Exactly one prepare call allowed in Driver (found $prepareCount)"
        );
        
        // Must be in executeInternal
        $this->assertMatches(
            '/protected function executeInternal/',
            $driverContent,
            'spec 4.3: prepare must be in executeInternal'
        );
    }
    
    /**
     * @testgroup compliance
     * @test
     */
    public function testNoNamespaceRepetition(): void
    {
        $allFiles = $this->getAllPhpFiles(self::SRC_DIR);
        
        foreach ($allFiles as $file) {
            $content = file_get_contents($file);
            $path = str_replace(self::SRC_DIR . '/', '', $file);
            
            // Check for class names that repeat namespace token
            // e.g., Config\Config, Query\Query, Cache\Cache
            if (preg_match('/namespace (UDA\\\w+);.*?class (\w+)/s', $content, $matches)) {
                $namespace = $matches[1];
                $class = $matches[2];
                
                // Extract last namespace token
                $parts = explode('\\', $namespace);
                $lastToken = end($parts);
                
                $this->assertNotEquals(
                    $lastToken,
                    $class,
                    "spec 4.3: No repeated tokens in path '$namespace\\$class' in $path"
                );
            }
        }
    }
    
    /**
     * @testgroup compliance
     * @test
     */
    public function testAllFilesHavePurposeHeader(): void
    {
        $allFiles = $this->getAllPhpFiles(self::SRC_DIR);
        
        foreach ($allFiles as $file) {
            $content = file_get_contents($file);
            $path = str_replace(self::SRC_DIR . '/', '', $file);
            
            $this->assertMatches(
                '/@purpose/',
                $content,
                "spec 6: File must have @purpose header: $path"
            );
        }
    }
    
    /**
     * @testgroup compliance
     * @test
     */
    public function testDomainRootControllerPattern(): void
    {
        // Check that domain roots exist as controllers
        $domains = ['Config', 'Driver', 'Query'];
        
        foreach ($domains as $domain) {
            $controllerPath = self::SRC_DIR . '/' . $domain . '.php';
            $this->assertFileExists(
                $controllerPath,
                "Domain '$domain' must have root controller"
            );
            
            // Must not be a facade that delegates everything
            $content = file_get_contents($controllerPath);
            
            // Must have real implementation (not just pass-through)
            $this->assertGreaterThan(
                50,
                substr_count($content, "\n"),
                "Domain controller '$domain.php' must have substantial implementation"
            );
        }
    }
    
    private function getAllPhpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    private function assertMatches(string $pattern, string $string, string $message = ''): void
    {
        $this->assertTrue(
            (bool) preg_match($pattern, $string),
            $message ?: "Pattern '$pattern' should match string"
        );
    }
    
    private function assertDoesNotMatch(string $pattern, string $string, string $message = ''): void
    {
        $this->assertFalse(
            (bool) preg_match($pattern, $string),
            $message ?: "Pattern '$pattern' should not match string"
        );
    }
}
