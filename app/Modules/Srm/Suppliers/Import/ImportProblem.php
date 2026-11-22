<?php

namespace App\Modules\Srm\Suppliers\Import;

final readonly class ImportProblem
{
    public function __construct(
        public int $row,
        public ProblemKind $kind,
        public ?ImportColumn $column,
        public string $message,
    ) {}

    /**
     * @return array{row: int, kind: string, column: string|null, message: string}
     */
    public function toArray(): array
    {
        return [
            'row' => $this->row,
            'kind' => $this->kind->value,
            'column' => $this->column?->label(),
            'message' => $this->message,
        ];
    }
}
