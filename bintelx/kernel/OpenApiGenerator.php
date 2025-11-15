<?php
# bintelx/kernel/OpenApiGenerator.php
namespace bX;

/**
 * OpenApiGenerator - Generates OpenAPI 3.1 specification by scanning endpoint files
 *
 * Scans all endpoint files in custom/ directory and extracts API documentation
 * from docblocks to generate a standard OpenAPI JSON specification.
 *
 * @package bX
 * @version 1.0
 */
class OpenApiGenerator
{
    private string $customPath;
    private string $apiVersion = '1.0.0';
    private string $title = 'Bintelx API';
    private string $description = 'Auto-generated API documentation from endpoint annotations';
    private array $endpoints = [];

    public function __construct(string $customPath)
    {
        $this->customPath = rtrim($customPath, '/');
    }

    /**
     * Set API metadata
     */
    public function setMetadata(string $title, string $description, string $version): void
    {
        $this->title = $title;
        $this->description = $description;
        $this->apiVersion = $version;
    }

    /**
     * Scan all endpoint files and extract API documentation
     */
    public function scan(): void
    {
        $this->endpoints = [];
        $pattern = $this->customPath . '/*/*.endpoint.php';
        $files = glob($pattern);

        foreach ($files as $file) {
            $this->scanFile($file);
        }
    }

    /**
     * Scan a single file for endpoint definitions
     */
    private function scanFile(string $filePath): void
    {
        if (!is_file($filePath)) {
            return;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        // Extract all docblocks that contain @endpoint
        preg_match_all('/\/\*\*(.*?)\*\//s', $content, $docblocks);

        foreach ($docblocks[1] as $docblock) {
            $endpoint = $this->parseDocblock($docblock);
            if ($endpoint !== null) {
                $this->endpoints[] = $endpoint;
            }
        }
    }

    /**
     * Parse a docblock and extract endpoint information
     */
    private function parseDocblock(string $docblock): ?array
    {
        $lines = explode("\n", $docblock);
        $endpoint = [
            'path' => null,
            'methods' => [],
            'scope' => 'ROUTER_SCOPE_PUBLIC',
            'purpose' => '',
            'body' => null,
            'params' => [],
            'response' => null,
            'tags' => []
        ];

        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B*");

            // @endpoint /api/...
            if (preg_match('/@endpoint\s+(.+)/', $line, $matches)) {
                $endpoint['path'] = trim($matches[1]);
            }

            // @method GET, POST, etc.
            if (preg_match('/@method\s+(.+)/', $line, $matches)) {
                $methods = array_map('trim', explode(',', $matches[1]));
                $endpoint['methods'] = array_map('strtoupper', $methods);
            }

            // @scope ROUTER_SCOPE_PUBLIC | ROUTER_SCOPE_PRIVATE
            if (preg_match('/@scope\s+(.+)/', $line, $matches)) {
                $endpoint['scope'] = trim($matches[1]);
            }

            // @purpose Description of endpoint
            if (preg_match('/@purpose\s+(.+)/', $line, $matches)) {
                $endpoint['purpose'] = trim($matches[1]);
            }

            // @body (JSON) {...}
            if (preg_match('/@body\s+\(([^)]+)\)\s+(.+)/', $line, $matches)) {
                $endpoint['body'] = [
                    'contentType' => strtolower(trim($matches[1])),
                    'schema' => trim($matches[2])
                ];
            }

            // @param name type description
            if (preg_match('/@param\s+(\w+)\s+(\w+)\s+(.+)/', $line, $matches)) {
                $endpoint['params'][] = [
                    'name' => $matches[1],
                    'type' => $matches[2],
                    'description' => trim($matches[3])
                ];
            }

            // @response {...}
            if (preg_match('/@response\s+(.+)/', $line, $matches)) {
                $endpoint['response'] = trim($matches[1]);
            }

            // @tag TagName
            if (preg_match('/@tag\s+(.+)/', $line, $matches)) {
                $endpoint['tags'][] = trim($matches[1]);
            }
        }

        // Only return if we have a valid endpoint path
        if ($endpoint['path'] === null) {
            return null;
        }

        // Default methods if not specified
        if (empty($endpoint['methods'])) {
            $endpoint['methods'] = ['GET'];
        }

        // Auto-tag based on path
        if (empty($endpoint['tags'])) {
            $pathParts = explode('/', trim($endpoint['path'], '/'));
            if (count($pathParts) >= 2) {
                $endpoint['tags'][] = ucfirst($pathParts[1]); // e.g., "api" -> skip, "_demo" -> "Demo"
            }
        }

        return $endpoint;
    }

