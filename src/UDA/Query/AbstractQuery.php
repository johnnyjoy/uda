<?php

declare(strict_types=1);

/** @purpose Base query builder - provides quoting and parameter utilities */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\SQL\Identifier;
use UDA\SQL\ParamBag;
use UDA\SQL\SqlMessage;
use UDA\SQL\Value;

use UDA\SQL\Sql;

abstract class AbstractQuery
{
    /**
     * @purpose Ensure concrete query builders implement toSql()
     */
    abstract public function toSql(): Sql;

    /** @var ?\UDA\Driver Driver instance for compatibility */
    public ?\UDA\Driver $driverInstance = null;
    /** @var string Driver name for quoting */
    public string $driverName = '';

        protected ParamBag $params;

    public function __construct()
{$this->params = new ParamBag();}
    protected function param(mixed $value): string
    {
        return Value::param($this->params, $value);
    }

    protected function quote(string $identifier): string
    {
        try {
            // Use stored driver name instead of accessing driver directly (spec compliance)
            return (new Identifier($identifier))->quoted($this->driverName);
        } catch (\Throwable $ex) {
            throw new QueryException('Invalid identifier: ' . $identifier, 0, $ex);
        }
    }

    protected function buildSql(string $query): SqlMessage
    {
        return new SqlMessage($query, $this->params->getParams());
    }
}
