<?php
use MongoDB\Model\BSONDocument;

class RentalPriceUtils
{
    /**
     * Find the matching price entry from a sub-unit's price options,
     * matching by plan name first, falling back to the first option.
     */
    public static function extractMatchedPrice($newSubUnit, $oldPriceName, $unitDoc)
    {
        $priceOptions = $newSubUnit['price'] ?? [];

        if (is_numeric($priceOptions)) {
            return [
                'name' => $oldPriceName ?? 'default',
                'price' => (float) $priceOptions
            ];
        }

        if (isset($priceOptions['price']) && is_numeric($priceOptions['price'])) {
            return [
                'name' => $oldPriceName ?? $priceOptions['name'] ?? 'default',
                'price' => (float) $priceOptions['price']
            ];
        }

        if (is_array($priceOptions)) {
            foreach ($priceOptions as $option) {
                $option = $option instanceof BSONDocument ? $option->getArrayCopy() : $option;
                if ($oldPriceName && ($option['name'] ?? null) === $oldPriceName) {
                    return $option;
                }
            }

            $firstOption = reset($priceOptions);
            if ($firstOption) {
                return $firstOption instanceof BSONDocument
                    ? $firstOption->getArrayCopy()
                    : $firstOption;
            }
        }

        return [
            'name' => $oldPriceName ?? 'default',
            'price' => (float) ($unitDoc['unitPrice'] ?? 0.0)
        ];
    }

    /**
     * Calculate parking + shuttle fees based on plan name.
     */
    public static function calculateAddonFees($planName, $parkingData = null, $shuttleData = null): array
    {
        $parkingFee = 0.0;
        $shuttleFee = 0.0;

        if (!empty($parkingData) && !empty($parkingData['hasParking'])) {
            $parkingFee = match ($planName) {
                '10-month' => 500.0,
                '11-month' => 455.0,
                'annual'   => 5000.0,
                default    => (float) ($parkingData['fee'] ?? 0.0),
            };
        }

        if (!empty($shuttleData) && !empty($shuttleData['hasShuttle'])) {
            $shuttleFee = match ($planName) {
                '10-month' => 800.0,
                '11-month' => 800.0,
                'annual'   => 8000.0,
                default    => (float) ($shuttleData['fee'] ?? 0.0),
            };
        }

        return [
            'parkingFee' => $parkingFee,
            'shuttleFee' => $shuttleFee,
        ];
    }
}