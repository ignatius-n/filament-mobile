<?php

declare(strict_types=1);

use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Write\DeleteRefusedException;
use Gait\FilamentMobile\Write\RecordDeleter;

it('rolls the lifecycle back when deleteUsing refuses', function () {
    $banner = seedBanner();
    $company = $banner->company;

    $mobile = MobileResource::make()
        ->beforeDelete(fn () => $company->update(['name' => 'must roll back']))
        ->deleteUsing(fn () => false)
        ->afterDelete(fn () => $company->update(['name' => 'must not run']));

    expect(fn () => RecordDeleter::delete($mobile, $banner))
        ->toThrow(DeleteRefusedException::class);

    expect($banner->fresh())->not->toBeNull()
        ->and($company->fresh()->name)->toBe('Acme');
});
