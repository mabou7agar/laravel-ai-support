<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Services\Agent\UserProfileResolver;
use PHPUnit\Framework\TestCase;

class UserProfileResolverTest extends TestCase
{
    public function test_returns_fallback_when_user_model_missing(): void
    {
        $resolver = new UserProfileResolver([
            'user_model' => null,
            'fields' => ['name', 'email'],
        ]);

        $profile = $resolver->resolve(15);

        $this->assertSame('- User ID: 15', $profile);
    }

    public function test_returns_no_profile_for_empty_user(): void
    {
        $resolver = new UserProfileResolver([
            'user_model' => null,
            'fields' => ['name', 'email'],
        ]);

        $profile = $resolver->resolve(null);

        $this->assertSame('- No user profile available', $profile);
    }
}
