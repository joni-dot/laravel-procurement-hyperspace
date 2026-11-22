<?php

namespace App\Modules\Srm\Suppliers\Import;

/**
 * Which column of the file holds what, read from its first row. Headers match whatever their case and
 * surrounding spaces.
 */
final readonly class ImportHeader
{
    /**
     * @param  array<int, ImportColumn>  $columns  position in the row => column
     * @param  list<string>  $ignored  headers that match no column
     */
    private function __construct(
        private array $columns,
        public array $ignored,
    ) {}

    /**
     * @param  array<int, string>  $cells
     *
     * @throws ImportFileRefused when a required column is missing
     */
    public static function read(array $cells): self
    {
        $known = [];

        foreach (ImportColumn::cases() as $column) {
            $known[mb_strtolower($column->value)] = $column;
        }

        $columns = [];
        $ignored = [];

        foreach ($cells as $position => $header) {
            $column = $known[mb_strtolower($header)] ?? null;

            if ($column !== null && ! in_array($column, $columns, true)) {
                $columns[$position] = $column;
            } elseif ($header !== '') {
                $ignored[] = $header;
            }
        }

        self::refuseMissingColumns($columns);

        return new self($columns, $ignored);
    }

    /**
     * @param  array<int, string>  $cells
     * @return array<string, string> column value => the cell, '' when the row has no such cell
     */
    public function values(array $cells): array
    {
        $values = [];

        foreach ($this->columns as $position => $column) {
            $values[$column->value] = $cells[$position] ?? '';
        }

        return $values;
    }

    /**
     * @param  array<int, ImportColumn>  $columns
     */
    private static function refuseMissingColumns(array $columns): void
    {
        $missing = array_filter(
            ImportColumn::cases(),
            fn (ImportColumn $column) => $column->isRequired() && ! in_array($column, $columns, true),
        );

        if ($missing !== []) {
            throw new ImportFileRefused(trans_choice('The file has no :columns column.|The file has no :columns columns.', count($missing), [
                'columns' => implode(', ', array_map(fn (ImportColumn $column) => '"'.$column->value.'"', $missing)),
            ]));
        }
    }
}
