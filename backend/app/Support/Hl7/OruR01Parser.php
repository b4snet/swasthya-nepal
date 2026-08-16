<?php

namespace App\Support\Hl7;

/**
 * Parses an ORU^R01 (unsolicited laboratory observation) message into a
 * structured, segment-faithful array (INTEROPERABILITY §HL7). Pure syntax:
 * it extracts the MSH header, the PID patient segment, and the OBR/OBX
 * order/observation groups without interpreting meaning — OruResultMapper
 * turns this shape into result-entry semantics.
 *     * Field positions follow HL7 v2.3+ for the segments that matter to a lab
 * result import: MSH-3/4/7/10, PID-3/5/7/8, OBR-2/3/4/5/15/25, OBX-1..15.
 */
final class OruR01Parser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(Hl7Message $message): array
    {
        $msh = $message->segment('MSH');
        $pid = $message->segment('PID');

        $orders = [];
        foreach ($message->segmentsNamed('OBR') as $obr) {
            $orders[] = $this->order($message, $obr);
        }

        return [
            'messageType' => $msh?->component(9, 1) ?? '',
            'messageTrigger' => $msh?->component(9, 2) ?? '',
            'messageControlId' => $msh?->field(10) ?? '',
            'messageTime' => $msh?->field(7) ?? '',
            'sendingApplication' => $msh?->component(3, 1) ?? '',
            'sendingFacility' => $msh?->component(4, 1) ?? '',
            'receivingApplication' => $msh?->component(5, 1) ?? '',
            'patient' => [
                'externalId' => $pid?->component(3, 1) ?? '',
                'mrn' => $pid?->component(3, 1) ?? '',
                'familyName' => $pid?->component(5, 1) ?? '',
                'givenName' => $pid?->component(5, 2) ?? '',
                'dateOfBirth' => $pid?->field(7) ?? '',
                'sex' => $pid?->field(8) ?? '',
            ],
            'orders' => $orders,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function order(Hl7Message $message, Hl7Segment $obr): array
    {
        $observations = [];
        $setIds = [];

        // The observations of THIS order are the OBX segments that follow it
        // until the next OBR (the OBR/OBX group structure of ORU^R01). OBX-1
        // is the observation set id within the group.
        $after = false;
        foreach ($message->segments() as $segment) {
            if ($segment === $obr) {
                $after = true;

                continue;
            }
            if (! $after) {
                continue;
            }
            if ($segment->name === 'OBR') {
                break;
            }
            if ($segment->name !== 'OBX') {
                continue;
            }
            $setId = (int) $segment->field(1);
            $setIds[] = $setId;
            $observations[$setId] = $this->observation($segment);
        }

        // OBX segments repeat in set-id order; keep that deterministic order
        // regardless of the raw file order.
        ksort($observations);

        return [
            'placerOrderNumber' => $obr->component(2, 1),
            'fillerOrderNumber' => $obr->component(3, 1),
            'accessionNumber' => $obr->component(3, 1),
            'universalServiceCode' => $obr->component(4, 1),
            'universalServiceName' => $obr->component(4, 2),
            'specimenSource' => $obr->component(15, 1),
            'priority' => $obr->field(5), // HL7 v2.3 OBR-5 (R/A/S/P codes)
            'resultStatus' => $obr->field(25),
            'observations' => array_values($observations),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function observation(Hl7Segment $obx): array
    {
        return [
            'setId' => (int) $obx->field(1),
            'valueType' => $obx->field(2),
            'testCode' => $obx->component(3, 1),
            // Text fields are un-escaped (\F\ \S\ \T\ …): a unit such as
            // 10\S\9/L carries an escaped component separator inside its code.
            'testName' => $obx->unescape($obx->component(3, 2)),
            'value' => $obx->unescape($obx->field(5)),
            'unit' => $obx->unescape($obx->component(6, 1)),
            'referenceRange' => $obx->unescape($obx->field(7)),
            'abnormalFlags' => $obx->repetitions(8),
            'status' => $obx->field(11),
            'observedAt' => $obx->field(14),
            'producer' => $obx->component(15, 1),
        ];
    }
}
