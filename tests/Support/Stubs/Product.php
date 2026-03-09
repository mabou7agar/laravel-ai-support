<?php

namespace LaravelAIEngine\Tests\Support\Stubs;

class Product
{
    public static function executeAI(string $action, array $params): array
    {
        $missing = [];

        foreach (['name', 'price'] as $field) {
            if (!isset($params[$field]) || $params[$field] === '' || $params[$field] === null) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            return [
                'success' => false,
                'error' => 'Missing required parameters: ' . implode(', ', $missing),
            ];
        }

        return [
            'success' => true,
            'id' => 123,
            'name' => $params['name'],
            'price' => $params['price'],
        ];
    }
}

