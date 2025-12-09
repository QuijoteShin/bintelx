<?php # custom/edc/edc.data.endpoint.php

use bX\Router;
use bX\DataCaptureService;
use bX\Args;
use bX\Log;
use bX\Response;

/**
 * Endpoint para query de datos EAV en formato horizontal
 *
 * POST /edc/query/horizontal.json  → JSON
 * POST /edc/query/horizontal.toon  → Toon streaming
 *
 * Payload:
 * {
 *   "scope_entity_id": 1,
 *   "subject_entity_ids": [1, 2, 3],
 *   "variable_names": ["var1", "var2"],
 *   "convert_types": true,
 *   "context": {
 *     "macro_context": "study_001",
 *     "event_context": "visit_baseline",
 *     "sub_context": "lab_results",
 *     "parent_context_id": 42
 *   }
 * }
 */

Router::register(['POST'], 'query/horizontal\.(json|toon)', function($matches = []) {

    $format = $matches[1] ?? $matches['1'] ?? 'json'; # Captura desde regex (índice numérico o string)

    # Obtener payload desde $_POST (ya parseado en api.php)
    $payload = $_POST;

    # Validar contextos obligatorios (macro_context + event_context)
    if (empty($payload['context']['macro_context'])) {
        Log::logWarning("EDC query rejected: macro_context missing");
        if ($format === 'toon') {
            header('Content-Type: text/plain');
            echo "Error: macro_context is required";
        } else {
            Response::error('macro_context is required in context object', 400);
        }
        return;
    }

    if (empty($payload['context']['event_context'])) {
        Log::logWarning("EDC query rejected: event_context missing");
        if ($format === 'toon') {
            header('Content-Type: text/plain');
            echo "Error: event_context is required";
        } else {
            Response::error('event_context is required in context object', 400);
        }
        return;
    }

    # Construir filtros desde payload
    $filters = [
        'scope_entity_id' => $payload['scope_entity_id'] ?? null,
        'subject_entity_ids' => $payload['subject_entity_ids'] ?? null,
        'variable_names' => $payload['variable_names'] ?? null,
        'convert_types' => $payload['convert_types'] ?? false,
        'context_group_id' => $payload['context_group_id'] ?? null,
    ];

    # Extraer contexto si existe
    if (!empty($payload['context'])) {
        $ctx = $payload['context'];
        $filters['macro_context'] = $ctx['macro_context'] ?? null;
        $filters['event_context'] = $ctx['event_context'] ?? null;
        $filters['sub_context'] = $ctx['sub_context'] ?? null;
        $filters['parent_context_id'] = $ctx['parent_context_id'] ?? null;
    }

    # Limpiar filtros vacíos
    $filters = array_filter($filters, fn($v) => $v !== null && $v !== '');

    Log::logInfo("EDC query horizontal", ['format' => $format, 'filters' => $filters]);

    try {
        if ($format === 'toon') {
            # === TOON STREAMING OUTPUT ===
            header('Content-Type: text/toon; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-cache');

            $rowCount = 0;
            $fields = null;

            # Streaming progresivo: sin buffer completo en memoria
            DataCaptureService::getHorizontalData($filters, function($row) use(&$rowCount, &$fields) {
                if ($rowCount === 0) {
                    # Primera fila: emitir header Toon con campos
                    $fields = array_keys($row);
                    echo "[?]{" . implode(',', $fields) . "}:\n";
                }

                # Emitir valores en formato Toon
                $values = array_values($row);
                $encodedValues = [];

                foreach ($values as $val) {
                    if ($val === null) {
                        $encodedValues[] = 'null';
                    } elseif (is_bool($val)) {
                        $encodedValues[] = $val ? 'true' : 'false';
                    } elseif (is_numeric($val)) {
                        $encodedValues[] = $val;
                    } else {
                        # Escapar strings según spec Toon
                        $escaped = str_replace(
                            ['\\', '"', "\n", "\r", "\t"],
                            ['\\\\', '\"', '\n', '\r', '\t'],
                            (string)$val
                        );
                        $encodedValues[] = '"' . $escaped . '"';
                    }
                }

                echo '  ' . implode(',', $encodedValues) . "\n";
                $rowCount++;

                # Flush cada 10 filas (no buffering)
                if ($rowCount % 10 === 0) {
                    flush();
                }
            });

            flush(); # Flush final

            Log::logInfo("EDC query streamed", ['rows' => $rowCount, 'format' => 'toon']);

        } else {
            # === JSON OUTPUT ===
            $data = DataCaptureService::getHorizontalData($filters);

            $response = Response::success([
                'data' => $data,
                'count' => count($data),
                'filters_applied' => $filters,
                'format' => 'json'
            ]);

            $response->send();
        }

    } catch (\Exception $e) {
        Log::logError("EDC query horizontal failed", [
            'error' => $e->getMessage(),
            'format' => $format,
            'filters' => $filters
        ]);

        if ($format === 'toon') {
            if (!headers_sent()) {
                header('Content-Type: text/plain');
            }
            echo "Error: " . $e->getMessage();
        } else {
            Response::error($e->getMessage(), 500);
        }
    }

}, ROUTER_SCOPE_PUBLIC); # PUBLIC para testing (cambiar a PRIVATE después)
