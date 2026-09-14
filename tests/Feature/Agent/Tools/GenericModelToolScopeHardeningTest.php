<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Agent\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\GenericModelDetailTool;
use LaravelAIEngine\Services\Agent\Tools\GenericModelUpsertTool;
use LaravelAIEngine\Tests\TestCase;

/**
 * Tenant-scope and data-exposure guarantees for the generic model tools.
 */
class GenericModelToolScopeHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scoped_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('workspace_id');
            $table->string('api_token')->nullable();
            $table->string('client_secret')->nullable();
            $table->string('internal_note')->nullable();
            $table->unsignedInteger('token_count')->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('scoped_accounts');
        parent::tearDown();
    }

    private function scopeFor(string $workspace): \Closure
    {
        return static fn (UnifiedActionContext $context, array $parameters): array => ['workspace_id' => $workspace];
    }

    private function upsert(string $workspace): GenericModelUpsertTool
    {
        return new GenericModelUpsertTool(
            name: 'create_account',
            model: ScopedAccount::class,
            identity: ['name'],
            write: ['name'],
            defaultsResolver: static fn (): array => ['workspace_id' => $workspace],
            scope: $this->scopeFor($workspace),
        );
    }

    public function test_create_does_not_match_or_overwrite_a_same_named_record_in_another_scope(): void
    {
        $other = ScopedAccount::create(['name' => 'Acme', 'workspace_id' => 'ws-a', 'internal_note' => 'tenant A data']);

        $result = $this->upsert('ws-b')->execute(['name' => 'Acme'], new UnifiedActionContext('scope-test', 'user-b'));

        $this->assertTrue($result->success);
        $this->assertTrue((bool) ($result->data['created'] ?? false), 'A new row must be created in the caller scope.');

        $other->refresh();
        $this->assertSame('ws-a', $other->workspace_id);
        $this->assertSame('tenant A data', $other->internal_note);
        $this->assertSame(2, ScopedAccount::count());
        $this->assertSame(1, ScopedAccount::where('workspace_id', 'ws-b')->where('name', 'Acme')->count());
    }

    public function test_create_still_updates_the_matching_record_inside_the_same_scope(): void
    {
        ScopedAccount::create(['name' => 'Acme', 'workspace_id' => 'ws-b']);

        $result = $this->upsert('ws-b')->execute(['name' => 'Acme'], new UnifiedActionContext('scope-test', 'user-b'));

        $this->assertTrue($result->success);
        $this->assertFalse((bool) ($result->data['created'] ?? true));
        $this->assertSame(1, ScopedAccount::count());
    }

    public function test_show_without_explicit_columns_withholds_hidden_and_credential_columns(): void
    {
        $account = ScopedAccount::create([
            'name' => 'Acme',
            'workspace_id' => 'ws-a',
            'api_token' => 'tok_live_123',
            'client_secret' => 'shh',
            'internal_note' => 'hidden by model',
            'token_count' => 42,
        ]);

        $tool = new GenericModelDetailTool('show_account', ScopedAccount::class, ['name'], [], [], '', $this->scopeFor('ws-a'));
        $result = $tool->execute(['id' => $account->id], new UnifiedActionContext('show-test', 'user-a'));

        $this->assertTrue($result->success);
        $record = $result->data['record'];
        $this->assertSame('Acme', $record['name']);
        $this->assertSame(42, (int) $record['token_count'], 'Non-credential columns stay visible.');
        $this->assertArrayNotHasKey('api_token', $record);
        $this->assertArrayNotHasKey('client_secret', $record);
        $this->assertArrayNotHasKey('internal_note', $record, 'Model $hidden columns are respected.');
    }

    public function test_show_with_explicit_columns_returns_exactly_what_the_host_configured(): void
    {
        $account = ScopedAccount::create(['name' => 'Acme', 'workspace_id' => 'ws-a', 'internal_note' => 'allowed']);

        $tool = new GenericModelDetailTool('show_account', ScopedAccount::class, ['name'], ['id', 'name', 'internal_note'], [], '', $this->scopeFor('ws-a'));
        $result = $tool->execute(['id' => $account->id], new UnifiedActionContext('show-test', 'user-a'));

        $this->assertSame('allowed', $result->data['record']['internal_note'] ?? null);
    }

    public function test_show_cannot_read_a_record_from_another_scope(): void
    {
        $account = ScopedAccount::create(['name' => 'Acme', 'workspace_id' => 'ws-a']);

        $tool = new GenericModelDetailTool('show_account', ScopedAccount::class, ['name'], [], [], '', $this->scopeFor('ws-b'));
        $result = $tool->execute(['id' => $account->id], new UnifiedActionContext('show-test', 'user-b'));

        $this->assertFalse($result->success);
    }
}

class ScopedAccount extends Model
{
    protected $table = 'scoped_accounts';

    protected $fillable = ['name', 'workspace_id', 'api_token', 'client_secret', 'internal_note', 'token_count'];

    protected $hidden = ['internal_note'];
}
