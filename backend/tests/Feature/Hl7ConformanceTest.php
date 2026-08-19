<?php

use App\Support\Hl7\AdtA01Mapper;
use App\Support\Hl7\Hl7Message;
use App\Support\Hl7\OruR01Parser;
use Illuminate\Support\Facades\File;

/**
 * HL7 v2 conformance test (INTEROPERABILITY.md §5, MASTER_RULES.md §32.5).
 *
 * Every documented HL7 message pattern is tested against its fixture.
 * The parser/mapper is a pure function: raw HL7 → validated internal shape.
 */
it('parses ADT^A01 admit message from fixture', function () {
    $raw = File::get(base_path('tests/Fixtures/hl7/adt_a01_basic.hl7'));
    $message = Hl7Message::fromString($raw);

    $mapper = new AdtA01Mapper;
    $mapped = $mapper->map($message);

    expect($mapped['messageType'])->toBe('ADT');
    expect($mapped['messageTrigger'])->toBe('A01');
    expect($mapped['patient'])->toHaveKeys(['mrn', 'familyName', 'givenName']);
    expect($mapped['patient']['mrn'])->not->toBeEmpty();
    expect($mapped['patient']['familyName'])->not->toBeEmpty();
    expect($mapped['patient']['givenName'])->not->toBeEmpty();
});

it('parses ORU^R01 lab result from fixture', function () {
    $raw = File::get(base_path('tests/Fixtures/hl7/oru_r01_basic.hl7'));
    $message = Hl7Message::fromString($raw);

    $parser = new OruR01Parser;
    $parsed = $parser->parse($message);

    expect($parsed['messageType'])->toBe('ORU');
    expect($parsed['messageTrigger'])->toBe('R01');
    expect($parsed['patient'])->toHaveKeys(['mrn', 'familyName', 'givenName']);
    expect($parsed['orders'])->toBeArray();
    expect(count($parsed['orders']))->toBeGreaterThan(0);

    // Each order should have observations
    foreach ($parsed['orders'] as $order) {
        expect($order)->toHaveKey('observations');
        expect($order['observations'])->toBeArray();
    }
});

it('parses ORU^R01 with critical values from fixture', function () {
    $raw = File::get(base_path('tests/Fixtures/hl7/oru_r01_critical.hl7'));
    $message = Hl7Message::fromString($raw);

    $parser = new OruR01Parser;
    $parsed = $parser->parse($message);

    expect($parsed['orders'])->toBeArray();

    // At least one observation should have a critical abnormal flag
    $hasCritical = false;
    foreach ($parsed['orders'] as $order) {
        foreach ($order['observations'] as $obs) {
            if (in_array('HH', $obs['abnormalFlags'] ?? [], true)) {
                $hasCritical = true;
                break 2;
            }
        }
    }

    expect($hasCritical)->toBeTrue('Fixture should contain at least one critical (HH) value');
});

it('parses ORU^R01 with multiple orders from fixture', function () {
    $raw = File::get(base_path('tests/Fixtures/hl7/oru_r01_multiple_orders.hl7'));
    $message = Hl7Message::fromString($raw);

    $parser = new OruR01Parser;
    $parsed = $parser->parse($message);

    expect($parsed['orders'])->toBeArray();
    expect(count($parsed['orders']))->toBeGreaterThanOrEqual(2);
});

it('HL7 message rejects invalid message (no MSH segment)', function () {
    $invalid = 'OBX|1|NM|HGB||12.5|g/dL|12.0-16.0|||F|||20260115';

    try {
        Hl7Message::fromString($invalid);
        $this->fail('Should have thrown exception for invalid HL7 message');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('MSH');
    }
});

it('all HL7 fixtures are valid parseable messages', function () {
    $fixtures = File::glob(base_path('tests/Fixtures/hl7/*.hl7'));

    expect(count($fixtures))->toBeGreaterThanOrEqual(4);

    foreach ($fixtures as $path) {
        $raw = File::get($path);
        $name = basename($path);

        $message = Hl7Message::fromString($raw);
        $msh = $message->segment('MSH');
        expect($msh)->not->toBeNull("{$name} should have MSH segment");
        expect($msh->component(9, 1))->not->toBeEmpty("{$name} should have a message type");
    }
});

it('ORU result observations have required fields', function () {
    $raw = File::get(base_path('tests/Fixtures/hl7/oru_r01_basic.hl7'));
    $message = Hl7Message::fromString($raw);

    $parser = new OruR01Parser;
    $parsed = $parser->parse($message);

    foreach ($parsed['orders'] as $order) {
        foreach ($order['observations'] as $obs) {
            expect($obs)->toHaveKey('testCode');
            expect($obs)->toHaveKey('value');
            expect($obs)->toHaveKey('abnormalFlags');
        }
    }
});
