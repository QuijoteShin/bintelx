<?php
/**
 * EdcBusinessLogic - Domain-specific business logic for EDC
 *
 * This class is for custom business logic specific to your implementation.
 * The generic operations are in bX\EDC (kernel).
 *
 * Examples of domain-specific logic:
 * - Custom validation rules
 * - Integration with external systems
 * - Reporting and analytics
 * - Notifications and workflows
 *
 * @package edc
 */

namespace edc;

use bX\EDC;
use bX\Log;

class EdcBusinessLogic {

    /**
     * Example: Custom validation for a specific form type
     *
     * @param int $formResponseId Response ID
     * @param array $fieldsData Field data
     * @return array ['success' => bool, 'errors' => array]
     */
    public static function validatePatientIntakeForm(
        int $formResponseId,
        array $fieldsData
    ): array {
        $errors = [];

        // Example: Custom business rule
        if (isset($fieldsData['age']) && $fieldsData['age'] < 18) {
            if (empty($fieldsData['guardian_name'])) {
                $errors[] = 'Guardian name is required for patients under 18';
            }
        }

        return [
            'success' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Example: Generate report from form responses
     *
     * @param string $formName Form name
     * @param array $filters Filters
     * @return array Report data
     */
    public static function generateFormReport(
        string $formName,
        array $filters = []
    ): array {
        // Custom reporting logic
        $filters['form_name'] = $formName;
        $responses = EDC::listResponses($filters);

        // Process responses for report
        // ...

        return [
            'success' => true,
            'report' => [
                'total_responses' => $responses['total'] ?? 0,
                // Additional report data
            ]
        ];
    }

    /**
     * Example: Send notification when form is submitted
     *
     * @param int $formResponseId Response ID
     */
    public static function notifyOnFormSubmission(int $formResponseId): void {
        // Custom notification logic
        Log::logInfo('EDC.notifyOnFormSubmission', ['form_response_id' => $formResponseId]);

        // TODO: Send email, webhook, etc.
    }
}
