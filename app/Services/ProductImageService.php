<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ProductImageService
{
    /** The caller owns the database transaction and compensates all attempted paths on failure. */
    public function append(Product $product, array $uploads, array &$paths): void
    {
        $disk = Storage::disk('public');
        foreach ($uploads as $upload) {
            $directory = 'products/'.$product->id;
            $filename = Str::uuid().'.'.$upload->extension();
            // Record before writing: a failed write may still have created a partial file.
            $paths[] = $path = $directory.'/'.$filename;
            if ($disk->putFileAs($directory, $upload, $filename) === false) {
                throw new RuntimeException('Unable to store product image.');
            }
            $product->images()->create(['image_path' => $path]);
        }
    }

    public function cleanup(array $paths): void
    {
        if ($paths !== [] && ! Storage::disk('public')->delete($paths)) {
            throw new RuntimeException('Unable to clean up failed product uploads.');
        }
    }
}
