<?php

namespace CDC; // Or CDC\Study if you prefer deeper nesting

use bX\CONN;    // Assuming CONN might be needed for transactions later
use bX\Log;
use bX\Profile;
use CDC\CRF;
use CDC\Study;
use Exception;

/**
 * Class Setup
 *
 * Provides methods for configuring study-specific components,
 * such as defining the structure of Case Report Forms (CRFs)
 * by linking fields to form domains for a specific study.
 *
 * @package CDC
 */
class Setup {

    /**
     * Configures a specific form (form_domain) for a given study by linking
     * a set of fields with their order and options.
     *
     * This method iterates through a provided list of fields and uses
     * CRF::addFormField to create the associations in the database.
     * It logs the progress and outcome using bX\Log.
     *
     * @param string $studyId The public/protocol identifier for the study (e.g., 'PROT-001').
     * @param array $data An associative array containing the form configuration:
     * - 'form_domain' (string, required): The identifier for the form (e.g., 'VS').
     * - 'fields' (array, required): An associative array where keys are field_names
     * and values are arrays containing at least 'order' (int) and optionally
     * 'options' (array) for CRF::addFormField.
     * Example: [
     * 'VSPERF' => ['order' => 10],
     * 'VSDTC'  => ['order' => 20, 'options' => ['is_mandatory' => false]]
     * ]
     * @return array An associative array indicating success or failure:
     * - 'success' (bool): True on success, false on failure.
     * - 'message' (string): A descriptive message about the outcome.
     */
    public static function configureForm(string $studyId, array $data): array {

        // Attempt to get Actor ID from Bintelx Profile. Fallback to 'SYSTEM_SETUP' if not available.
        $actorId = Profile::$account_id ?? Profile::$profile_id ?? 'SYSTEM_SETUP';

        // Validate input data structure
        if (empty($studyId) || empty($data['form_domain']) || !isset($data['fields']) || !is_array($data['fields'])) {
            Log::logError("Setup::configureForm - Invalid input.", ['studyId' => $studyId, 'data_keys' => array_keys($data)]);
            return ['success' => false, 'message' => "Invalid input: Study ID, form_domain, and fields array are required."];
        }

        $formDomain = $data['form_domain'];
        $formLabel  = $data['form_label'];
        $fields = $data['fields'];

        Log::logInfo("Starting form configuration for Study: '$studyId', Form: '$formDomain'. Actor: '$actorId'.");

        // A. Validate that the study exists
        $studyCheck = Study::getStudyDetails($studyId);
        if (!$studyCheck['success']) {
            Log::logError("Setup::configureForm - Study not found.", ['studyId' => $studyId, 'formDomain' => $formDomain]);
            return ['success' => false, 'message' => "Study '$studyId' not found."];
        }

        // B. Link each field to the form for this study
        $allSuccess = true;
        $messages = [];
        $addedCount = 0;

        foreach ($fields as $fieldName => $config) {

            // Ensure basic config structure is present
            if (!isset($config['order'])) {
                 Log::logWarning("Setup::configureForm - Skipping field '$fieldName' due to missing 'order'.", ['studyId' => $studyId, 'formDomain' => $formDomain]);
                 $messages[] = "Skipped '$fieldName': Missing 'order'.";
                 $allSuccess = false; // Consider this a failure or just a skip? Let's mark as failure.
                 continue;
            }

            $itemOrder = (int)$config['order'];
            $options = $config['options'] ?? [];

            Log::logInfo("  - Adding field '$fieldName' to '$formDomain' with order $itemOrder...", ['studyId' => $studyId]);

            try {
                // Call CRF::addFormField
                $result = CRF::addFormField(
                    $studyId,
                    $formDomain,
                    $formLabel,
                    $fieldName,
                    $itemOrder,
                    $options,
                    $actorId
                );

                // Check the result and log accordingly
                if (!$result['success']) {
                    $allSuccess = false;
                    $messages[] = "Failed to add '$fieldName': " . $result['message'];
                    Log::logWarning("  - FAILED adding '$fieldName': " . $result['message'], ['studyId' => $studyId, 'formDomain' => $formDomain]);
                } else {
                    $addedCount++;
                    Log::logInfo("  - SUCCESS adding '$fieldName'. ID: " . ($result['form_field_id'] ?? 'N/A'), ['studyId' => $studyId, 'formDomain' => $formDomain]);
                }

            } catch (Exception $e) {
                 // Catch any unexpected exceptions from CRF::addFormField
                 $allSuccess = false;
                 $messages[] = "Exception adding '$fieldName': " . $e->getMessage();
                 Log::logError("  - EXCEPTION adding '$fieldName': " . $e->getMessage(), ['studyId' => $studyId, 'formDomain' => $formDomain, 'trace' => substr($e->getTraceAsString(), 0, 500)]);
            }
        }

        // C. Return the final result
        if ($allSuccess) {
            $finalMessage = "'$formDomain' Form configured successfully for study '$studyId'. $addedCount fields processed.";
            Log::logInfo($finalMessage, ['studyId' => $studyId, 'formDomain' => $formDomain]);
            return ['success' => true, 'message' => $finalMessage];
        } else {
            $finalMessage = "Errors during '$formDomain' Form configuration for study '$studyId': " . implode('; ', $messages);
            Log::logError($finalMessage, ['studyId' => $studyId, 'formDomain' => $formDomain]);
            return ['success' => false, 'message' => $finalMessage];
        }
    }
}

/*
// --- Cómo se llamaría (How it would be called) ---



*/
?>