<?php # custom/cdc/Business/CRF.php
namespace cdc;

class CRF {
    public static function createFieldDefinition(array $fieldData, string $actor): array {
        if (!isset($fieldData['field_name'], $fieldData['data_type'])) {
            return ['success' => false, 'message' => 'Faltan campos obligatorios'];
        }

        $applicationName = 'CDC_APP';
        $fieldName = strtoupper($fieldData['field_name']);
        $label = $fieldData['label'] ?? $fieldName;
        $dataType = strtoupper($fieldData['data_type']);
        $attributes = $fieldData['attributes_json'] ?? [];

        return \bX\DataCaptureService::defineCaptureField(
            $applicationName,
            $fieldName,
            $dataType,
            $label,
            json_encode($attributes),
            $actor
        );
    }
}