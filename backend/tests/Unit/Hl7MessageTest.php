<?php

use App\Support\Hl7\Hl7Message;

/**
 * The HL7 v2 syntax layer (INTEROPERABILITY §HL7): segment splitting,
 * separator learning from MSH, and escape decoding. Pure parsing — no
 * database, no ORU semantics (that is OruResultMapper's job).
 */
it('learns the field and encoding separators from the MSH segment', function () {
    $message = Hl7Message::fromString(
        "MSH|^~\\&|SYS|FAC|DST|HOSP|20260816093000||ORU^R01|M1|P|2.3.1\r".
        "OBR|1|ORD-1|ACC-1|CBC^Complete Blood Count^L\r"
    );

    expect($message->segment('MSH'))->not->toBeNull()
        ->and($message->segment('MSH')->component(9, 1))->toBe('ORU')
        ->and($message->segment('MSH')->component(9, 2))->toBe('R01')
        ->and($message->segment('MSH')->field(10))->toBe('M1');
});

it('splits segments on carriage returns and tolerates newlines', function () {
    // CRLF line endings (as produced by real LIS outboxes).
    $message = Hl7Message::fromString(
        "MSH|^~\\&|SYS|FAC|DST|HOSP|20260816093000||ORU^R01|M2|P|2.3.1\r\n".
        "PID|1||MRN-9\r\n".
        "OBX|1|NM|WBC^White Blood Count^L|7.2\r\n"
    );

    expect(count($message->segmentsNamed('PID')))->toBe(1)
        ->and($message->segment('PID')->component(3, 1))->toBe('MRN-9')
        ->and(count($message->segmentsNamed('OBX')))->toBe(1);
});

it('returns all repetitions of a field', function () {
    $message = Hl7Message::fromString(
        "MSH|^~\\&|SYS|FAC|DST|HOSP|20260816093000||ORU^R01|M3|P|2.3.1\r".
        "OBX|1|NM|GLU^Glucose^L||450|mg/dL|70-99|HH~H\r"
    );

    expect($message->segment('OBX')->repetitions(8))->toBe(['HH', 'H'])
        ->and($message->segment('OBX')->repetition(8, 1))->toBe('HH')
        ->and($message->segment('OBX')->repetition(8, 2))->toBe('H')
        ->and($message->segment('OBX')->repetition(8, 3))->toBe('');
});

it('decodes the HL7 escape sequences', function () {
    $message = Hl7Message::fromString(
        "MSH|^~\\&|SYS|FAC|DST|HOSP|20260816093000||ORU^R01|M4|P|2.3.1\r".
        "OBX|1|ST|COM^Comment^L||Diagnosis\\F\\note\\S\\with\\T\\escapes\\E\\\r"
    );

    $decoded = $message->segment('OBX')->unescape($message->segment('OBX')->field(5));
    expect($decoded)->toBe('Diagnosis|note^with&escapes\\');
});

it('returns empty strings for out-of-range field access', function () {
    $message = Hl7Message::fromString("MSH|^~\\&|SYS|FAC|DST|HOSP|20260816093000||ORU^R01|M5|P|2.3.1\r");

    expect($message->segment('MSH')->field(99))->toBe('')
        ->and($message->segment('MSH')->component(9, 9))->toBe('')
        ->and($message->segment('PID'))->toBeNull();
});

it('rejects messages that do not start with MSH', function () {
    expect(fn () => Hl7Message::fromString("PID|1||MRN-1\r"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Hl7Message::fromString(''))->toThrow(InvalidArgumentException::class);
});
