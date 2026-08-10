<?php

use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource;

// What this package is allowed to be, asserted against its own source rather
// than against its behaviour. Every rule here is one a reviewer would otherwise
// have to hold in their head while reading a diff.

/**
 * Every PHP file this package ships.
 *
 * @return list<string>
 */
function sourceFiles(): array
{
    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** Every PHP file under `src/`, concatenated. */
function sourceOfSrc(): string
{
    return implode("\n", array_map(
        fn (string $path): string => (string) file_get_contents($path),
        sourceFiles(),
    ));
}

it('ships some source to check', function () {
    // So that every grep below is a statement about files rather than a
    // statement about an empty list.
    expect(sourceFiles())->not->toBeEmpty();
});

/**
 * The one commerce namespace this package is entitled to name.
 *
 * Written as "collect what is mentioned and check the set" rather than as a
 * search for a particular sibling, because a test that spells out a forbidden
 * name puts that name in the repository in order to look for it — and the grep
 * that enforces the boundary is a text search over `src/` that cannot tell a
 * docblock from an import.
 */
it('names its own commerce namespace and no other', function () {
    preg_match_all('/Liberu\\\\+Ecommerce\\\\+([A-Za-z]+)/', sourceOfSrc(), $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and(array_values(array_unique($matches[1])))->toBe(['Fulfillment']);
});

it('reaches the application namespace nowhere', function () {
    expect(sourceOfSrc())->not->toContain('use App\\')
        ->not->toContain('new App\\')
        ->not->toContain('extends App\\')
        ->not->toContain('implements App\\');
});

/**
 * No carrier name anywhere, because a carrier is a string the host chooses.
 *
 * The same shape the domain module uses, and for the same reason: a package that
 * knew these names would have to be released the day a merchant signs with
 * somebody else, and it would have an opinion about which of them are worth
 * naming. Matched on a word boundary rather than as a substring, so an innocent
 * substring inside a longer word is not a failure somebody deletes rather than
 * fixes.
 */
it('names no carrier in its source', function (string $carrier) {
    expect(preg_match('/\b'.preg_quote($carrier, '/').'\b/i', sourceOfSrc()))->toBe(0);
})->with([
    'UPS', 'FedEx', 'DHL', 'Royal Mail', 'USPS', 'Hermes', 'Evri', 'DPD',
    'Yodel', 'GLS', 'TNT', 'Australia Post', 'Canada Post', 'EasyPost',
    'Shippo', 'ShipStation', 'Sendcloud',
]);

/**
 * There is no editable counter field anywhere, and this is the assertion that
 * says so about the source rather than about one page.
 *
 * The three counters on a line of demand are not fillable in the domain, because
 * the arithmetic that keeps them honest lives with them. A form field would be a
 * second door — one that skips both invariants — and forms are filled from a
 * request array. `dispatched_quantity` is the sharpest case: nothing anywhere in
 * the domain lowers it, and a field here would be the only thing in the system
 * that could.
 *
 * So the whole package constructs exactly one input, and it is the cancellation
 * reason.
 */
it('constructs one form input in the whole package, and it is a closed vocabulary', function () {
    $imported = [];
    $named = [];

    foreach (sourceFiles() as $path) {
        $source = (string) file_get_contents($path);

        preg_match_all('/^use Filament\\\\Forms\\\\Components\\\\([A-Za-z]+);$/m', $source, $imports);
        $imported = [...$imported, ...$imports[1]];

        preg_match_all('/Select::make\(\'([a-z_]+)\'\)/', $source, $fields);
        $named = [...$named, ...$fields[1]];
    }

    expect($imported)->toBe(['Select'])
        ->and($named)->toBe(['reason'])
        // A select over a fixed list, so nothing a person types can reach the
        // log line the domain copies it into.
        ->and(ShipmentResource::CANCELLATION_REASONS)->not->toBeEmpty();
});

/**
 * This package writes nothing itself.
 *
 * Every change goes through a domain action — `TransitionShipment` is the only
 * one this package calls — which is where the transition table, the counter
 * arithmetic, the timestamp and the event all live. A `save()` or an `update()`
 * in a presentation package is a second write path that has none of them, and on
 * these tables it would be a second write path around two invariants.
 */
it('performs no write of its own anywhere in its source', function () {
    preg_match_all(
        '/->(save|update|updateOrCreate|forceFill|fill|delete|forceDelete|restore|insert|push|increment|decrement)\(/',
        sourceOfSrc(),
        $matches,
    );

    expect($matches[1])->toBe([]);
});

/**
 * And it calls exactly one domain action.
 *
 * Recording a parcel and releasing a line are both deliberately absent — the
 * first because its idempotency key belongs to its caller, the second because
 * neither policy publishes an ability for it. See `docs/domain.md`.
 */
it('calls one domain action and no other', function () {
    preg_match_all('/^use Liberu\\\\Ecommerce\\\\Fulfillment\\\\Actions\\\\([A-Za-z]+);$/m', sourceOfSrc(), $matches);

    expect(array_values(array_unique($matches[1])))->toBe(['TransitionShipment']);
});

it('registers no service provider through Composer', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    // Installation never implies boot. The module manager registers the provider
    // `module.json` names, and only when the deployment asks for it by name.
    expect($composer['extra']['laravel']['providers'] ?? [])->toBe([]);

    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true, flags: JSON_THROW_ON_ERROR);

    // The manifest's package list is the composer `require` list, filtered. The
    // boundary suite asserts the same thing; this is the one that fails in a diff
    // where somebody added a dependency and forgot the manifest.
    $required = array_keys(array_filter(
        $composer['require'],
        fn (string $package): bool => str_starts_with($package, 'liberusoftware/'),
        ARRAY_FILTER_USE_KEY,
    ));

    sort($required);

    $declared = array_keys($manifest['requires']['packages']);
    sort($declared);

    expect($declared)->toBe($required)
        ->and($composer['version'])->toBe($manifest['version'])
        ->and($composer['extra']['liberu']['name'])->toBe($manifest['name'])
        ->and($manifest['category'])->toBe('presentation')
        ->and($manifest['presentation']['filament']['admin'])->not->toBeEmpty();
});

it('names only classes that exist under its presentation key', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($manifest['presentation']['filament'] as $panel => $classes) {
        foreach ($classes as $class) {
            expect(class_exists($class))->toBeTrue($panel.': '.$class);
        }
    }

    expect(class_exists($manifest['provider']))->toBeTrue();
});

/**
 * The domain declares its own VCS repository here so that **this** package's CI
 * can resolve it, and that declaration does nothing for a consumer: Composer
 * honours `repositories` only from the root manifest. The host has to add the
 * same entry, which `docs/adoption.md` says.
 */
it('declares the domain package it presents, in both manifests and as a VCS repository', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    $urls = array_map(fn (array $repository): string => $repository['url'], $composer['repositories']);

    expect($composer['require'])->toHaveKey('liberusoftware/ecommerce-fulfillment')
        ->and($composer['require-dev'])->toHaveKey('liberusoftware/ecommerce-fulfillment')
        ->and($urls)->toBe(['https://github.com/liberusoftware/module-ecommerce-fulfillment']);
});
