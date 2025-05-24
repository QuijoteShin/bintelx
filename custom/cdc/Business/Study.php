<?php // custom/cdc/Business/Study.php

namespace cdc;

use bX\CONN;
use bX\Log;
use Exception;

class Study {

    /**
     * Creates a new study.
     */
    public static function createStudy(array $studyDetails, string $actorUserId): array {
        if (empty($studyDetails['study_id']) || empty($studyDetails['study_title'])) {
            return ['success' => false, 'message' => 'Study ID and Study Title are required.'];
        }
        if (empty($actorUserId)) {
            return ['success' => false, 'message' => 'Actor User ID is required.'];
        }

        CONN::begin();
        try {
            $sqlCheck = "SELECT study_internal_id FROM cdc_study WHERE study_id = :study_id";
            $existing = CONN::dml($sqlCheck, [':study_id' => $studyDetails['study_id']]);
            if (!empty($existing)) {
                CONN::rollback(); // No need to keep transaction open
                return ['success' => false, 'message' => 'Study with the provided Study ID already exists.'];
            }

            $sqlInsert = "INSERT INTO cdc_study 
                            (study_id, sponsor_name, protocol_id, study_title, status, created_by_actor_id)
                          VALUES 
                            (:study_id, :sponsor_name, :protocol_id, :study_title, :status, :created_by_actor_id)";

            $params = [
                ':study_id' => $studyDetails['study_id'],
                ':sponsor_name' => $studyDetails['sponsor_name'] ?? null,
                ':protocol_id' => $studyDetails['protocol_id'] ?? null,
                ':study_title' => $studyDetails['study_title'],
                ':status' => $studyDetails['status'] ?? 'PENDING_SETUP',
                ':created_by_actor_id' => $actorUserId
            ];

            $insertResult = CONN::nodml($sqlInsert, $params);

            if (!$insertResult['success'] || empty($insertResult['last_id'])) {
                throw new Exception('Failed to create new study. DB Error: ' . ($insertResult['error'] ?? 'Unknown error'));
            }
            $studyInternalId = (int)$insertResult['last_id'];

            CONN::commit();
            return ['success' => true, 'study_internal_id' => $studyInternalId, 'study_id' => $studyDetails['study_id'], 'message' => 'Study created successfully.'];

        } catch (Exception $e) {
            if (CONN::isInTransaction()) CONN::rollback();
            Log::logError("Study::createStudy Exception: " . $e->getMessage(), ['details' => $studyDetails, 'trace' => substr($e->getTraceAsString(), 0, 500)]);
            return ['success' => false, 'message' => 'Error creating study: ' . $e->getMessage()];
        }
    }

    /**
     * Retrieves details of a study by its public study_id.
     */
    public static function getStudyDetails(string $studyId): array {
        if (empty($studyId)) {
            return ['success' => false, 'message' => 'Study ID is required.'];
        }

        try {
            $sql = "SELECT study_internal_id, study_id, sponsor_name, protocol_id, study_title, status, created_by_actor_id, created_at, updated_at 
                    FROM cdc_study 
                    WHERE study_id = :study_id";
            $studyRows = CONN::dml($sql, [':study_id' => $studyId]);

            if (empty($studyRows[0])) {
                return ['success' => false, 'message' => "Study not found with Study ID: $studyId."];
            }

            return ['success' => true, 'study_details' => $studyRows[0]];

        } catch (Exception $e) {
            Log::logError("Study::getStudyDetails Exception for Study ID $studyId: " . $e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
            return ['success' => false, 'message' => 'Error retrieving study details: ' . $e->getMessage()];
        }
    }

    /**
     * Retrieves details of a study by its internal_id.
     */
    public static function getStudyDetailsByInternalId(int $studyInternalId): array {
        if (empty($studyInternalId)) {
            return ['success' => false, 'message' => 'Study Internal ID is required.'];
        }

        try {
            $sql = "SELECT study_internal_id, study_id, sponsor_name, protocol_id, study_title, status, created_by_actor_id, created_at, updated_at 
                    FROM cdc_study 
                    WHERE study_internal_id = :study_internal_id";
            $studyRows = CONN::dml($sql, [':study_internal_id' => $studyInternalId]);

            if (empty($studyRows[0])) {
                return ['success' => false, 'message' => "Study not found with Internal ID: $studyInternalId."];
            }

            return ['success' => true, 'study_details' => $studyRows[0]];

        } catch (Exception $e) {
            Log::logError("Study::getStudyDetailsByInternalId Exception for Internal ID $studyInternalId: " . $e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
            return ['success' => false, 'message' => 'Error retrieving study details: ' . $e->getMessage()];
        }
    }

    /**
     * Updates the status of a study.
     */
    public static function updateStudyStatus(string $studyId, string $newStatus, string $actorUserId): array {
        if (empty($studyId) || empty($newStatus) || empty($actorUserId)) {
            return ['success' => false, 'message' => 'Study ID, New Status, and Actor User ID are required.'];
        }

        // Optional: Add validation for allowed status transitions if needed

        CONN::begin();
        try {
            $sqlUpdate = "UPDATE cdc_study 
                          SET status = :status, updated_at = NOW() 
                          WHERE study_id = :study_id";

            $updateResult = CONN::nodml($sqlUpdate, [
                ':status' => $newStatus,
                ':study_id' => $studyId
            ]);

            if (!$updateResult['success']) {
                throw new Exception('Failed to update study status. DB Error: ' . ($updateResult['error'] ?? 'Unknown error'));
            }

            if ($updateResult['rowCount'] === 0) {
                CONN::rollback();
                return ['success' => false, 'message' => "Study not found with Study ID: $studyId, or status is already $newStatus."];
            }

            CONN::commit();
            // Log this action with actorUserId if Bintelx has a dedicated audit log for metadata changes
            Log::logInfo("Study status updated for $studyId to $newStatus by $actorUserId.");
            return ['success' => true, 'message' => 'Study status updated successfully.'];

        } catch (Exception $e) {
            if (CONN::isInTransaction()) CONN::rollback();
            Log::logError("Study::updateStudyStatus Exception for Study ID $studyId: " . $e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
            return ['success' => false, 'message' => 'Error updating study status: ' . $e->getMessage()];
        }
    }
}
?>
