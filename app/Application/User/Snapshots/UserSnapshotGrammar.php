<?php

namespace App\Application\User\Snapshots;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/** Query-local temporal relations; no temporary tables or business writes on GET. */
final class UserSnapshotGrammar extends PostgresGrammar
{
    public function __construct($connection, private readonly UserSnapshotReader $reader)
    {
        parent::__construct($connection);
    }

    public function compileSelect(Builder $query)
    {
        // wrapTable also quotes virtual subquery aliases. Validate the actual FROM
        // and JOIN sources here, so aliases stay legal and new live inputs fail closed.
        foreach ([$query->from, ...array_map(fn ($join) => $join->table, $query->joins ?? [])] as $table) {
            if (is_string($table) && ! isset(UserSnapshotSources::COLUMNS[preg_split('/\\s+as\\s+/i', $table)[0]])) {
                throw new \LogicException('Untracked user snapshot source. Add a forward capture migration first.');
            }
        }

        return parent::compileSelect($query);
    }

    protected function wrapSegments($segments)
    {
        // Column qualifiers must stay identifiers, even when they name a source table.
        return implode('.', array_map($this->wrapValue(...), $segments));
    }

    public function wrapTable($table, $prefix = null)
    {
        if (is_string($table)) {
            $parts = preg_split('/\s+as\s+/i', $table);
            if (isset(UserSnapshotSources::COLUMNS[$parts[0]])) {
                return '('.$this->reader->relation($parts[0]).') as '.$this->wrapValue($parts[1] ?? $parts[0]);
            }
        }

        return parent::wrapTable($table, $prefix);
    }
}
