<?php

namespace App\Support\Hl7;

/**
 * Maps an ADT^A01 (admit patient) message to a canonical, internal-facing
 * shape (INTEROPERABILITY.md §5 — the ADT admit/transfer/discharge patterns
 * the ecosystem uses). Pure syntax + explicit segment/field mapping,
 * fixture-tested against backend/tests/Fixtures/hl7/adt_a01_basic.hl7.
 *
 * Like OruR01Parser this is the READINESS layer only: it translates the
 * message into a validated internal shape — no live HL7 transport, no
 * import side effects. Field positions follow HL7 v2.3+: MSH-3/4/7/9/10,
 * PID-3/5/7/8/11, PV1-2/3/4/7/8/36/44.
 */
final class AdtA01Mapper
{
    /**
     * @return array<string, mixed>
     */
    public function map(Hl7Message $message): array
    {
        $msh = $message->segment('MSH');
        $pid = $message->segment('PID');
        $pv1 = $message->segment('PV1');

        $admitWard = $pv1?->component(3, 1) ?? '';
        $admitRoom = $pv1?->component(3, 2) ?? '';
        $admitBed = $pv1?->component(3, 3) ?? '';
        $location = trim(implode(' ', array_filter([$admitWard, $admitRoom, $admitBed])));

        return [
            'messageType' => $msh?->component(9, 1) ?? '',
            'messageTrigger' => $msh?->component(9, 2) ?? '',
            'messageControlId' => $msh?->field(10) ?? '',
            'messageTime' => $msh?->field(7) ?? '',
            'sendingApplication' => $msh?->component(3, 1) ?? '',
            'sendingFacility' => $msh?->component(4, 1) ?? '',
            'patientClass' => $pv1?->field(2) ?? '',
            'admissionType' => $pv1?->field(4) ?? '',
            'admitDateTime' => $pv1?->field(44) ?? '',
            'referringDoctorId' => $pv1?->component(8, 1) ?? '',
            'admissionSource' => $pv1?->field(36) ?? '',
            'location' => $location !== '' ? $location : null,
            'patient' => [
                'externalId' => $pid?->component(3, 1) ?? '',
                'mrn' => $pid?->component(3, 1) ?? '',
                'familyName' => $pid?->component(5, 1) ?? '',
                'givenName' => $pid?->component(5, 2) ?? '',
                'dateOfBirth' => $pid?->field(7) ?? '',
                'sex' => $pid?->field(8) ?? '',
                'address' => $pid?->field(11) ?? '',
            ],
        ];
    }
}
