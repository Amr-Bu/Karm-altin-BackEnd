<?php

/** Verify trigger upgrade/rollback in a disposable database, never the application database. */
require dirname(__DIR__).'/vendor/autoload.php';

use App\Models\ProductIngredient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\OrderFixtures;

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$original = DB::connection();
if ($original->getDriverName() !== 'mysql') {
    throw new RuntimeException('MySQL/MariaDB is required.');
}
$database = 'recipe_migration_test_'.bin2hex(random_bytes(8));
if (! preg_match('/^recipe_migration_test_[a-f0-9]{16}$/D', $database)) {
    throw new RuntimeException('Invalid disposable database name.');
}
$original->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$assertions = 0;
$check = function (bool $condition, string $message) use (&$assertions): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
};
$rejects = function (callable $operation) use ($check): void {
    try {
        $operation();
    } catch (QueryException $exception) {
        $check($exception->errorInfo[0] === '45000', 'Expected trigger rejection.');

        return;
    }
    throw new RuntimeException('Invalid recipe was accepted by the trigger.');
};
try {
    config(['database.connections.recipe_test' => array_replace($original->getConfig(), ['name' => 'recipe_test', 'database' => $database, 'url' => null]),
        'database.default' => 'recipe_test']);
    Schema::clearResolvedInstance('db.schema');
    Artisan::call('migrate', ['--database' => 'recipe_test', '--force' => true]);
    DB::setDefaultConnection('recipe_test');
    $f = OrderFixtures::create();
    $migration = require dirname(__DIR__).'/database/migrations/2026_09_26_000001_allow_purchased_recipe_components.php';
    $migration->up(); // Also safe after an interrupted/retried upgrade.
    $row = $f['meal']->ingredients()->create(['raw_material_id' => $f['drink']->id, 'quantity_required' => '1.000']);
    $row->update(['quantity_required' => '2.000']);
    $check($row->fresh()->quantity_required === '2.000', 'Purchased component must support insert and update.');
    try {
        $migration->down();
        throw new LogicException('Rollback must refuse existing purchased components.');
    } catch (RuntimeException $exception) {
        $check(str_contains($exception->getMessage(), 'Cannot restore raw-only'), 'Unexpected rollback failure.');
    }
    $check(ProductIngredient::whereKey($row->id)->exists(), 'Refused rollback must preserve recipe data.');
    $row->update(['quantity_required' => '3.000']);
    $row->delete();
    $migration->down();
    $raw = $f['meal']->ingredients()->first();
    $raw->update(['quantity_required' => '0.300']);
    $check($raw->fresh()->quantity_required === '0.300', 'Raw components must still update after rollback.');
    $rejects(fn () => $f['meal']->ingredients()->create(['raw_material_id' => $f['drink']->id, 'quantity_required' => 1]));
    $rejects(fn () => $raw->update(['raw_material_id' => $f['drink']->id]));
    $rejects(fn () => $f['drink']->ingredients()->create(['raw_material_id' => $f['raw']->id, 'quantity_required' => 1]));
    $migration->up();
    $raw->refresh()->update(['raw_material_id' => $f['drink']->id]);
    $check($raw->fresh()->raw_material_id === $f['drink']->id, 'Re-upgrade must permit purchased components on update.');
    $rejects(fn () => $raw->update(['raw_material_id' => $f['meal']->id]));
    echo "Recipe migration upgrade/rollback: $assertions assertions passed.\n";
} finally {
    DB::purge('recipe_test');
    $original->statement('DROP DATABASE `'.$database.'`');
    echo "Disposable recipe migration database removed.\n";
}
