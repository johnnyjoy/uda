<?php

declare(strict_types=1);

namespace Tests\Query;

use UDA\Query\Dialect\Db2;
use UDA\Query\Dialect\DeleteState;
use UDA\Query\Dialect\Dialect;
use UDA\Query\Dialect\InsertState;
use UDA\Query\Dialect\MariaDb;
use UDA\Query\Dialect\Oracle;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Dialect\SQLite;
use UDA\Query\Dialect\SelectState;
use UDA\Query\Dialect\SqlServer;
use UDA\Query\Dialect\Sybase;
use UDA\Query\Dialect\UpdateState;
use UDA\Query\Dialect\UpsertState;
use UDA\Query\Sql;

final class CountingDialect extends Dialect
{
    private int $selectCompiles = 0;
    private int $insertCompiles = 0;
    private int $updateCompiles = 0;
    private int $deleteCompiles = 0;
    private int $upsertCompiles = 0;

    public function __construct(private readonly Dialect $inner, private ?string $overrideName = null)
    {
    }

    public static function postgres(): self
    {
        return new self(new PostgreSql());
    }

    public static function sqlite(): self
    {
        return new self(new SQLite());
    }

    public static function mariadb(): self
    {
        return new self(new MariaDb());
    }

    public static function sqlServer(): self
    {
        return new self(new SqlServer());
    }

    public static function sybase(): self
    {
        return new self(new Sybase());
    }

    public static function oracle(): self
    {
        return new self(new Oracle());
    }

    public static function db2(): self
    {
        return new self(new Db2());
    }

    public function name(): string
    {
        return $this->overrideName ?? $this->inner->name();
    }

    public function selectCompileCount(): int
    {
        return $this->selectCompiles;
    }

    public function insertCompileCount(): int
    {
        return $this->insertCompiles;
    }

    public function updateCompileCount(): int
    {
        return $this->updateCompiles;
    }

    public function deleteCompileCount(): int
    {
        return $this->deleteCompiles;
    }

    public function upsertCompileCount(): int
    {
        return $this->upsertCompiles;
    }

    public function compileSelect(SelectState $state): Sql
    {
        $this->selectCompiles++;

        return $this->inner->compileSelect($state);
    }

    public function compileInsert(InsertState $state): Sql
    {
        $this->insertCompiles++;

        return $this->inner->compileInsert($state);
    }

    public function compileUpdate(UpdateState $state): Sql
    {
        $this->updateCompiles++;

        return $this->inner->compileUpdate($state);
    }

    public function compileDelete(DeleteState $state): Sql
    {
        $this->deleteCompiles++;

        return $this->inner->compileDelete($state);
    }

    public function compileUpsert(UpsertState $state): Sql
    {
        $this->upsertCompiles++;

        return $this->inner->compileUpsert($state);
    }

    public function supportsReturning(): bool
    {
        return $this->inner->supportsReturning();
    }

    public function supportsMerge(): bool
    {
        return $this->inner->supportsMerge();
    }

    public function supportsUpsert(): bool
    {
        return $this->inner->supportsUpsert();
    }

    public function supportsCte(): bool
    {
        return $this->inner->supportsCte();
    }

    public function supportsRecursiveCte(): bool
    {
        return $this->inner->supportsRecursiveCte();
    }

    public function supportsWritableCte(): bool
    {
        return $this->inner->supportsWritableCte();
    }

    public function supportsRecursiveWritableCte(): bool
    {
        return $this->inner->supportsRecursiveWritableCte();
    }

    public function supportsCteMaterializationHints(): bool
    {
        return $this->inner->supportsCteMaterializationHints();
    }

    public function supportsIntersect(): bool
    {
        return $this->inner->supportsIntersect();
    }

    public function supportsExcept(): bool
    {
        return $this->inner->supportsExcept();
    }

    public function supportsWindowFunctions(): bool
    {
        return $this->inner->supportsWindowFunctions();
    }
}
