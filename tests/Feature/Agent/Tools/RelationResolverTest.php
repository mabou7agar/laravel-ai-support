<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Agent\Tools;

use LaravelAIEngine\Services\Agent\Tools\RelationResolver;
use LaravelAIEngine\Tests\Models\User;
use LaravelAIEngine\Tests\TestCase;

/**
 * Deterministic find-or-create of a related record (no model-driven tool loop), with the
 * user-provided identity as the source of truth.
 */
class RelationResolverTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $relation = [
        'field' => 'user_id',
        'model' => User::class,
        'identity' => ['email'],
        'map' => ['email' => 'customer_email', 'name' => 'customer_name'],
        'create' => ['name', 'email'],
        'defaults' => ['password' => 'seeded'], // create-only; must not filter the find
    ];

    /** @var array<string, mixed> */
    private array $scope = [];

    private function resolver(): RelationResolver
    {
        return app(RelationResolver::class);
    }

    public function test_finds_an_existing_record_by_identity(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        $out = $this->resolver()->resolve(
            ['customer_email' => 'ada@example.com', 'customer_name' => 'Ada'],
            $this->relation,
            $this->scope
        );

        $this->assertSame($user->id, $out['user_id']);
        $this->assertSame(1, User::count(), 'must not create a duplicate');
    }

    public function test_creates_the_record_when_missing(): void
    {
        $out = $this->resolver()->resolve(
            ['customer_email' => 'grace@example.com', 'customer_name' => 'Grace Hopper'],
            $this->relation,
            $this->scope
        );

        $this->assertNotEmpty($out['user_id'] ?? null);
        $this->assertDatabaseHas('users', ['email' => 'grace@example.com', 'name' => 'Grace Hopper']);
    }

    public function test_provided_email_is_the_source_of_truth_over_a_mismatched_id(): void
    {
        // A loose name match handed us the wrong record's id.
        $wrong = User::create(['name' => 'Mohamed Abou Khaled', 'email' => 'other@example.com', 'password' => 'x']);

        $out = $this->resolver()->resolve(
            ['user_id' => $wrong->id, 'customer_email' => 'm.abou7agar@gmail.com', 'customer_name' => 'Mohamed'],
            $this->relation,
            $this->scope
        );

        $this->assertNotSame($wrong->id, $out['user_id'], 'the mismatched id must be dropped');
        $this->assertDatabaseHas('users', ['email' => 'm.abou7agar@gmail.com']);
    }

    public function test_keeps_an_id_whose_identity_matches(): void
    {
        $user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

        $out = $this->resolver()->resolve(
            ['user_id' => $user->id, 'customer_email' => 'ada@example.com'],
            $this->relation,
            $this->scope
        );

        $this->assertSame($user->id, $out['user_id']);
    }

    public function test_does_not_create_without_all_required_fields(): void
    {
        // email present but name (a required create column) missing.
        $out = $this->resolver()->resolve(
            ['customer_email' => 'nobody@example.com'],
            $this->relation,
            $this->scope
        );

        $this->assertArrayNotHasKey('user_id', $out);
        $this->assertSame(0, User::where('email', 'nobody@example.com')->count());
    }
}
