<?php
# kernel/Scon/Validator.php
namespace bX\Scon;

class Validator {

    private string $mode; # loose, warn, strict
    private ?string $enforce;
    private array $warnings = [];
    private array $errors = [];

    # Required fields per enforce spec
    private const ENFORCE_RULES = [
        'openapi:3.1' => [
            'required' => ['openapi', 'info', 'paths'],
            'info.required' => ['title', 'version'],
        ],
        'openapi:3.0' => [
            'required' => ['openapi', 'info', 'paths'],
            'info.required' => ['title', 'version'],
        ],
    ];

    public function __construct(array $options = []) {
        $this->mode = $options['mode'] ?? 'warn';
        $this->enforce = $options['enforce'] ?? null;
    }

    # Validate data and return result
    public function validate(mixed $data): array {
        $this->warnings = [];
        $this->errors = [];

        if (!is_array($data)) {
            $this->addIssue('Root value must be an object or array');
            return $this->result();
        }

        # Apply enforce rules if configured
        if ($this->enforce !== null && isset(self::ENFORCE_RULES[$this->enforce])) {
            $this->enforceSpec($data, self::ENFORCE_RULES[$this->enforce]);
        }

        return $this->result();
    }

    # Validate a schema definition
    public function validateSchema(string $name, array $schema): array {
        $this->warnings = [];
        $this->errors = [];

        if (empty($schema)) {
            $this->addIssue("Schema '$name' is empty");
        }

        return $this->result();
    }

    # Validate data against a schema (field presence)
    public function validateAgainstSchema(array $data, array $schema, string $path = ''): array {
        $this->warnings = [];
        $this->errors = [];

        foreach ($schema as $key => $def) {
            $fieldPath = $path ? "$path.$key" : $key;
            $isRequired = str_ends_with($key, '!');
            $cleanKey = rtrim($key, '!');

            if ($isRequired && !isset($data[$cleanKey])) {
                $this->addIssue("Missing required field: $fieldPath");
            }
        }

        # Check for extra fields
        if ($this->mode === 'strict') {
            $schemaKeys = array_map(fn($k) => rtrim($k, '!'), array_keys($schema));
            foreach (array_keys($data) as $dataKey) {
                if (!in_array($dataKey, $schemaKeys, true)) {
                    $this->addIssue("Extra field not in schema: $path.$dataKey");
                }
            }
        } elseif ($this->mode === 'warn') {
            $schemaKeys = array_map(fn($k) => rtrim($k, '!'), array_keys($schema));
            foreach (array_keys($data) as $dataKey) {
                if (!in_array($dataKey, $schemaKeys, true)) {
                    $this->warnings[] = "Extra field: $path.$dataKey";
                }
            }
        }
        # loose mode: no warnings for extras

        return $this->result();
    }

    private function enforceSpec(array $data, array $rules): void {
        if (isset($rules['required'])) {
            foreach ($rules['required'] as $field) {
                if (!isset($data[$field])) {
                    $this->addIssue("Missing required field per {$this->enforce}: $field");
                }
            }
        }

        # Check nested required fields (pattern: parent.required)
        foreach ($rules as $ruleKey => $ruleFields) {
            if ($ruleKey === 'required') continue;
            if (str_ends_with($ruleKey, '.required')) {
                $parentKey = substr($ruleKey, 0, -9); # remove .required
                if (isset($data[$parentKey]) && is_array($data[$parentKey])) {
                    foreach ($ruleFields as $field) {
                        if (!isset($data[$parentKey][$field])) {
                            $this->addIssue("Missing required field per {$this->enforce}: $parentKey.$field");
                        }
                    }
                }
            }
        }
    }

    private function addIssue(string $msg): void {
        if ($this->mode === 'strict' || $this->enforce !== null) {
            $this->errors[] = $msg;
        } elseif ($this->mode === 'warn') {
            $this->warnings[] = $msg;
        }
        # loose: ignore
    }

    private function result(): array {
        return [
            'valid' => empty($this->errors),
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'mode' => $this->mode,
            'enforce' => $this->enforce
        ];
    }
}
