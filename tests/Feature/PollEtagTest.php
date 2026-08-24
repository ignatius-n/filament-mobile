<?php

declare(strict_types=1);

it('makes list, detail, and dashboard reads conditionally cacheable', function (string $path) {
    $banner = seedBanner('ETag');
    $path = str_replace('{id}', (string) $banner->id, $path);
    $user = makeUser('admin');

    $first = $this->actingAs($user)->getJson($path)->assertOk();
    $etag = $first->headers->get('ETag');

    expect($etag)->toBeString()->not->toBeEmpty();

    $second = $this->actingAs($user)
        ->withHeaders(['If-None-Match' => $etag])
        ->getJson($path);

    expect($second->getStatusCode())->toBe(304)
        ->and($second->getContent())->toBe('')
        ->and($second->headers->get('ETag'))->toBe($etag);
})->with([
    '/api/mobile-panel/banners',
    '/api/mobile-panel/banners/{id}',
    '/api/mobile-panel/dashboard',
]);

it('changes the list validator when a visible record changes', function () {
    $banner = seedBanner('Before');
    $user = makeUser('admin');
    $before = $this->actingAs($user)
        ->getJson('/api/mobile-panel/banners')
        ->headers->get('ETag');

    $banner->update(['name' => 'After']);

    $this->actingAs($user)
        ->withHeaders(['If-None-Match' => $before])
        ->getJson('/api/mobile-panel/banners')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'After');
});
