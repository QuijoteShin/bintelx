<?php
namespace bX;

use Exception;

class Account {
    private string $jwtSecret;
    private string $xorKey; // For potential other non-JWT crypto operations

    private int $tokenExpiration = 3600; // Default 1 hour for generated tokens

    public function __construct(string $jwtSecret, string $xorKey = '') {
        $this->jwtSecret = $jwtSecret;
        $this->xorKey = $xorKey;
    }

    /**
     * Creates a new account in the database.
     *
     * @param string $username
     * @param string $plainPassword The plain text password to be hashed.
     * @param bool $isActive Initial status of the account (default true).
     * @param array $otherAccountDetails Associative array for other fields like 'email', 'snapshot_id'.
     * @return string|false The new account_id on success, false on failure.
     */
    public function createAccount(string $username, string $plainPassword, bool $isActive = true, array $otherAccountDetails = []): string|false {
        Log::logDebug("Account::createAccount - Attempting to create account for username: " . $username);

        if (empty(trim($username)) || empty(trim($plainPassword))) {
            Log::logError("Account::createAccount - Username and password cannot be empty.");
            return false;
        }

        $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
        if (!$hashedPassword) {
            Log::logError("Account::createAccount - Failed to hash password.");
            return false; // Should not happen with valid inputs
        }

        $dataToInsert = [
            ':username' => $username,
            ':password' => $hashedPassword,
            ':is_active' => (int)$isActive, // Ensure TINYINT compatibility
        ];

        // Add other optional details if provided
        $optionalFields = "";
        if (array_key_exists('email', $otherAccountDetails)) {
            $optionalFields .= ", email";
            $dataToInsert[':email'] = $otherAccountDetails['email'];
        }
        if (array_key_exists('snapshot_id', $otherAccountDetails)) {
            $optionalFields .= ", snapshot_id";
            $dataToInsert[':snapshot_id'] = $otherAccountDetails['snapshot_id'];
        }
        $optionalValues = implode(", ", array_keys(array_intersect_key($dataToInsert, array_flip(explode(", ", trim($optionalFields, ", "))))));


        $sql = "INSERT INTO account (username, password, is_active" . (empty($optionalFields) ? "" : $optionalFields) . ") 
                VALUES (:username, :password, :is_active" . (empty($optionalValues) ? "" : ", " . $optionalValues) . ")";

        try {
            // Check if username already exists
            $existingUser = CONN::dml("SELECT account_id FROM account WHERE username = :username LIMIT 1", [':username' => $username]);
            if (!empty($existingUser)) {
                Log::logWarning("Account::createAccount - Username '$username' already exists.");
                return false;
            }

            $result = CONN::nodml($sql, $dataToInsert);

            if ($result['success'] && $result['last_id']) {
                $newAccountId = (string)$result['last_id'];
                Log::logInfo("Account::createAccount - Account created successfully for username: $username, Account ID: $newAccountId");
                return $newAccountId;
            } else {
                Log::logError("Account::createAccount - Failed to insert new account for username: $username.", ['db_error' => $result['error'] ?? 'Unknown DB error']);
                return false;
            }
        } catch (Exception $e) {
            Log::logError("Account::createAccount - Exception for username $username: " . $e->getMessage());
            return false;
        }
    }


