<?php

namespace App\Modules\Srm\Suppliers\Import;

enum ProblemKind: string
{
    /** The row is left out until the file is fixed. */
    case Error = 'error';

    /** The row is left out because the supplier is already there. */
    case Skipped = 'skipped';

    /** The row is added, without the value the warning names. */
    case Warning = 'warning';
}
