<?php

use App\Support\Districts;

it('knows the 64 districts', function () {
    expect(Districts::all())->toHaveCount(64);
    expect(Districts::all())->toContain('Dhaka', 'Chattogram', "Cox's Bazar", 'Sylhet');
});

it('finds a district in any letter case', function (string $input, string $expected) {
    expect(Districts::find($input))->toBe($expected);
})->with([
    ['dhaka', 'Dhaka'],
    ['DHAKA', 'Dhaka'],
    ['  Dhaka ', 'Dhaka'],
    ["cox's bazar", "Cox's Bazar"],
    ['coxs bazar', "Cox's Bazar"],
    ['cox bazar', "Cox's Bazar"],
]);

it('finds a district by another spelling', function (string $input, string $expected) {
    expect(Districts::find($input))->toBe($expected);
})->with([
    ['Chittagong', 'Chattogram'],
    ['Comilla', 'Cumilla'],
    ['Barisal', 'Barishal'],
    ['Jessore', 'Jashore'],
    ['Bogra', 'Bogura'],
]);

it('returns null for a name that is not a district', function (string $input) {
    expect(Districts::find($input))->toBeNull();
})->with(['Atlantis', '', '12345', 'Dhaka City']);
