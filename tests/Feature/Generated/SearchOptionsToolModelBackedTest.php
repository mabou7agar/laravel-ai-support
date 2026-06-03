<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Generated {

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\Schema;
    use LaravelAIEngine\DTOs\UnifiedActionContext;
    use LaravelAIEngine\Services\Agent\Tools\SearchOptionsTool;
    use LaravelAIEngine\Services\AIEngineService;
    use LaravelAIEngine\Tests\TestCase;
    use Mockery;

    /**
     * Eloquent model used to exercise SearchOptionsTool::searchInModel against a real
     * (in-memory) table with the "name"/"id" columns that branch plucks.
     */
    class SearchOptionsCategoryModel extends Model
    {
        protected $table = 'search_options_categories';
        public $timestamps = false;
        protected $guarded = [];
    }

    /**
     * Covers SearchOptionsTool's model-backed `searchInModel` branch (taken when a valid
     * model_class is passed). That branch calls a global schema() helper the host app is
     * expected to supply but the package leaves undefined; we define a minimal one (see
     * the root-namespace block at the bottom of this file) so the branch executes instead
     * of throwing — which the tool would otherwise silently swallow into "no options".
     */
    class SearchOptionsToolModelBackedTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            Schema::dropIfExists('search_options_categories');
            Schema::create('search_options_categories', function ($table) {
                $table->id();
                $table->string('name');
            });

            SearchOptionsCategoryModel::query()->insert([
                ['name' => 'Electronics'],
                ['name' => 'Electrical Supplies'],
                ['name' => 'Gardening'],
            ]);
        }

        protected function makeTool(): SearchOptionsTool
        {
            // searchInModel never touches the AI engine; mock it so the tool constructs
            // without any real LLM dependency.
            return new SearchOptionsTool(Mockery::mock(AIEngineService::class));
        }

        public function test_search_options_helper_is_defined_for_the_model_branch(): void
        {
            $this->assertTrue(function_exists('schema'));
            $this->assertTrue(schema()->hasColumn('search_options_categories', 'name'));
        }

        public function test_model_backed_search_returns_matching_options(): void
        {
            $tool = $this->makeTool();

            $result = $tool->execute([
                'field_name' => 'category',
                'query' => 'Electr',
                'model_class' => SearchOptionsCategoryModel::class,
            ], new UnifiedActionContext('search-options-model', 1));

            $this->assertTrue($result->success, $result->error ?? '');

            $options = $result->data['options'] ?? [];
            $this->assertIsArray($options);
            $this->assertContains('Electronics', $options);
            $this->assertContains('Electrical Supplies', $options);
            $this->assertNotContains('Gardening', $options);
            $this->assertSame(count($options), $result->data['count']);
            $this->assertSame('category', $result->data['field']);
        }

        public function test_model_backed_search_without_query_returns_all_options(): void
        {
            $tool = $this->makeTool();

            $result = $tool->execute([
                'field_name' => 'category',
                'model_class' => SearchOptionsCategoryModel::class,
            ], new UnifiedActionContext('search-options-model-all', 1));

            $this->assertTrue($result->success, $result->error ?? '');

            $options = $result->data['options'] ?? [];
            $this->assertContains('Electronics', $options);
            $this->assertContains('Gardening', $options);
            $this->assertSame(3, $result->data['count']);
        }
    }
}

namespace {

    use Illuminate\Support\Facades\Schema;

    /**
     * Global schema() helper that the host app is expected to provide.
     * SearchOptionsTool::searchInModel calls schema()->hasColumn(...) unqualified from
     * its own namespace, which PHP resolves to this root-namespace function at call
     * time. Defining it here lets the model-backed branch run instead of throwing.
     */
    if (!function_exists('schema')) {
        function schema()
        {
            return Schema::connection(config('database.default'));
        }
    }
}
