<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Write;

use Gait\MobileCore\MobileResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Runs the explicit mobile delete lifecycle for resource and relation rows. */
final class RecordDeleter
{
    public static function delete(MobileResource $mobile, Model $record): void
    {
        DB::transaction(function () use ($mobile, $record): void {
            $mobile->getBeforeDelete()?->__invoke($record);

            $default = static fn (): ?bool => $record->delete();
            $using = $mobile->getDeleteUsing();
            $deleted = $using === null
                ? $default()
                : $using($record, $default);

            if ($deleted === false) {
                throw new DeleteRefusedException(
                    'The resource refused to delete this record.',
                );
            }

            $mobile->getAfterDelete()?->__invoke($record);
        });
    }
}
