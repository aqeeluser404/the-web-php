<?php
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONDocument;

class RentalChainUtils
{
    /**
     * Returns every unit ID in this rental's renewal chain, excluding the
     * current unit itself — every prior-year unit that represents the same
     * physical room and needs to stay locked/freed in sync.
     *
     * Only includes units from the ACTIVE chain (after the last room change).
     */
    public static function getChainUnitIds($rental, $currentUnitId): array
    {
        $ids = [];

        if (!empty($rental['renewalHistory'])) {
            // Find the last room-change break (scanning backwards)
            $breakIndex = -1;
            for ($i = count($rental['renewalHistory']) - 1; $i >= 0; $i--) {
                $entry = $rental['renewalHistory'][$i];
                if (isset($entry['sameRoom']) && $entry['sameRoom'] === false) {
                    $breakIndex = $i;
                    break;
                }
            }

            foreach ($rental['renewalHistory'] as $index => $entry) {
                if ($breakIndex !== -1 && $index <= $breakIndex) {
                    continue;
                }
                if (isset($entry['fromUnit']) &&
                    (string) $entry['fromUnit'] !== (string) $currentUnitId) {
                    $ids[(string) $entry['fromUnit']] = $entry['fromUnit'];
                }
            }
        }

        // Fallback for rentals extended before renewalHistory existed
        if (empty($ids) && !empty($rental['renewedFromUnit']) &&
            (string) $rental['renewedFromUnit'] !== (string) $currentUnitId) {
            $ids[(string) $rental['renewedFromUnit']] = $rental['renewedFromUnit'];
        }

        return array_values($ids);
    }

    /**
     * Format the raw renewalHistory array for API responses.
     */
    public static function formatRenewalHistory($history): array
    {
        if (empty($history)) {
            return [];
        }

        $formatted = [];
        foreach ($history as $entry) {
            $formatted[] = [
                'fromUnit' => isset($entry['fromUnit']) ? (string) $entry['fromUnit'] : null,
                'fromUnitNumber' => $entry['fromUnitNumber'] ?? null,
                'fromSubUnit' => [
                    'roomType' => $entry['fromSubUnit']['roomType'] ?? null,
                    'bedType'  => $entry['fromSubUnit']['bedType'] ?? null,
                ],
                'fromYear' => $entry['fromYear'] ?? null,
                'toUnit' => isset($entry['toUnit']) ? (string) $entry['toUnit'] : null,
                'toUnitNumber' => $entry['toUnitNumber'] ?? null,
                'toSubUnit' => [
                    'roomType' => $entry['toSubUnit']['roomType'] ?? null,
                    'bedType'  => $entry['toSubUnit']['bedType'] ?? null,
                ],
                'toYear' => $entry['toYear'] ?? null,
                'sameRoom' => $entry['sameRoom'] ?? null,
                'changedAt' => DateUtils::safeFormat($entry['changedAt'] ?? null),
            ];
        }
        return $formatted;
    }
}