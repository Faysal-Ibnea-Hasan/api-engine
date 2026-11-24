<?php

namespace App\Domain\Sources;

use RuntimeException;

/** Thrown at an import checkpoint after someone asked the run to stop. */
final class ImportCancelled extends RuntimeException {}
