<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P24 Task 3: the fixture the filter ENDPOINT tests query against — a model
 * that genuinely soft-deletes.
 *
 * The existing `Slide` fixture cannot stand in: `TableFiltersResource` puts a
 * `TrashedFilter` on it, and `TrashedFilter`'s own queries call
 * `withTrashed()`/`onlyTrashed()`, which are a `BadMethodCallException` on a
 * model without this trait (Task 2 hit exactly that, and
 * `filament-mobile:doctor` now reports it). `Banner` cannot either: its table
 * has a `deleted_at` column but the model deliberately refuses the trait.
 */
class Story extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'featured' => 'boolean',
    ];
}
