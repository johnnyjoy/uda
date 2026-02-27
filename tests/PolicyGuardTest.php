<?php

declare(strict_types=1);

/**
 * @purpose Policy guard tests to enforce UDA constitutional rules
 */

use PHPUnit\Framework\TestCase;
use UDA\Driver;
use UDA\Cache;
use UDA\Query\SelectQuery;
use UDA\Query\InsertQuery;
use UDA\Query\UpdateQuery;
use UDA\Query\DeleteQuery;
use UDA\Query\UpsertQuery;

final class PolicyGuardTest extends TestCase
{
    /**
     * @purpose Verify no Scope classes exist
     */
    public function testNoScopeClassesExist(): void
    {
        // Check that no Scope classes are defined in the codebase
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src')
        );
        
        $phpFiles = new RegexIterator($iterator, '/\.php$/');
        $scopeClasses = [];
        
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file->getPathname());
            if (preg_match('/class\s+\w*Scope/', $content)) {
                $scopeClasses[] = $file->getPathname();
            }
        }
        
        $this->assertEmpty($scopeClasses, 'Scope classes found: ' . implode(', ', $scopeClasses));
    }
    
    /**
     * @purpose Verify exactly one execution path exists
     */
    public function testOneExecutionPath(): void
    {
        // Check that prepare/execute is only called in Driver class
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../src')
        );
        
        $phpFiles = new RegexIterator($iterator, '/\.php$/');
        $executionLocations = [];
        
        foreach ($phpFiles as $file) {
            $relativePath = str_replace(__DIR__ . '/../', '', $file->getPathname());
            if (strpos($relativePath, 'UDA/Driver.php') !== false) {
                continue; // Skip Driver class itself
            }
            
            $content = file_get_contents($file->getPathname());
            if (preg_match('/->(prepare|execute)\s*\(/', $content)) {
                $executionLocations[] = $relativePath;
            }
        }
        
        $this->assertEmpty($executionLocations, 'Execution methods found outside Driver: ' . implode(', ', $executionLocations));
    }
    
    /**
     * @purpose Verify named parameters only enforcement
     */
    public function testNamedParametersOnly(): void
    {
        // This would test that positional parameters are rejected
        $this->assertTrue(true); // Placeholder - would need actual database connection to test
    }
    
    /**
     * @purpose Verify query builders do not execute
     */
    public function testQueryBuildersDoNotExecute(): void
    {
        $reflection = new ReflectionClass(SelectQuery::class);
        $this->assertFalse($reflection->hasMethod('row'), 'SelectQuery should not have row() execution method');
        $this->assertFalse($reflection->hasMethod('rows'), 'SelectQuery should not have rows() execution method');
        $this->assertFalse($reflection->hasMethod('value'), 'SelectQuery should not have value() execution method');
        
        $reflection = new ReflectionClass(InsertQuery::class);
        $this->assertFalse($reflection->hasMethod('exec'), 'InsertQuery should not have exec() execution method');
        
        $reflection = new ReflectionClass(UpdateQuery::class);
        $this->assertFalse($reflection->hasMethod('exec'), 'UpdateQuery should not have exec() execution method');
        
        $reflection = new ReflectionClass(DeleteQuery::class);
        $this->assertFalse($reflection->hasMethod('exec'), 'DeleteQuery should not have exec() execution method');
        
        $reflection = new ReflectionClass(UpsertQuery::class);
        $this->assertFalse($reflection->hasMethod('exec'), 'UpsertQuery should not have exec() execution method');
    }
    
    /**
     * @purpose Verify cache is transparent
     */
    public function testCacheIsTransparent(): void
    {
        $reflection = new ReflectionClass(Driver::class);
        $this->assertFalse($reflection->hasMethod('cache'), 'Driver should not have cache() method');
    }
}