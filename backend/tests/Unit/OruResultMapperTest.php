<?php

use App\Support\Hl7\Hl7Message;
use App\Support\Hl7\OruResultMapper;

/**
 * The ORU^R01 → result-entry mapping layer (INTEROPERABILITY §HL7,
 * PRODUCT_REQUIREMENTS §6.8): fixture-tested readiness for a future
 * LIS/analyzer adapter. The mapper is pure — it interprets a parsed
 * message, never touches the database.
 */
function mapFixture(string $file): array
{
    return (new OruResultMapper)->map(Hl7Message::fromString(file_get_contents(__DIR__.'/../Fixtures/hl7/'.$file)));
}

it('maps a basic CBC ORU^R01 fixture into the result-entry shape', function () {
    $mapped = mapFixture('oru_r01_basic.hl7');

    expect($mapped['messageControlId'])->toBe('MSG-0001')
        ->and($mapped['sendingApplication'])->toBe('SwasthyaLIS')
        ->and($mapped['sendingFacility'])->toBe('LAB1')
        ->and($mapped['patient']['mrn'])->toBe('MRN-1001')
        ->and($mapped['patient']['familyName'])->toBe('Doe')
        ->and($mapped['patient']['givenName'])->toBe('John')
        ->and($mapped['patient']['sex'])->toBe('M')
        ->and($mapped['messageTime'])->toBe('2026-08-16T09:30:00+00:00');

    expect(count($mapped['orders']))->toBe(1);
    $order = $mapped['orders'][0];
    expect($order['accessionNumber'])->toBe('ACC-260816-ABC123')
        ->and($order['testCode'])->toBe('CBC')
        ->and($order['testName'])->toBe('Complete Blood Count')
        ->and($order['specimenSource'])->toBe('blood')
        ->and($order['priority'])->toBeNull(); // OBR-27 empty → default

    expect(count($order['observations']))->toBe(3);
    $wbc = $order['observations'][0];
    expect($wbc['setId'])->toBe(1)
        ->and($wbc['valueType'])->toBe('NM')
        ->and($wbc['testCode'])->toBe('WBC')
        ->and($wbc['value'])->toBe('7.2')
        ->and($wbc['unit'])->toBe('x10^9/L')
        ->and($wbc['referenceRange'])->toBe('4.0-11.0')
        ->and($wbc['abnormalFlags'])->toBe(['N'])
        ->and($wbc['isCritical'])->toBeNull()
        ->and($wbc['status'])->toBe('F')
        ->and($wbc['observedAt'])->toBe('2026-08-16T09:30:00+00:00');

    // The CBC order's other two observations map too.
    expect(array_column($order['observations'], 'testCode'))->toBe(['WBC', 'HGB', 'PLT']);
});

it('derives criticality from the HH abnormal flag in the critical fixture', function () {
    $mapped = mapFixture('oru_r01_critical.hl7');

    expect($mapped['messageControlId'])->toBe('MSG-0002');
    $glucose = $mapped['orders'][0]['observations'][0];
    expect($glucose['testCode'])->toBe('GLU')
        ->and($glucose['value'])->toBe('450')
        ->and($glucose['unit'])->toBe('mg/dL')
        ->and($glucose['referenceRange'])->toBe('70-99')
        ->and($glucose['abnormalFlags'])->toBe(['HH'])
        ->and($glucose['isCritical'])->toBeTrue()
        ->and($glucose['observedAt'])->toBe('2026-08-16T09:45:00+00:00');
});

it('groups OBX observations under their own OBR order in a multi-order message', function () {
    $mapped = mapFixture('oru_r01_multiple_orders.hl7');

    expect(count($mapped['orders']))->toBe(2);

    $lft = $mapped['orders'][0];
    expect($lft['accessionNumber'])->toBe('ACC-260816-GHI789')
        ->and($lft['testCode'])->toBe('LFT')
        ->and(count($lft['observations']))->toBe(2)
        ->and(array_column($lft['observations'], 'testCode'))->toBe(['ALT', 'AST'])
        ->and($lft['observations'][0]['abnormalFlags'])->toBe(['H'])
        ->and($lft['observations'][0]['isCritical'])->toBeFalse();

    $lip = $mapped['orders'][1];
    expect($lip['accessionNumber'])->toBe('ACC-260816-JKL012')
        ->and($lip['testCode'])->toBe('LIP')
        ->and(count($lip['observations']))->toBe(1)
        ->and($lip['observations'][0]['testCode'])->toBe('LDL');
});

it('classifies abnormal flags into critical / abnormal / unknown', function () {
    $mapper = new OruResultMapper;

    expect($mapper->criticalityFromFlags(['HH']))->toBeTrue()
        ->and($mapper->criticalityFromFlags(['LL']))->toBeTrue()
        ->and($mapper->criticalityFromFlags(['HH', 'H']))->toBeTrue()
        ->and($mapper->criticalityFromFlags(['H']))->toBeFalse()
        ->and($mapper->criticalityFromFlags(['L']))->toBeFalse()
        ->and($mapper->criticalityFromFlags(['N']))->toBeNull()
        ->and($mapper->criticalityFromFlags(['A']))->toBeNull()
        ->and($mapper->criticalityFromFlags([]))->toBeNull();
});

it('parses HL7 DTM timestamps tolerantly', function () {
    $mapper = new OruResultMapper;

    expect($mapper->parseDateTime('20260816093000'))->toBe('2026-08-16T09:30:00+00:00')
        ->and($mapper->parseDateTime('20260816093000.5'))->toBe('2026-08-16T09:30:00+00:00')
        ->and($mapper->parseDateTime('20260816'))->toBe('2026-08-16T00:00:00+00:00')
        ->and($mapper->parseDateTime('20260816093000+0545'))->toBe('2026-08-16T09:30:00+05:45')
        ->and($mapper->parseDateTime('20260816093000Z'))->toBe('2026-08-16T09:30:00+00:00')
        ->and($mapper->parseDateTime(''))->toBeNull()
        ->and($mapper->parseDateTime('not-a-date'))->toBeNull()
        ->and($mapper->parseDateTime('20261399'))->toBeNull(); // month 13
});

it('maps HL7 priorities to the internal vocabulary', function () {
    $mapper = new OruResultMapper;
    $mapped = mapFixture('oru_r01_basic.hl7');
    $critical = mapFixture('oru_r01_critical.hl7');

    expect($mapped['orders'][0]['priority'])->toBeNull(); // empty OBR-27

    // A stat message maps to the internal 'stat' priority.
    $stat = (new OruResultMapper)->map(Hl7Message::fromString(
        "MSH|^~\\&|SYS|FAC|DST|HOSP|20260816100000||ORU^R01|M6|P|2.3.1\r".
        "PID|1||MRN-9\r".
        // OBR-5 (the HL7 v2.3 priority field) carries the 'S' (stat) code.
        "OBR|1|ORD-9|ACC-9|GLU^Glucose^L|S\r".
        "OBX|1|NM|GLU^Glucose^L||110|mg/dL|70-99|N\r"
    ));

    expect($stat['orders'][0]['priority'])->toBe('stat');
});
