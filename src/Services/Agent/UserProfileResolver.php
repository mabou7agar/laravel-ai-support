<?php

namespace LaravelAIEngine\Services\Agent;

class UserProfileResolver
{
    public function __construct(
        protected array $config = []
    ) {
    }

    public function resolve($userId): string
    {
        if (!$userId) {
            return '- No user profile available';
        }

        $userModel = $this->getConfig('user_model', null);
        if (!$userModel || !is_string($userModel) || !class_exists($userModel)) {
            return "- User ID: {$userId}";
        }

        try {
            $user = $userModel::find($userId);
            if (!$user) {
                return "- User ID: {$userId} (profile not found)";
            }

            $fields = $this->getConfig('fields', ['name', 'email']);
            $lines = [];

            foreach ($fields as $field) {
                $value = data_get($user, $field);
                if ($value === null || $value === '') {
                    continue;
                }

                $label = ucwords(str_replace(['_', '-'], ' ', (string) $field));
                if (is_array($value)) {
                    $value = json_encode($value);
                } elseif (is_object($value)) {
                    $value = method_exists($value, '__toString') ? (string) $value : json_encode($value);
                }

                $lines[] = "- {$label}: {$value}";
            }

            if (empty($lines)) {
                return "- User ID: {$userId}";
            }

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            return "- User ID: {$userId}";
        }
    }

    protected function getConfig(string $key, $default = null)
    {
        if (array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }

        try {
            return match ($key) {
                'user_model' => config('ai-engine.user_model', $default),
                'fields' => config('ai-agent.orchestrator.user_profile_fields', $default),
                default => $default,
            };
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
