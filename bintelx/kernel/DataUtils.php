<?php
namespace bX;

class DataUtils {
    static function formatData(array $data, array $model): array
    {
        $formatted = [];
        foreach ($model as $field => $rules) {
            $val = $data[$field] ?? null;
            switch ($rules['type']) {
                case 'int':
                    $formatted[$field] = (int) $val;
                    break;
                case 'string':
                    $formatted[$field] = (string) $val;
                    break;
                default:
                    $formatted[$field] = $val;
            }
        }
        return $formatted;
    }
}