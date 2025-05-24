<?php # custom/cdc/Business/CRF.php

namespace cdc;

use bX\DataCaptureService;
use bX\Log;
use Exception;

class CRF {

    private const CDC_APPLICATION_NAME = 'CDC_APP';

    /**
     * Defines a new field for the CDC application in DataCaptureService.
     *
     * @param array $fieldDefinition Expected to be an array like:
     *   [
     *     'field_name' => 'VSORRES_SYSBP',
     *     'label' => 'Systolic Blood Pressure',
     *     'data_type' => 'NUMERIC', // e.g., VARCHAR, NUMERIC, DATE, BOOLEAN
     *     'attributes_json' => [ // Optional: for UI hints, validation, datalists etc.
     *       'description' => 'Subject Systolic Blood Pressure measurement',
     *       'min_value' => 0,
     *       'max_value' => 300
     *     ]
     *   ]
     * @param string $actorUserId The user ID performing this action.
     * @return array ['success' => bool, 'message' => string, 'definition_id' => int|null]
     */
    public static function defineCDCField(array $fieldDefinition, string $actorUserId): array {
        if (empty($fieldDefinition['field_name']) || empty($fieldDefinition['data_type'])) {
            return ['success' => false, 'message' => "field_name and data_type are required in fieldDefinition."];
        }
        if (empty($actorUserId)) {
            return ['success' => false, 'message' => "Actor User ID is required."];
        }

        try {
            // DataCaptureService::defineCaptureField expects the definition as the second argument.
            // $fieldDefinition already matches the new structure for defineCaptureField's second argument.
            $result = DataCaptureService::defineCaptureField(
                self::CDC_APPLICATION_NAME,
                $fieldDefinition, // This is the array with field_name, label, data_type, attributes_json
                $actorUserId
            );

            if (!$result['success']) {
                Log::logWarning("CRF::defineCDCField - Failed to define field via DataCaptureService.",
                    ['app_name' => self::CDC_APPLICATION_NAME, 'field_def' => $fieldDefinition, 'error' => $result['message'] ?? 'Unknown error']
                );
            }

            return $result;

        } catch (Exception $e) {
            Log::logError("CRF::defineCDCField Exception: " . $e->getMessage(),
                ['app_name' => self::CDC_APPLICATION_NAME, 'field_def' => $fieldDefinition, 'trace_short' => substr($e->getTraceAsString(),0,500)]
            );
            return ['success' => false, 'message' => 'Error in defineCDCField: ' . $e->getMessage()];
        }
    }
}
?>