    /**
     * Generates a JWT token with the specific payload structure: [METADATA, {"id": ACCOUNT_ID}].
     *
     * @param string $accountId The account ID.
     * @param mixed $metadataElement Optional metadata to include in the first element of the payload array (e.g., date("mdhs")).
     * If not provided, a timestamp will be used.
     * @param ?int $expiresIn Seconds until token expiration. Defaults to $this->tokenExpiration.
     * @return string|false The generated JWT token or false on failure.
     */
    public function generateToken(string $accountId, $metadataElement = null, ?int $expiresIn = null): string|false {
        if (empty(trim($accountId))) {
            Log::logError("Account::generateToken - Account ID cannot be empty.");
            return false;
        }

        $actualMetadata = $metadataElement ?? time(); // Default metadata to current timestamp

        // Construct the specific payload structure: array([METADATA], ["id" => ACCOUNT_ID])
        $payloadStructure = [
            $actualMetadata, // e.g., date("mdhs") or other scalar/array
            ["id" => $accountId]
        ];

        // Standard JWT claims like 'exp', 'iat', 'sub' are not part of this custom structure's top level.
        // If expiration needs to be part of the token and verifiable by `verifyToken` using standard means,
        // the payload structure or JWT class would need to accommodate it.
        // For now, adhering strictly to the user's example for setPayload.
        // Expiration can be handled by `verifyToken` if it's part of `metadataElement` or if the JWT class adds it.

        // The `bX\JWT` class should be initialized with the payload for standard claims if they are needed.
        // The user's example `setPayload($payload)` overrides any previous payload.
        // Let's assume standard claims like 'exp' are NOT part of this specific structure unless manually added to $metadataElement or the second array.
        // However, a common practice is to include 'exp'. If your JWT class does not add it automatically based on $expiresIn,
        // you might need to embed it within your custom payload structure, e.g., in $metadataElement.
        // For simplicity here, following user's direct example for payload content.

        try {
            $jwt = new JWT($this->jwtSecret);
            $jwt->setHeader(['alg' => 'HS256', 'typ' => 'JWT']);
            $jwt->setPayload($payloadStructure); // Sets the payload to the custom structure

            // Note: If token expiration is desired *within the JWT standard claims*,
            // the payload needs to be an associative array with an 'exp' key.
            // E.g., $payloadForStdClaims = ['exp' => time() + ($expiresIn ?? $this->tokenExpiration), 'data' => $payloadStructure];
            // $jwt->setPayload($payloadForStdClaims);
            // But sticking to user example for now: $jwt->setPayload($payloadStructure);

            return $jwt->generateJWT();
        } catch (Exception $e) {
            Log::logError("Account::generateToken - Exception: " . $e->getMessage(), ['account_id' => $accountId]);
            return false;
        }
    }

    /**
     * Verifies a JWT token that has the payload structure: [METADATA, {"id": ACCOUNT_ID}].
     *
     * @param string $token The JWT token string (may include "Bearer " prefix).
     * @return string|false The account_id from payload[1]['id'] if valid, false otherwise.
     */
    public function verifyToken(string $token): string|false {
        if (strpos($token, 'Bearer ') === 0) {
            $token = substr($token, 7);
        }

        if (empty($token)) {
            Log::logWarning("Account::verifyToken - Token is empty.");
            return false;
        }

        try {
            $jwt = new JWT($this->jwtSecret, $token);

            // The validateSignature method in your JWT class should verify the signature based on
            // its current header and payload.
            if (!$jwt->validateSignature()) {
                Log::logWarning("Account::verifyToken - Invalid JWT signature.");
                return false;
            }

            $payload = $jwt->getPayload(); // Expecting: [ 0 => "somedate", 1 => ["id" => $accountId] ]

            // Validate the structure and extract account_id
            if (is_array($payload) && count($payload) === 2 &&
                isset($payload[1]) && is_array($payload[1]) &&
                isset($payload[1]['id']) && !empty(trim((string)$payload[1]['id']))) {

                $accountIdFromToken = (string)$payload[1]['id'];

                // Note: Expiration check. If 'exp' is not a standard top-level claim due to the custom payload,
                // you'd need to have embedded it within your payload structure (e.g., in $payload[0] or $payload[1])
                // and check it manually here. For example:
                // if (isset($payload[0]['exp']) && time() > $payload[0]['exp']) {
                //     Log::logWarning("Account::verifyToken - Token expired based on custom payload data.");
                //     return false;
                // }

                Log::logInfo("Account::verifyToken - Token verified successfully. Account ID: " . $accountIdFromToken);
                return $accountIdFromToken;
            } else {
                Log::logWarning("Account::verifyToken - Payload structure is not as expected or 'id' is missing/empty.", ['payload_received' => $payload]);
                return false;
            }

        } catch (Exception $e) {
            Log::logError("Account::verifyToken - Exception: " . $e->getMessage(), ['token_prefix' => substr($token, 0, 10)]);
            return false;
        }
    }
}