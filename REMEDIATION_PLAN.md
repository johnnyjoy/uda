# UDA Remediation Plan - Achieving Spec Compliance

## **AUDIT RESULT: 7 CRITICAL SPEC VIOLATIONS**

### **Violation 1: Core/Connection.php EXISTS** ❌
- **Spec violation**: Section 4.5 - "No Connection domain"
- **Design violation**: "Connection is data only"
- **Impact**: HIGH - Wrong architecture plane
- **Action**: DELETE `src/UDA/Core/Connection.php` and all references

### **Violation 2: No @purpose File Headers** ❌
- **Spec violation**: Section 6 - "File Purpose Contract"
- **Impact**: MEDIUM - Code maintainability, onboarding
- **Action**: Add `@purpose` docblock to ALL 36 PHP files

### **Violation 3: Mixed UBA Namespace** ❌
- **Spec requirement**: All code under `UDA\` only
- **Found**: Roam shows UBA\Driver namespace mixed in
- **Impact**: HIGH - Inconsistent domain boundaries
- **Action**: Search/replace ALL UBA → UDA

### **Violation 4: Driver.php God Class (370 lines)** ⚠️
- **Spec requirement**: Lean design, "Stupid-Simple"
- **Found**: Driver contains query building, caching, SQL helpers
- **Impact**: MEDIUM - Reduced maintainability, not lean
- **Action**: Extract SQL helpers to `Driver\SqlHelper`, cache integration to `Driver\CacheBridge`

### **Violation 5: Query Classes Execute SQL** ❌
- **Spec violation**: Section 4.1 - "Builders build only"
- **Found**: `InsertQuery::executeReturning()` executes queries
- **Impact**: HIGH - Multiple execution paths
- **Action**: Remove ALL execution from Query classes; return `Sql` value objects only

### **Violation 6: Exceptions in Wrong Namespace** ⚠️
- **Design violation**: `src/SQL/*Exception.php` should be `UDA\Exception\*`
- **Impact**: LOW - Consistency, discoverability
- **Action**: Move exceptions to correct domain

### **Violation 7: Missing Domain Facades** ⚠️
- **Spec requirement**: `UDA\Config.php`, `UDA\Query.php` facades
- **Found**: Not present
- **Impact**: MEDIUM - Public API inconsistency
- **Action**: Create facade classes as spec'd

---

## **COMPREHENSIVE REMEDIATION PLAN**

### **Phase 1: Foundation (Week 1)**

**Goals**: Fix critical violations, establish correct boundaries

#### **Step 1.1: Nuke Connection Domain**
```bash
# Delete forbidden files
rm src/UDA/Core/Connection.php
rm src/UDA/Core/ConnectionManager.php

# Remove all imports/references
grep -r "use.*Core.*Connection" src/ --include="*.php" | cut -d: -f1 | sort -u
# Edit each file to remove Connection dependencies
```

#### **Step 1.2: Universal UDA Namespace**
```bash
# Find and replace ALL UBA → UDA
find src/ -type f -name "*.php" -exec sed -i 's/namespace UBA/namespace UDA/g' {} \;
find src/ -type f -name "*.php" -exec sed -i 's/use UBA\\;/use UDA\\;/g' {} \;
find src/ -type f -name "*.php" -exec sed -i 's/UBA\\Driver/UDA\\Driver/g' {} \;

# Rename directory structure
find src -type d -name "UBA" -exec sh -c 'mv "$0" "${0/UBA/UDA}"' {} \;
```

#### **Step 1.3: Add @purpose Headers**
Create script to auto-add headers:
```php
#!/usr/bin/env php
<?php
foreach (glob('src/**/*.php') as $file) {
    $content = file_get_contents($file);
    if (strpos($content, '@purpose') !== false) continue;
    
    // Extract class name and create purpose from namespace
    if (preg_match('/namespace (UDA\\.+?);/', $content, $nsMatch) &&
        preg_match('/(class|interface|trait) (\w+)/', $content, $classMatch)) {
        $purpose = "/** @purpose {$nsMatch[1]}\\{$classMatch[2]}: Add one-sentence purpose here */";
        $content = preg_replace('/(<\?php\s+declare\(strict_types=1\);)/', 
            "$1\n\n{$purpose}", $content);
        file_put_contents($file, $content);
    }
}
```

### **Phase 2: Query Domain Purity (Week 2)**

**Goals**: Separate building from execution

#### **Step 2.1: Create Sql Value Object**
```php
// src/UDA/Query/Sql.php
namespace UDA\Query;

final class Sql
{
    public function __construct(
        public readonly string $sql,
        public readonly array $params
    ) {}
}
```

#### **Step 2.2: Refactor Query Builders**
Current:
```php
// WRONG - executes
class SelectQuery extends AbstractQuery {
    public function rows(): array { /* executes */ }
}
```

Target:
```php
// CORRECT - returns Sql
class SelectQuery extends AbstractQuery {
    public function toSql(): Sql { /* builds only */ }
}
```

**Replace ALL Query classes**:
- Remove PDO references
- Remove execution methods
- Add `toSql(): Sql` method
- Driver calls `toSql()` and then executes

### **Phase 3: Driver Leanification (Week 2-3)**

**Goals**: Extract responsibilities, keep Driver lean

#### **Step 3.1: Extract SQL Helpers**
```php
// src/UDA/Driver/SqlHelper.php
namespace UDA\Driver;

final class SqlHelper
{
    public static function limitOffset(int $limit, int $offset): string;
    public static function orderByAllowed(string $col, array $allowlist, string $dir): string;
    public static function inList(array $values, string $hint): string;
}
```

#### **Step 3.2: Extract Cache Bridge**
```php
// src/UDA/Driver/CacheBridge.php
namespace UDA\Driver;

