<?php

namespace App\Support\Hl7;

/**
 * Maps a parsed ORU^R01 message into the result-entry semantics the lab
 * domain understands (INTEROPERABILITY §HL7, PRODUCT_REQUIREMENTS §6.8).
 * This is the "readiness" layer for a future LIS/analyzer adapter: it
 * translates external syntax into the internal shape (test code, value,
 * unit, reference range, criticality) WITHOUT touching the database — the
 * mapping is explicit, versioned, and fixture-tested. A future import job
 * matches observations to lab_order_items by test code + accession and
 * routes unmatchable messages to a review queue (INTEROPERABILITY §HL7:
 * inbound data is untrusted input).
 *
 * Criticality is derived from the HL7 abnormal-flag vocabulary: HH/LL
 * (critically high/low) are critical; H/L (high/low) are abnormal but not
 * critical; anything else resolves to null (not determinable from the
 * message) — the internal entry step still lets a human flag criticality.
 */
final class OruResultMapper
{
    private const CRITICAL_FLAGS = ['HH', 'LL'];

    private const ABNORMAL_FLAGS = ['H', 'L'];

    /**
     * @return array<string, mixed>
     */
    public function map(Hl7Message $message): array
    {
        $parsed = (new OruR01Parser)->parse($message);

        return [
            'messageControlId' => $parsed['messageControlId'],
            'messageTime' => $this->parseDateTime($parsed['messageTime']),
            'sendingApplication' => $parsed['sendingApplication'],
            'sendingFacility' => $parsed['sendingFacility'],
            'patient' => $parsed['patient'],
            'orders' => array_map(fn (array $order): array => $this->mapOrder($order), $parsed['orders']),
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function mapOrder(array $order): array
    {
        return [
            'placerOrderNumber' => $order['placerOrderNumber'],
            'fillerOrderNumber' => $order['fillerOrderNumber'],
            'accessionNumber' => $order['accessionNumber'],
            'testCode' => $order['universalServiceCode'],
            'testName' => $order['universalServiceName'],
            'specimenSource' => $order['specimenSource'],
            'priority' => $this->mapPriority($order['priority']),
            'resultStatus' => $order['resultStatus'],
            'observations' => array_map(fn (array $obx): array => [
                'setId' => $obx['setId'],
                'valueType' => $obx['valueType'],
                'testCode' => $obx['testCode'],
                'testName' => $obx['testName'],
                'value' => $obx['value'],
                'unit' => $obx['unit'],
                'referenceRange' => $obx['referenceRange'],
                'abnormalFlags' => $obx['abnormalFlags'],
                'isCritical' => $this->criticalityFromFlags($obx['abnormalFlags']),
                'status' => $obx['status'],
                'observedAt' => $this->parseDateTime($obx['observedAt']),
                'producer' => $obx['producer'],
            ], $order['observations']),
        ];
    }

    /**
     * @param  list<string>  $flags
     */
    public function criticalityFromFlags(array $flags): ?bool
    {
        foreach ($flags as $flag) {
            if (in_array($flag, self::CRITICAL_FLAGS, true)) {
                return true;
            }
        }
        foreach ($flags as $flag) {
            if (in_array($flag, self::ABNORMAL_FLAGS, true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * HL7 priority (OBR-27 / ORC-9): R → routine, A → ASAP, S → stat, P →
     * pre-op. Unknown or empty → null (the consumer keeps its default).
     */
    private function mapPriority(string $priority): ?string
    {
        return match (strtoupper($priority)) {
            'R' => 'routine',
            'A' => 'urgent',
            'S' => 'stat',
            'P' => 'urgent',
            default => null,
        };
    }

    /**
     * Parse an HL7 DTM (YYYY[MM[DD[HH[MM[SS]]]]]) into ISO-8601 (UTC), or
     * null when absent/unparseable — malformed timestamps never throw.
     */
    public function parseDateTime(string $dtm): ?string
    {
        if ($dtm === '') {
            return null;
        }

        if (preg_match('/^(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?(?:\.\d+)?(Z|[+-]\d{4})?$/', $dtm, $m) !== 1) {
            return null;
        }

        $year = (int) $m[1];
        $month = (int) ($m[2] ?? 1);
        $day = (int) ($m[3] ?? 1);
        $hour = (int) ($m[4] ?? 0);
        $minute = (int) ($m[5] ?? 0);
        $second = (int) ($m[6] ?? 0);

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 60) {
            return null;
        }

        $offset = ($m[7] ?? 'Z') === 'Z'
            ? '+00:00'
            : substr($m[7], 0, 3).':'.substr($m[7], 3, 2);

        return sprintf('%04d-%02d-%02dT%02d:%02d:%02d%s', $year, $month, $day, $hour, $minute, $second, $offset);
    }
}
