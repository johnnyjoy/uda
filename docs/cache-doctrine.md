# UDA Cache Doctrine

Cache exists to reduce read load without changing caller behavior.

## Doctrine

Application code always calls `Database` the same way:

```php
$rows = $db->rows($sql, $params, ['users']);
```

Whether that read is served from cache is an internal Driver decision based on
configuration and cache metadata.

## Metadata Before Payload

Payload must not be fetched until metadata proves the entry is usable.

Required order:

1. read cache metadata
2. inspect referenced tables
3. compare table write timestamps
4. decide whether the payload may be used
5. fetch payload only after the decision

## Invalidation

Successful writes touch table mtimes:

```text
(connection name, table name) -> last write timestamp
```

A cached entry is stale when its creation time is older than any referenced
table mtime.

## Isolation

Cache keys include the connection name, SQL text, named parameters, namespace,
and cache format version. This keeps multiple same-backend connections isolated.

## Anti-Goals

Cache must not introduce:

* explicit cache calls in repositories
* scope objects
* alternate read paths
* SQL parsing for table detection
* cache-owned SQL execution

