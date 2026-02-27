# Universal Data Abstraction Usage Guide

## Overview

This system provides a unified interface for working with different data sources while maintaining type safety and extensibility. It works similarly to `getpwent()` - the underlying source is abstracted, but the system still requires configuration to know where to get the data from.

## Getting Started

### Basic Usage

```php
use UniversalDataAbstraction\Database;

// Create database connection using a specific driver
$driver = new MySQLDriver($pdoConnection);
$db = new Database($driver);

// Simple SELECT query
$users = $db->query()
    ->select(['id', 'name', 'email'])
    ->from('users')
    ->where('active', '=', 1)
    ->orderBy('name')
    ->get();

// Insert data
$db->query()->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);

// Update data
$db->query()
    ->from('users')
    ->where('id', '=', 1)
    ->update([
        'name' => 'Jane Doe'
    ]);

// Delete data
$db->query()
    ->from('users')
    ->where('id', '=', 1)
    ->delete();

// Raw SQL execution
$result = $db->execute('SELECT * FROM users WHERE id = ?', [1]);
```

## Driver-Specific Usage

### SQL Drivers (MySQL, PostgreSQL, SQLite, MSSQL, Oracle)

These drivers work with PDO connections and support standard SQL operations:

```php
use UniversalDataAbstraction\Driver\MySQLDriver;
use UniversalDataAbstraction\Database;

$pdo = new PDO('mysql:host=localhost;dbname=test', $username, $password);
$driver = new MySQLDriver($pdo);
$db = new Database($driver);
```

### Non-SQL Drivers

For non-SQL databases, you can use the specific drivers directly:

```php
use UniversalDataAbstraction\Driver\MongoDBDriver;
use UniversalDataAbstraction\Driver\RedisDriver;
use UniversalDataAbstraction\Driver\ElasticsearchDriver;

// MongoDB usage
$mongo = new MongoDB\Client('mongodb://localhost:27017');
$mongoDriver = new MongoDBDriver($mongo);
// Use specific methods like: $mongoDriver->find(), $mongoDriver->insert(), etc.

// Redis usage
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redisDriver = new RedisDriver($redis);
// Use specific methods like: $redisDriver->get(), $redisDriver->set(), etc.

// Elasticsearch usage
$client = new Elasticsearch\Client(['hosts' => ['localhost:9200']]);
$esDriver = new ElasticsearchDriver($client);
// Use specific methods like: $esDriver->search(), $esDriver->index(), etc.
```

## Transactions

All SQL drivers support transactions:

```php
$db->beginTransaction();
try {
    $db->query()->insert(['name' => 'John']);
    $db->query()->update(['name' => 'Jane']);
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
}
```

## Result Sets

Results are returned as ResultSet objects with type-safe methods:

```php
$result = $db->query()->select('*')->from('users')->get();

// Get all results
$allUsers = $result->all();

// Get first result
$firstUser = $result->first();

// Get a single column value
$firstUserName = $result->value('name');

// Get count
$count = $result->count();

// Check if empty
if ($result->isEmpty()) {
    // Handle empty result
}
```

## Extensibility

The system is designed to be easily extensible:

1. **Custom Drivers**: Implement `DriverInterface` for new database types
2. **Custom Query Builder**: Extend `QueryBuilder` class for additional methods
3. **Custom Result Set**: Extend `ResultSet` class for specialized result handling

## Error Handling

The system includes comprehensive exception handling:

```php
use UniversalDataAbstraction\Exceptions\DatabaseException;
use UniversalDataAbstraction\Exceptions\ConnectionException;

try {
    $db = new Database($driver);
    // ... operations
} catch (DatabaseException $e) {
    // Handle database-specific errors
} catch (ConnectionException $e) {
    // Handle connection errors
}
```

## Configuration

The system can be configured through the constructor parameters:

```php
$db = new Database(
    'mysql:host=localhost;dbname=test',
    'username',
    'password',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);
```

## Performance Considerations

- Uses prepared statements for all queries
- Lazy loading of results
- Efficient parameter binding
- Connection pooling support
- `ConfigLoader::load()` caches each JSON configuration path so repeated `Database::connect()` calls stay fast; call `ConfigLoader::clearCache($path)` whenever the file changes and you need the next connection to see the update.
- Transparent caching: when a connection defines a cache block, the default `row` / `rows` helpers (including `select()->rows()`) honor that cache policy automatically; use `Driver::cache()` only for per-call overrides.
