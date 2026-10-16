<?php

use Fleetbase\Models\Category;
use Fleetbase\Storefront\Http\Controllers\v1\CategoryController;
use Fleetbase\Storefront\Http\Resources\Catalog as CatalogResource;
use Fleetbase\Storefront\Http\Resources\Category as CategoryResource;
use Fleetbase\Storefront\Models\Catalog;
use Fleetbase\Storefront\Models\CatalogCategory;
use Fleetbase\Storefront\Observers\CategoryTreeObserver;
use Fleetbase\Storefront\Support\CategoryTree;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Validation\ValidationException;

function nestedCategoriesDb()
{
    return Model::getConnectionResolver()->connection('mysql');
}

/**
 * A store's product categories, Drinks › Coffee › Cold brew and Drinks › Tea, plus Food and
 * another store's Snacks; and a few products filed at each level.
 */
function nestedCategoriesSchema(): void
{
    $schema = nestedCategoriesDb()->getSchemaBuilder();
    foreach (['categories', 'products', 'product_addon_categories', 'product_variants', 'files', 'catalog_category_products'] as $table) {
        $schema->dropIfExists($table);
    }

    $schema->create('categories', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('owner_uuid')->nullable();
        $table->string('owner_type')->nullable();
        $table->string('parent_uuid')->nullable();
        $table->string('icon_file_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->text('tags')->nullable();
        $table->text('translations')->nullable();
        $table->text('meta')->nullable();
        $table->string('icon')->nullable();
        $table->string('slug')->nullable();
        $table->string('for')->nullable();
        $table->integer('order')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('products', function ($table) {
        $table->increments('id');
        $table->string('uuid')->nullable();
        $table->string('public_id')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('store_uuid')->nullable();
        $table->boolean('is_available')->default(true);
        $table->string('status')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    foreach (['product_addon_categories', 'product_variants'] as $table) {
        $schema->create($table, function ($blueprint) {
            $blueprint->increments('id');
            $blueprint->string('product_uuid')->nullable();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }
    $schema->create('files', function ($table) {
        $table->increments('id');
        $table->string('subject_uuid')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('catalog_category_products', function ($table) {
        $table->string('uuid')->nullable();
        $table->string('catalog_category_uuid')->nullable();
        $table->string('product_uuid')->nullable();
        $table->integer('price')->nullable();
        $table->boolean('is_available')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    $category = fn (string $uuid, string $name, ?string $parent, string $owner = 'store_uuid', string $for = 'storefront_product', int $order = 0) => [
        'uuid'         => $uuid,
        'public_id'    => 'category_' . $uuid,
        'company_uuid' => 'company_uuid',
        'owner_uuid'   => $owner,
        'parent_uuid'  => $parent,
        'name'         => $name,
        'for'          => $for,
        'order'        => $order,
        'created_at'   => now(),
        'updated_at'   => now(),
    ];
    nestedCategoriesDb()->table('categories')->insert([
        $category('drinks', 'Drinks', null),
        $category('coffee', 'Coffee', 'drinks', order: 1),
        $category('cold_brew', 'Cold brew', 'coffee'),
        $category('tea', 'Tea', 'drinks', order: 2),
        $category('food', 'Food', null, order: 3),
        $category('snacks', 'Snacks', null, 'other_store'),
    ]);

    $product = fn (string $uuid, string $category) => ['uuid' => $uuid, 'public_id' => 'product_' . $uuid, 'category_uuid' => $category, 'store_uuid' => 'store_uuid', 'is_available' => true, 'status' => 'published'];
    nestedCategoriesDb()->table('products')->insert([
        $product('flat_white', 'coffee'),
        $product('nitro', 'cold_brew'),
        $product('chai', 'tea'),
        $product('croissant', 'food'),
    ]);
}

function nestedCategoriesRequest(array $input = [], string $uri = '/v1/categories'): Request
{
    $request = Request::create($uri, 'GET', $input);
    $request->setLaravelSession(new SessionStore('nested-categories-test', new ArraySessionHandler(120)));
    // The API cache keys a list by the request's session (its store or network), as in production.
    foreach (['company', 'storefront_store', 'storefront_network'] as $key) {
        $request->session()->put($key, session($key));
    }
    app()->instance('request', $request);

    return $request;
}

function nestedCategory(string $uuid): Category
{
    return Category::where('uuid', $uuid)->firstOrFail();
}

/** What a validation failure says. */
function nestedCategoriesRefusal(Closure $callback): ?string
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors()['parent'][0] ?? null;
    }

    return null;
}

beforeEach(function () {
    nestedCategoriesSchema();
    session(['company' => 'company_uuid', 'storefront_store' => 'store_uuid', 'storefront_network' => null]);
    app()->instance('responsecache', new class {
        public function clear(): void
        {
        }
    });
    Model::setEventDispatcher(new Illuminate\Events\Dispatcher(app()));
    Model::clearBootedModels();
});

afterEach(function () {
    Category::flushEventListeners();
    CatalogCategory::flushEventListeners();
    Model::unsetEventDispatcher();
    Model::clearBootedModels();
});

test('a category takes in every category under it, and knows its level, ancestors and the levels under it', function () {
    expect(CategoryTree::withDescendants('drinks'))->toBe(['drinks', 'coffee', 'tea', 'cold_brew'])
        ->and(CategoryTree::withDescendants(nestedCategory('coffee')))->toBe(['coffee', 'cold_brew'])
        ->and(CategoryTree::withDescendants('food'))->toBe(['food'])
        ->and(CategoryTree::depth(nestedCategory('cold_brew')))->toBe(3)
        ->and(collect(CategoryTree::ancestors(nestedCategory('cold_brew')))->pluck('uuid')->all())->toBe(['coffee', 'drinks'])
        ->and(CategoryTree::height(nestedCategory('drinks')))->toBe(2)
        ->and(CategoryTree::height(nestedCategory('tea')))->toBe(0)
        ->and(CategoryTree::nests(nestedCategory('drinks')))->toBeTrue()
        ->and(CategoryTree::nests(null))->toBeFalse();

    // A parent that is gone ends the walk up.
    $orphan = (new Category())->forceFill(['uuid' => 'orphan', 'parent_uuid' => 'gone']);
    expect(CategoryTree::ancestors($orphan))->toBe([]);
});

test('a parent must be in the same store, not under the category itself, and within three levels', function () {
    $drinks   = nestedCategory('drinks');
    $coldBrew = nestedCategory('cold_brew');

    expect(nestedCategoriesRefusal(fn () => CategoryTree::assertValidParent($drinks, nestedCategory('snacks'))))
        ->toBe('A category can only be placed under a category of the same store or menu.')
        ->and(nestedCategoriesRefusal(fn () => CategoryTree::assertValidParent($drinks, $coldBrew)))
        ->toBe('A category cannot be placed under itself or one of its own subcategories.')
        // Food under Cold brew would be a fourth level.
        ->and(nestedCategoriesRefusal(fn () => CategoryTree::assertValidParent(nestedCategory('food'), $coldBrew)))
        ->toBe('Categories nest at most three levels deep.')
        // Coffee (with Cold brew under it) under Tea would put Cold brew at the fourth level.
        ->and(nestedCategoriesRefusal(fn () => CategoryTree::assertValidParent(nestedCategory('coffee'), nestedCategory('tea'))))
        ->toBe('Categories nest at most three levels deep.')
        // Food under Tea, and a new category under Coffee, are fine; so is no parent at all.
        ->and(nestedCategoriesRefusal(fn () => CategoryTree::assertValidParent(nestedCategory('food'), nestedCategory('tea'))))->toBeNull()
        ->and(nestedCategoriesRefusal(fn () => CategoryTree::assertValidParent((new Category())->forceFill(['owner_uuid' => 'store_uuid', 'for' => 'storefront_product']), nestedCategory('coffee'))))->toBeNull()
        ->and(nestedCategoriesRefusal(fn () => CategoryTree::assertValidParent($drinks, null)))->toBeNull();
});

test('a whole tree is checked before it is written, and parents are placed before their children', function () {
    $valid = ['lunch' => null, 'tacos' => 'lunch', 'veggie' => 'tacos', 'drinks' => null];

    expect(nestedCategoriesRefusal(fn () => CategoryTree::assertValidTree($valid)))->toBeNull()
        ->and(CategoryTree::topDown(['veggie' => 'tacos', 'tacos' => 'lunch', 'lunch' => null]))->toBe(['lunch', 'tacos', 'veggie'])
        ->and(nestedCategoriesRefusal(fn () => CategoryTree::assertValidTree(['tacos' => 'gone'])))
        ->toBe('A category was placed under a category that is not in this menu.')
        ->and(nestedCategoriesRefusal(fn () => CategoryTree::assertValidTree(['a' => 'b', 'b' => 'a'])))
        ->toBe('A category cannot be placed under itself or one of its own subcategories.')
        ->and(nestedCategoriesRefusal(fn () => CategoryTree::assertValidTree(['a' => null, 'b' => 'a', 'c' => 'b', 'd' => 'c'])))
        ->toBe('Categories nest at most three levels deep.');
});

test('saving a category under a parent is refused when the tree would break, and removing one moves its subcategories up', function () {
    Category::observe(CategoryTreeObserver::class);

    $food              = nestedCategory('food');
    $food->parent_uuid = 'cold_brew';
    $tooDeep           = nestedCategoriesRefusal(fn () => $food->save());

    $food->parent_uuid = 'gone';
    $missing           = nestedCategoriesRefusal(fn () => $food->save());

    $tea              = nestedCategory('tea');
    $tea->parent_uuid = 'food';
    $tea->save();

    // A category that does not nest (a network's) is left alone, as is one moved to the top.
    $network              = (new Category())->forceFill(['uuid' => 'network_category', 'for' => 'storefront_network', 'parent_uuid' => 'gone']);
    $network->save();
    $coffee              = nestedCategory('coffee');
    $coffee->parent_uuid = null;
    $coffee->save();

    // Removing Coffee moves Cold brew up to Coffee's parent (the top level now).
    nestedCategory('coffee')->delete();
    $network->delete();

    expect($tooDeep)->toBe('Categories nest at most three levels deep.')
        ->and($missing)->toBe('The parent category no longer exists.')
        ->and(nestedCategoriesDb()->table('categories')->where('uuid', 'tea')->value('parent_uuid'))->toBe('food')
        ->and(nestedCategoriesDb()->table('categories')->where('uuid', 'food')->value('parent_uuid'))->toBeNull()
        ->and(nestedCategoriesDb()->table('categories')->where('uuid', 'cold_brew')->value('parent_uuid'))->toBeNull()
        ->and(nestedCategoriesDb()->table('categories')->where('uuid', 'network_category')->value('parent_uuid'))->toBe('gone');
});

test('a store\'s categories say their parent and level, and can bring their subcategories and every product under them', function () {
    $flat     = (new CategoryController())->query(nestedCategoriesRequest())->resource->keyBy('uuid');
    $tree     = (new CategoryController())->query(nestedCategoriesRequest(['parents_only' => 1, 'with_subcategories' => 1]))->resource;
    $direct   = (new CategoryController())->query(nestedCategoriesRequest(['parents_only' => 1, 'with_products' => 1]))->resource->keyBy('uuid');
    $rollup   = (new CategoryController())->query(nestedCategoriesRequest(['parents_only' => 1, 'with_products' => 1, 'rollup' => 1]))->resource->keyBy('uuid');
    $products = fn ($category) => $category->products->map(fn ($product) => $product->resource->uuid)->sort()->values()->all();

    expect($flat->keys()->sort()->values()->all())->toBe(['coffee', 'cold_brew', 'drinks', 'food', 'tea'])
        ->and($flat['cold_brew']->parent_public_id)->toBe('category_coffee')
        ->and($flat['cold_brew']->depth)->toBe(3)
        ->and($flat['drinks']->parent_public_id)->toBeNull()
        ->and($flat['drinks']->depth)->toBe(1)
        ->and($tree->pluck('uuid')->all())->toBe(['drinks', 'food'])
        ->and($tree->firstWhere('uuid', 'drinks')->subCategories->pluck('uuid')->all())->toBe(['coffee', 'tea'])
        // Without rollup a category brings only its own products; with it, everything under it.
        ->and($products($direct['drinks']))->toBe([])
        ->and($products($rollup['drinks']))->toBe(['chai', 'flat_white', 'nitro'])
        ->and($products($rollup['food']))->toBe(['croissant']);
});

test('the category resource says a category\'s parent and level, and lists its subcategories', function () {
    $request = nestedCategoriesRequest(['with_subcategories' => 1]);
    $drinks  = nestedCategory('drinks');
    $drinks->setAttribute('parent_public_id', null);
    $drinks->setAttribute('depth', 1);
    $drinks->setRelation('subCategories', collect([nestedCategory('coffee')]));
    $coffee = nestedCategory('coffee')->setRelation('parentCategory', nestedCategory('drinks'));

    $data      = (new CategoryResource($drinks))->resolve($request);
    $loaded    = (new CategoryResource($coffee))->resolve($request);
    $plain     = (new CategoryResource(nestedCategory('tea')))->resolve(nestedCategoriesRequest());
    $noRelated = (new CategoryResource(nestedCategory('tea')))->resolve($request);

    expect($data['parent'])->toBeNull()
        ->and($data['depth'])->toBe(1)
        ->and(collect($data['subcategories'])->map(fn ($sub) => $sub->resource->uuid)->all())->toBe(['coffee'])
        ->and($loaded['parent'])->toBe('category_drinks')
        ->and($plain)->not->toHaveKeys(['parent', 'depth', 'subcategories'])
        ->and(collect($noRelated['subcategories'])->all())->toBe([]);
});

test('a network\'s categories say their parent and level too', function () {
    session(['storefront_store' => null, 'storefront_network' => 'network_uuid']);
    nestedCategoriesDb()->table('categories')->insert([
        ['uuid' => 'eat', 'public_id' => 'category_eat', 'owner_uuid' => 'network_uuid', 'parent_uuid' => null, 'name' => 'Eat', 'for' => 'storefront_network'],
        ['uuid' => 'noodles', 'public_id' => 'category_noodles', 'owner_uuid' => 'network_uuid', 'parent_uuid' => 'eat', 'name' => 'Noodles', 'for' => 'storefront_network'],
    ]);

    $categories = (new CategoryController())->query(nestedCategoriesRequest())->resource->keyBy('uuid');

    expect($categories['noodles']->parent_public_id)->toBe('category_eat')
        ->and($categories['noodles']->depth)->toBe(2);
});

test('a catalog saves its menu as a tree, new subcategories found by their key, and reports each parent publicly', function () {
    CatalogCategory::observe(CategoryTreeObserver::class);
    $catalog = new Catalog(['uuid' => 'catalog_uuid', 'company_uuid' => 'company_uuid']);
    $catalog->setRelation('categories', collect());

    $catalog->setCategories([
        ['key' => 'lunch', 'name' => 'Lunch', 'products' => []],
        ['key' => 'tacos', 'parent_key' => 'lunch', 'name' => 'Tacos', 'products' => []],
        ['key' => 'veggie', 'parent_key' => 'tacos', 'name' => 'Vegetarian', 'products' => []],
        ['key' => 'drinks', 'name' => 'Drinks', 'products' => []],
    ]);

    $saved   = fn () => CatalogCategory::where('owner_uuid', 'catalog_uuid')->get()->keyBy('name');
    $first   = $saved();
    // The models are updated in place by the next save, so keep what this one wrote.
    $firstParents = $first->map(fn ($category) => $category->parent_uuid)->all();
    $catalog      = new Catalog(['uuid' => 'catalog_uuid', 'company_uuid' => 'company_uuid']);
    $catalog->setRelation('categories', $first->values());

    // Move Vegetarian up under Lunch and Drinks under Vegetarian, in one save, by uuid.
    $catalog->setCategories([
        ['uuid' => $first['Lunch']->uuid, 'name' => 'Lunch', 'products' => []],
        ['uuid' => $first['Tacos']->uuid, 'parent_uuid' => $first['Lunch']->uuid, 'name' => 'Tacos', 'products' => []],
        ['uuid' => $first['Vegetarian']->uuid, 'parent_uuid' => $first['Lunch']->uuid, 'name' => 'Vegetarian', 'products' => []],
        ['uuid' => $first['Drinks']->uuid, 'parent_uuid' => $first['Vegetarian']->uuid, 'name' => 'Drinks', 'products' => []],
    ]);
    $second = $saved();

    $tooDeep = nestedCategoriesRefusal(fn () => $catalog->setCategories([
        ['key' => 'a', 'name' => 'A'],
        ['key' => 'b', 'parent_key' => 'a', 'name' => 'B'],
        ['key' => 'c', 'parent_key' => 'b', 'name' => 'C'],
        ['key' => 'd', 'parent_key' => 'c', 'name' => 'D'],
    ]));

    $menu = new Catalog(['uuid' => 'catalog_uuid', 'public_id' => 'catalog_menu', 'name' => 'Menu']);
    $menu->setRelation('categories', $second->values());
    $public = collect((new CatalogResource($menu))->resolve(nestedCategoriesRequest([], '/v1/catalogs'))['categories'])
        ->map(fn ($category) => $category->resolve(nestedCategoriesRequest([], '/v1/catalogs')));

    expect($firstParents['Tacos'])->toBe($first['Lunch']->uuid)
        ->and($firstParents['Vegetarian'])->toBe($first['Tacos']->uuid)
        ->and($firstParents['Lunch'])->toBeNull()
        ->and($firstParents['Drinks'])->toBeNull()
        ->and($second['Vegetarian']->parent_uuid)->toBe($second['Lunch']->uuid)
        ->and($second['Drinks']->parent_uuid)->toBe($second['Vegetarian']->uuid)
        ->and($tooDeep)->toBe('Categories nest at most three levels deep.')
        ->and($public->firstWhere('name', 'Drinks')['parent'])->toBe($second['Vegetarian']->public_id)
        ->and($public->firstWhere('name', 'Lunch')['parent'])->toBeNull();
});