final class CacheBridge
{
    public function readThrough(string $method, Sql $sql, array $hintTables): mixed;
    public function touchTables(array $tables): void;
}
```

#### **Step 3.3: Slim Driver to ~150 lines**
Keep in Driver:
- Constructor and properties
- `executeInternal()` (the ONE hot path)
- `transaction()` (orchestration)
- Public API methods (thin wrappers)

Move OUT:
- `q()`, `orderByAllowed()`, `limitOffset()`, `inList()` → `SqlHelper`
- Cache integration logic → `CacheBridge`
- Query builder instantiation → `Database.php` or separate factory

### **Phase 4: Domain Facades & Validation (Week 3)**

#### **Step 4.1: Create Config Facade**
```php
// src/UDA/Config.php
namespace UDA;

use UDA\Config\Loader;
use UDA\Config\Validator;
use UDA\Config\Snapshot;

final class Config
{
    private static ?Snapshot $snapshot = null;
    
    public static function load(string $path): Snapshot;
    public static function loadFromEnv(): Snapshot;
    public static function clearCache(): void;
}
```

#### **Step 4.2: Create Query Facade**
```php
// src/UDA/Query.php
namespace UDA;

use UDA\Query\Sql;

final class Query
{
    public static function select(): SelectQuery;
    public static function insert(): InsertQuery;
    public static function update(): UpdateQuery;
    public static function delete(): DeleteQuery;
    public static function upsert(): UpsertQuery;
    
    // Helper to create Sql from string + params
    public static function raw(string $sql, array $params = []): Sql;
}
```

#### **Step 4.3: Implement Config Validation**
```php
// src/UDA/Config/Validator.php
namespace UDA\Config;

final class Validator
{
    public function validate(array $config): array;
    public function validateConnection(string $name, array $connection): array;
}
```

### **Phase 5: Testing & Compliance (Week 3-4)**

**Goals**: Achieve spec compliance, all tests pass

#### **Step 5.1: Create Violation Test Suite**
```php
// tests/SpecComplianceTest.php

class SpecComplianceTest {
    public function testNoConnectionDomain() {
        $this->assertFileNotExists('src/UDA/Core/Connection.php');
    }
    
    public function testNoEngineBranchingOutsideDriver() {
        // Grep for driver name checks outside src/UDA/Driver/
    }
    
    public function testSingleExecutionPath() {
        // Count prepare/execute implementations - should be exactly 1
    }
    
    public function testNoNamespaceRepetition() {
        // Static scan: no Config\Config, Query\Query, etc
    }
}
```

#### **Step 5.2: SQLite Regression Tests**
Mandatory per spec 18:
- Config load/validation
- DSN building
- Named params (`?` rejected)
- Injection attempts fail
- Empty IN → `1=0`
- `each()` streaming
- Nested transactions
- Upsert
- Schema basics
- Caching key + TTL

#### **Step 5.3: Update All Tests**
- Remove UBA references
- Update to use `Driver->toSql()` + execution pattern
- Add spec compliance tests

### **Phase 6: Driver Expansion (Week 4)**

**Goals**: Support all PDO drivers

#### **Step 6.1: Complete Driver Implementations**
Current: Only GenericDriver, SQLiteDriver (shells)
Need per spec:
- `MySQLDriver`
- `PostgresDriver`
- `SQLServerDriver`
- `OracleDriver`
- `DblibDriver` (for MSSQL via FreeTDS)

#### **Step 6.2: DSN Builders**
Each driver needs:
```php
class PostgresDriver extends Driver {
    protected function buildDsn(array $params): string {
        // Build PostgreSQL DSN from params
    }
    
    protected function getDialect(): Dialect {
        // Return Postgres-specific dialect
    }
}
```

### **Phase 7: Performance & Documentation (Week 4)**

#### **Step 7.1: Add @purpose to ALL Files**
```bash
find src -name "*.php" -exec ./add-purpose.php {} \;
```

#### **Step 7.2: Update Specification Docs**
Update `spec.md`, `design.md` with actual implementation details

#### **Step 7.3: Performance Benchmarks**
- Benchmark vs plain PDO
- Benchmark with/without caching
- Memory usage profiling

---

## **SUCCESS CRITERIA**

1. ✅ **No Connection domain** - `src/UDA/Core/Connection.php` deleted
2. ✅ **All @purpose headers** present - 36/36 files
3. ✅ **UDA namespace only** - 0 references to UBA
4. ✅ **Driver is lean** - < 200 lines
5. ✅ **Query builders pure** - `toSql()` only, no execution
6. ✅ **Single execution path** - Only `executeInternal()` touches PDO
7. ✅ **No namespace repetition** - `Config\Config`, `Query\Query` don't exist
8. ✅ **All spec compliance tests pass**
9. ✅ **SQLite regression tests pass**
10. ✅ **All 6 PDO drivers implemented**

---

## **IMPLEMENTATION ORDER (TDD Style)**

**Week 1**: Critical violations (1-3)
1. Delete Connection.php
2. Fix UBA → UDA
3. Add @purpose headers
4. Run tests - all should fail initially

**Week 2**: Query purity (2, 5)
1. Create `Query\Sql` value object
2. Refactor all Query builders to `toSql()`
3. Update Driver to accept `Sql`
4. Tests should start passing

**Week 3**: Lean Driver & validation (4, 6, 7)
1. Extract SqlHelper, CacheBridge
2. Create Config/Query facades
3. Implement validation
4. Add spec compliance tests

**Week 4**: Completion (6, 8-10)
1. Complete all 6 PDO drivers
2. Implement DSN builders
3. Performance benchmarks
4. Final documentation sweep

**Total estimated effort: 4 weeks, ~160 hours**
