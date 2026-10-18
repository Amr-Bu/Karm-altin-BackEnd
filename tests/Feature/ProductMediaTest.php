<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

class ProductMediaTest extends TestCase
{
    use DatabaseTransactions;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Use phpunit.mysql.xml with the authorized MySQL database.');
        }
        Storage::fake('public');
        $this->f = OrderFixtures::create();
        $this->withToken($this->f['admin']->createToken('media-tests')->plainTextToken);
    }

    private function image(string $name = 'photo.png'): UploadedFile
    {
        // Real tiny PNG fixture, independent of the optional PHP GD extension.
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII='));
    }

    private function body(array $extra = []): array
    {
        return $extra + ['name' => 'Media product', 'item_type' => 'PRODUCED_PRODUCT', 'unit' => 'PIECE', 'price' => '12.50'];
    }

    public static function creationCases(): array
    {
        return [[0, null], [0, "وصف عربي\nEnglish description"], [1, null], [3, null], [2, 'With photos']];
    }

    #[DataProvider('creationCases')]
    public function test_creation_supports_optional_description_and_images(int $count, ?string $description): void
    {
        $images = array_map(fn () => $this->image('../../untrusted.png'), range(1, max(1, $count)));
        $body = $this->body(['description' => $description]);
        if ($count > 0) {
            $body['images'] = array_slice($images, 0, $count);
        }
        $response = $this->post('/api/products', $body, ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.description', $description)->assertJsonCount($count, 'data.images');
        $product = Product::findOrFail($response->json('data.id'));
        $this->assertNull($product->inventoryStock);
        $this->assertCount($count, $product->images);
        foreach ($product->images as $image) {
            $this->assertTrue($image->product->is($product));
            $this->assertMatchesRegularExpression('~^products/'.$product->id.'/[a-f0-9-]+\.png$~', $image->image_path);
            Storage::disk('public')->assertExists($image->image_path);
            $this->assertContains(['id' => $image->id, 'url' => Storage::disk('public')->url($image->image_path)], $response->json('data.images'));
        }
        $this->assertCount($count, Storage::disk('public')->allFiles());
    }

    public function test_omitted_description_defaults_to_null_and_updates_append_and_preserve(): void
    {
        $id = $this->postJson('/api/products', $this->body())->assertCreated()
            ->assertJsonPath('data.description', null)->assertJsonPath('data.images', [])->json('data.id');
        $url = '/api/products/'.$id;
        $this->post($url, ['_method' => 'PATCH', 'description' => "عربي\nEnglish", 'images' => [$this->image(), $this->image()]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(2, 'data.images');
        $original = Product::find($id)->images->pluck('image_path')->all();
        $this->patchJson($url, ['name' => 'Changed'])->assertOk()->assertJsonPath('data.description', "عربي\nEnglish")->assertJsonCount(2, 'data.images');
        $this->post($url, ['_method' => 'PATCH', 'images' => [$this->image()]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(3, 'data.images')->assertJsonPath('data.description', "عربي\nEnglish");
        foreach (['', null] as $clear) {
            $this->patchJson($url, ['description' => $clear])->assertOk()->assertJsonPath('data.description', null)->assertJsonCount(3, 'data.images');
        }
        Storage::disk('public')->assertExists($original);
        $this->assertCount(3, Storage::disk('public')->allFiles());
    }

    public function test_list_details_catalog_category_and_status_include_media_without_lazy_loading(): void
    {
        $category = Category::create(['name' => 'Media category', 'is_active' => true]);
        $id = $this->post('/api/products', $this->body(['category_id' => $category->id, 'description' => 'Public description', 'images' => [$this->image()]]), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $detail = $this->getJson('/api/products/'.$id)->assertOk()->assertJsonCount(1, 'data.images')->json('data');
        $this->assertArrayNotHasKey('image_path', $detail['images'][0]);
        $this->assertArrayNotHasKey('meta', $this->getJson('/api/products/'.$id)->json());
        $this->getJson('/api/products?category_id='.$category->id)->assertOk()->assertJsonPath('data.0.description', 'Public description')->assertJsonPath('data.0.images', $detail['images']);
        $this->getJson('/api/categories/'.$category->id)->assertOk()->assertJsonPath('data.products.0.images', $detail['images']);
        $catalog = $this->getJson('/api/catalog')->assertOk()->json();
        $row = collect($catalog['data'])->firstWhere('id', $category->id);
        $this->assertSame($detail['images'], $row['products'][0]['images']);
        $this->assertArrayNotHasKey('ingredients', $row['products'][0]);
        $this->assertArrayNotHasKey('meta', $catalog);
        $this->patchJson('/api/products/'.$id.'/status', ['is_active' => false])->assertOk()->assertJsonCount(1, 'data.images');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/products?per_page=100')->assertOk();
        $before = count(DB::getQueryLog());
        for ($i = 0; $i < 4; $i++) {
            $this->post('/api/products', $this->body(['images' => [$this->image()]]), ['Accept' => 'application/json'])->assertCreated();
        }
        DB::flushQueryLog();
        $this->getJson('/api/products?per_page=100')->assertOk();
        $this->assertSame($before, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public static function invalidUploads(): array
    {
        return [['text'], ['svg'], ['url'], ['size'], ['count'], ['description']];
    }

    #[DataProvider('invalidUploads')]
    public function test_invalid_uploads_never_write_products_or_files(string $case): void
    {
        $input = match ($case) {
            'text' => ['images' => [UploadedFile::fake()->createWithContent('disguised.jpg', '<?php echo 1;')]],
            'svg' => ['images' => [UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]],
            'url' => ['images' => ['https://example.com/photo.png']],
            'size' => ['images' => [$this->image()->size(5121)]],
            'count' => ['images' => array_map(fn () => $this->image(), range(1, 11))],
            'description' => ['description' => str_repeat('ع', 32768)],
        };
        $before = Product::count();
        $this->post('/api/products', $this->body($input), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame($before, Product::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->post('/api/products/'.$this->f['meal']->id, $input + ['_method' => 'PATCH'], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public static function physicalTypes(): array
    {
        return [['RAW_MATERIAL'], ['PURCHASED_PRODUCT']];
    }

    #[DataProvider('physicalTypes')]
    public function test_images_preserve_physical_stock_creation(string $type): void
    {
        $id = $this->post('/api/products', $this->body(['item_type' => $type, 'images' => [$this->image()]]), ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->assertSame('0.000', Product::find($id)->inventoryStock->current_quantity);
    }

    public function test_database_failure_cleans_files_and_rolls_back_create_and_update(): void
    {
        $service = app(ProductService::class);
        $existing = $service->create($this->body(['description' => 'Keep', 'images' => [$this->image()]]));
        $files = Storage::disk('public')->allFiles();
        $counts = [Product::count(), ProductImage::count(), InventoryStock::count()];
        $dispatcher = ProductImage::getEventDispatcher();
        ProductImage::setEventDispatcher(clone $dispatcher);
        ProductImage::creating(fn () => throw new RuntimeException('Simulated image row failure'));
        try {
            foreach ([false, true] as $updating) {
                try {
                    $input = $this->body(['item_type' => 'PURCHASED_PRODUCT', 'description' => 'Changed', 'images' => [$this->image()]]);
                    $updating ? $service->update($existing, $input) : $service->create($input);
                    $this->fail('Expected database write failure');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Simulated image row failure', $exception->getMessage());
                }
                $this->assertSame($counts, [Product::count(), ProductImage::count(), InventoryStock::count()]);
                $this->assertSame($files, Storage::disk('public')->allFiles());
                $this->assertSame('Keep', $existing->fresh()->description);
            }
        } finally {
            ProductImage::setEventDispatcher($dispatcher);
        }
    }

    public static function writes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('writes')]
    public function test_partial_upload_failure_removes_all_new_files_and_rows(bool $updating): void
    {
        $service = app(ProductService::class);
        $existing = $service->create($this->body(['description' => 'Keep', 'images' => [$this->image()]]));
        $disk = Storage::disk('public');
        $originalFiles = $disk->allFiles();
        $adapter = \Mockery::mock(FilesystemAdapter::class);
        $calls = 0;
        $adapter->shouldReceive('putFileAs')->andReturnUsing(function (...$args) use ($disk, &$calls) {
            $path = $disk->putFileAs(...$args);

            return ++$calls === 2 ? false : $path;
        });
        $adapter->shouldReceive('delete')->andReturnUsing(fn ($paths) => $disk->delete($paths));
        Storage::shouldReceive('disk')->with('public')->andReturn($adapter);
        $counts = [Product::count(), ProductImage::count(), InventoryStock::count()];
        try {
            $input = ['description' => 'Changed', 'images' => [$this->image(), $this->image()]];
            $updating ? $service->update($existing, $input) : $service->create($this->body($input + ['item_type' => 'PURCHASED_PRODUCT']));
            $this->fail('Expected upload failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to store product image.', $exception->getMessage());
        }
        $this->assertSame($counts, [Product::count(), ProductImage::count(), InventoryStock::count()]);
        $this->assertSame($originalFiles, $disk->allFiles());
        $this->assertSame('Keep', $existing->fresh()->description);
    }
}
