<?php
/**
 * DataDefinition - Wrapper for data_dictionary with HTML5 support
 *
 * Provides a higher-level interface for defining data fields with:
 * - HTML5 native input types
 * - Standard HTML attributes
 * - Custom validation rules
 * - UI hints for frontend rendering
 *
 * @package bX
 * @version 1.0
 * @date 2025-11-15
 */

namespace bX;

class DataDefinition {

    /**
     * HTML5 native control types supported
     */
    public const CONTROL_TYPES = [
        // HTML5 input types
        'text', 'email', 'tel', 'url', 'password',
        'number', 'range',
        'date', 'datetime-local', 'time', 'month', 'week',
        'color', 'checkbox', 'radio', 'file',

        // HTML5 elements
        'textarea', 'select',

        // Custom controls (extensible)
        'signature', 'rich_text', 'autocomplete', 'tags',
        'file_upload', 'image_upload', 'geolocation',
        'rating', 'slider', 'toggle'
    ];

    /**
     * Data types supported (inherited from DataCaptureService)
     */
    public const DATA_TYPES = [
        'STRING', 'DECIMAL', 'DATE', 'DATETIME', 'BOOLEAN', 'ENTITY_REF'
    ];

    /**
     * Defines a field in data_dictionary with HTML5 support
     *
     * @param array $fieldDef Field definition with extended attributes
     * @param int $actorId Actor profile ID
     * @return array ['success' => bool, 'variable_id' => int]
     */
    public static function defineField(array $fieldDef, int $actorId): array {
        try {
            // Validar control_type si existe
            if (isset($fieldDef['attributes_json']['control_type'])) {
                $controlType = $fieldDef['attributes_json']['control_type'];

                if (!in_array($controlType, self::CONTROL_TYPES)) {
                    Log::logWarning("Unknown control_type: $controlType (will be stored anyway)");
                }
            }

            // Validar data_type
            $dataType = strtoupper($fieldDef['data_type'] ?? 'STRING');
            if (!in_array($dataType, self::DATA_TYPES)) {
                return [
                    'success' => false,
                    'message' => "Invalid data_type: $dataType. Must be one of: " . implode(', ', self::DATA_TYPES)
                ];
            }

            // Normalizar attributes_json
            $attributes = $fieldDef['attributes_json'] ?? [];

            // Asegurar estructura estándar
            $normalizedAttributes = [
                'control_type' => $attributes['control_type'] ?? self::inferControlType($dataType),
                'html_attributes' => $attributes['html_attributes'] ?? [],
                'validation' => $attributes['validation'] ?? [],
                'ui_hints' => $attributes['ui_hints'] ?? []
            ];

            // Merge con otros atributos custom
            foreach ($attributes as $key => $value) {
                if (!in_array($key, ['control_type', 'html_attributes', 'validation', 'ui_hints'])) {
                    $normalizedAttributes[$key] = $value;
                }
            }

            $fieldDef['attributes_json'] = $normalizedAttributes;

            // Asegurar que is_pii sea int (0 o 1)
            $fieldDef['is_pii'] = (int)($fieldDef['is_pii'] ?? false);

            // Delegar a DataCaptureService
            return DataCaptureService::defineCaptureField($fieldDef, $actorId);

        } catch (\Exception $e) {
            Log::logError("DataDefinition.defineField: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Infers control_type from data_type
     *
     * @param string $dataType Data type
     * @return string Control type
     */
    public static function inferControlType(string $dataType): string {
        return match (strtoupper($dataType)) {
            'STRING' => 'text',
            'DECIMAL' => 'number',
            'DATE' => 'date',
            'DATETIME' => 'datetime-local',
            'BOOLEAN' => 'checkbox',
            'ENTITY_REF' => 'select',
            default => 'text'
        };
    }

    /**
     * Gets valid control types
     *
     * @return array Array of valid control types
     */
    public static function getValidControlTypes(): array {
        return self::CONTROL_TYPES;
    }

    /**
     * Gets valid data types
     *
     * @return array Array of valid data types
     */
    public static function getValidDataTypes(): array {
        return self::DATA_TYPES;
    }

    /**
     * Validates HTML attributes for a control type
     *
     * @param string $controlType Control type
     * @param array $htmlAttributes HTML attributes to validate
     * @return array ['success' => bool, 'errors' => array]
     */
    public static function validateHtmlAttributes(
        string $controlType,
        array $htmlAttributes
    ): array {
        $errors = [];

        // Validaciones específicas por tipo
        switch ($controlType) {
            case 'number':
            case 'range':
                if (isset($htmlAttributes['min']) && isset($htmlAttributes['max'])) {
                    if ($htmlAttributes['min'] > $htmlAttributes['max']) {
                        $errors[] = 'min must be less than max';
                    }
                }
                break;

            case 'email':
                if (isset($htmlAttributes['pattern']) && !self::isValidRegex($htmlAttributes['pattern'])) {
                    $errors[] = 'Invalid regex pattern for email';
                }
                break;
        }

        return [
            'success' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Checks if a regex pattern is valid
     *
     * @param string $pattern Regex pattern
     * @return bool True if valid
     */
    private static function isValidRegex(string $pattern): bool {
        return @preg_match('/' . $pattern . '/', '') !== false;
    }
}
