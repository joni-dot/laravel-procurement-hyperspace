<?php

namespace App\Modules\Srm\Suppliers\Import;

use RuntimeException;

/**
 * The whole file can't be imported. The message is shown to the importer, so it is already translated
 * and never includes anything read from the file except a column name.
 */
final class ImportFileRefused extends RuntimeException {}
