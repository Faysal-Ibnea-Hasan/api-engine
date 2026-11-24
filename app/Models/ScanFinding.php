<?php

namespace App\Models;

use Database\Factories\ScanFindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $route_scan_id
 * @property string $severity
 * @property string $code
 * @property string $message
 * @property string|null $source_path
 * @property int|null $source_line
 * @property string|null $expression
 */
class ScanFinding extends Model
{
    /** @use HasFactory<ScanFindingFactory> */
    use HasFactory;

    public $timestamps = false;
}
