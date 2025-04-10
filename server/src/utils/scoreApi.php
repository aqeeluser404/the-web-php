<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

class ScoreApi {
    
    public static function validateSouthAfricanID(string $idNumber): array {
        // Force to string and remove any non-digit characters
        $idNumber = preg_replace('/[^0-9]/', '', $idNumber);
        
        // Basic validation of South African ID number structure: 13 digits
        if (!preg_match('/^\d{13}$/', $idNumber)) {
            error_log("Invalid ID format: " . $idNumber);
            return ['isValid' => false, 'message' => 'Invalid ID number format'];
        }

        // Checksum validation (Luhn algorithm)
        $sum = 0;
        $shouldDouble = false;

        for ($i = 0; $i < 12; $i++) {
            $digit = (int)$idNumber[$i];

            if ($shouldDouble) {
                $doubled = $digit * 2;
                if ($doubled > 9) {
                    $doubled -= 9;
                }
                $sum += $doubled;
            } else {
                $sum += $digit;
            }
            $shouldDouble = !$shouldDouble;
        }

        $checksum = (10 - ($sum % 10)) % 10;
        $isValid = $checksum === (int)$idNumber[12];

        error_log("Checksum result: " . json_encode([
            'sum' => $sum,
            'checksum' => $checksum,
            'isValid' => $isValid
        ]));

        return [
            'isValid' => $isValid,
            'message' => $isValid ? 'ID number is valid' : 'Invalid ID number checksum'
        ];
    }

    public static function scorePayerData(array $payerData): int {
        $score = 0;
        
        // 1. Validate ID number and score
        $idValidation = self::validateSouthAfricanID($payerData['idNumber'] ?? '');
        $score += $idValidation['isValid'] ? 30 : 0;  // 30 points for valid ID number
        error_log("ID Score: " . $score);

        // 2. Check if first name is non-empty
        if (!empty($payerData['firstName']) && preg_match('/^[A-Za-z]+$/', $payerData['firstName'])) {
            $score += 10;  // 10 points for valid first name
            error_log("First Name Score: " . $score);
        }

        // 3. Check if last name is non-empty
        if (!empty($payerData['lastName']) && preg_match('/^[A-Za-z]+$/', $payerData['lastName'])) {
            $score += 10;  // 10 points for valid last name
            error_log("Last Name Score: " . $score);
        }

        // 4. Check if salary is a valid number within a reasonable range
        if (isset($payerData['salary']) && is_numeric($payerData['salary'])) {
            $salary = (float)$payerData['salary'];
            if ($salary >= 2000 && $salary <= 100000) {  // reasonable salary range check
                $score += 20;  // 20 points for valid salary
                error_log("Salary Score: " . $score);
            }
        }

        // 5. Check if bank name is non-empty
        if (!empty($payerData['bankName'])) {
            $bankName = is_array($payerData['bankName']) ? 
                ($payerData['bankName']['value'] ?? '') : 
                $payerData['bankName'];
            
            if (preg_match('/^[A-Za-z ]+$/', $bankName)) {
                $score += 10; // 10 points for non-empty, valid bank name
                error_log("Bank Score: " . $score);
            } else {
                error_log("Invalid Bank Name Format: " . $bankName);
            }
        }

        return $score;
    }
}