    /**
     * Generate OpenAPI 3.1.0 specification
     */
    public function generate(): array
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $this->title,
                'description' => $this->description,
                'version' => $this->apiVersion,
                'contact' => [
                    'name' => 'API Support',
                    'url' => Config::get('APP_URL', 'https://dev.local')
                ]
            ],
            'servers' => [
                [
                    'url' => Config::get('APP_URL', 'https://dev.local'),
                    'description' => 'Development server'
                ]
            ],
            'paths' => [],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                        'description' => 'JWT token obtained from /api/_demo/login'
                    ]
                ],
                'schemas' => []
            ],
            'tags' => []
        ];

        // Group endpoints by path
        $pathGroups = [];
        foreach ($this->endpoints as $endpoint) {
            $path = $endpoint['path'];
            if (!isset($pathGroups[$path])) {
                $pathGroups[$path] = [];
            }
            $pathGroups[$path][] = $endpoint;
        }

        // Build paths
        $tags = [];
        foreach ($pathGroups as $path => $endpoints) {
            $spec['paths'][$path] = [];

            foreach ($endpoints as $endpoint) {
                foreach ($endpoint['methods'] as $method) {
                    $methodLower = strtolower($method);

                    $operation = [
                        'summary' => $endpoint['purpose'] ?: 'No description available',
                        'tags' => $endpoint['tags'],
                        'parameters' => [],
                        'responses' => [
                            '200' => [
                                'description' => 'Successful response',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => [
                                                    'type' => 'object',
                                                    'description' => 'Response data'
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            '400' => [
                                'description' => 'Bad request',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'success' => ['type' => 'boolean', 'example' => false],
                                                        'message' => ['type' => 'string', 'example' => 'Error message']
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            '401' => [
                                'description' => 'Unauthorized',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'success' => ['type' => 'boolean', 'example' => false],
                                                        'message' => ['type' => 'string', 'example' => 'Invalid credentials']
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            '500' => [
                                'description' => 'Internal server error',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'data' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'success' => ['type' => 'boolean', 'example' => false],
                                                        'message' => ['type' => 'string', 'example' => 'An internal error occurred']
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ];

                    // Add security if private endpoint
                    if ($endpoint['scope'] === 'ROUTER_SCOPE_PRIVATE') {
                        $operation['security'] = [
                            ['bearerAuth' => []]
                        ];
                    }

                    // Add request body if specified
                    if ($endpoint['body'] !== null) {
                        $operation['requestBody'] = [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => $this->parseBodySchema($endpoint['body']['schema'])
                                ]
                            ]
                        ];
                    }

                    // Add query/path parameters
                    foreach ($endpoint['params'] as $param) {
                        $operation['parameters'][] = [
                            'name' => $param['name'],
                            'in' => 'query',
                            'schema' => ['type' => $param['type']],
                            'description' => $param['description']
                        ];
                    }

                    $spec['paths'][$path][$methodLower] = $operation;

                    // Collect unique tags
                    foreach ($endpoint['tags'] as $tag) {
                        if (!in_array($tag, $tags)) {
                            $tags[] = $tag;
                        }
                    }
                }
            }
        }

        // Add tags with descriptions
        foreach ($tags as $tag) {
            $spec['tags'][] = [
                'name' => $tag,
                'description' => "Operations related to {$tag}"
            ];
        }

        return $spec;
    }

    /**
     * Parse body schema from docblock annotation
     * Converts simple JSON-like string to OpenAPI schema
     */
    private function parseBodySchema(string $schemaString): array
    {
        // Try to decode as JSON
        $decoded = json_decode($schemaString, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->convertToOpenApiSchema($decoded);
        }

        // Fallback: generic object schema
        return [
            'type' => 'object',
            'description' => $schemaString
        ];
    }

    /**
     * Convert a simple associative array to OpenAPI schema
     */
    private function convertToOpenApiSchema(array $data): array
    {
        $properties = [];
        foreach ($data as $key => $value) {
            $type = gettype($value);
            $properties[$key] = [
                'type' => $this->phpTypeToJsonType($type),
                'example' => $value
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties
        ];
    }

    /**
     * Convert PHP type to JSON Schema type
     */
    private function phpTypeToJsonType(string $phpType): string
    {
        return match ($phpType) {
            'integer' => 'integer',
            'double', 'float' => 'number',
            'boolean' => 'boolean',
            'array' => 'array',
            'NULL' => 'null',
            default => 'string'
        };
    }

    /**
     * Get the generated endpoints (for debugging)
     */
    public function getEndpoints(): array
    {
        return $this->endpoints;
    }
}
