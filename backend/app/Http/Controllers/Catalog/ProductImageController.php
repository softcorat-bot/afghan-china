<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Product photos — stored on the PRIVATE disk and served through the app by
 * CatalogPhotoController, exactly like the attachment system. Nothing here
 * depends on `storage:link`, the public disk, or web-server configuration:
 * the write goes to `storage/app/private/products/…`, and the read is an API
 * route. Legacy files on the public disk keep being served by the same route.
 */
class ProductImageController extends Controller
{
    /** Where product photos live now. */
    private const DISK = 'local';
    public function upload(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:15360'],
        ]);

        $file = $request->file('image');
        $raw = (string) file_get_contents($file->getRealPath());

        $this->store($product, $raw, strtolower($file->getClientOriginalExtension() ?: 'jpg'));

        ActivityLog::log('updated', 'Product', "Set photo for \"{$product->name}\"");

        return response()->json($product->fresh());
    }

    /**
     * The picture library: every photo already in this shop's storage, plus the
     * ones that shipped with the repo. Lets a user set a product photo by
     * *picking* one instead of hunting for the file again — the common case
     * when several products share a picture, or a photo was uploaded once and
     * is needed on another line.
     *
     * @return JsonResponse
     */
    public function library(): JsonResponse
    {
        $items = [];

        // 1. Photos already uploaded to this company — on the private disk
        //    now, plus anything still sitting on the legacy public disk from
        //    before the switch. Both are served by the same API route.
        $dir = 'products/'.Tenant::id();
        $seen = [];
        foreach (['local', 'public'] as $disk) {
            foreach (Storage::disk($disk)->files($dir) as $path) {
                if (! $this->isImage($path) || isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
                $items[] = [
                    'source' => 'storage',
                    'key' => $path,
                    'name' => basename($path),
                    'url' => '/api/catalog-photo/'.$path,
                    'size' => Storage::disk($disk)->size($path),
                ];
            }
        }

        // 2. The pictures that travel with the repo, so a fresh install has a
        //    library on day one instead of an empty grid. Mirrored onto the
        //    private disk under library/ so the photo route can stream them.
        //    Cheap and idempotent: only missing files are copied.
        foreach (glob(database_path('seed-images/*')) as $file) {
            if (! is_file($file) || ! $this->isImage($file)) {
                continue;
            }
            $name = basename($file);
            $mirror = 'library/'.$name;
            if (! Storage::disk(self::DISK)->exists($mirror)
                || Storage::disk(self::DISK)->size($mirror) !== filesize($file)) {
                Storage::disk(self::DISK)->put($mirror, (string) file_get_contents($file));
            }
            $items[] = [
                'source' => 'seed',
                'key' => 'seed:'.$name,
                'name' => $name,
                'url' => '/api/catalog-photo/'.$mirror,
                'size' => filesize($file),
            ];
        }

        // Newest storage uploads first, then the shipped set.
        usort($items, fn ($a, $b) => [$a['source'] === 'seed' ? 1 : 0, $b['name']] <=> [$b['source'] === 'seed' ? 1 : 0, $a['name']]);

        return response()->json($items);
    }

    /** Attach a library picture to a product. */
    public function fromLibrary(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:300'],
        ]);
        $key = $data['key'];

        if (str_starts_with($key, 'seed:')) {
            $file = database_path('seed-images/'.basename(substr($key, 5)));
            abort_unless(is_file($file) && $this->isImage($file), 404, 'That picture is not in the library.');
            $raw = (string) file_get_contents($file);
        } else {
            // Must be one of THIS company's files — never another tenant's.
            $dir = 'products/'.Tenant::id().'/';
            abort_unless(str_starts_with($key, $dir) && ! str_contains($key, '..'), 403, 'Not found.');
            $disk = Storage::disk(self::DISK)->exists($key) ? self::DISK
                : (Storage::disk('public')->exists($key) ? 'public' : null);
            abort_unless($disk !== null, 404, 'That picture is no longer on disk.');
            $raw = (string) Storage::disk($disk)->get($key);
        }

        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION)) ?: 'jpg';
        $this->store($product, $raw, $ext);

        ActivityLog::log('updated', 'Product', "Set photo for \"{$product->name}\" from the library");

        return response()->json($product->fresh());
    }

    private function isImage(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    /**
     * Write the bytes as this product's photo, exactly as they came in — no
     * re-encoding, no resizing — and only record the path once we can read it
     * back.
     */
    private function store(Product $product, string $raw, string $ext): void
    {
        $old = $product->image;

        $dir = 'products/'.Tenant::id();
        $path = $dir.'/p'.$product->id.'-'.time().'.'.$ext;
        Storage::disk(self::DISK)->makeDirectory($dir);
        Storage::disk(self::DISK)->put($path, $raw);

        // Never record a path we cannot read back. Two checks, deliberately:
        // Flysystem's own view, AND a raw filesystem check at the absolute
        // path the photo route will later stream from. They can disagree — a
        // wrong disk root, a permission problem swallowed by the disk's
        // `throw => false` — and when they do, the upload "succeeds" and
        // every image 404s afterwards. Fail here instead, loudly.
        $absolute = Storage::disk(self::DISK)->path($path);
        $written = Storage::disk(self::DISK)->exists($path) ? filesize($absolute) : false;

        abort_unless(is_file($absolute), 500, sprintf(
            'The image was not written to %s. The disk is rooted at %s — check that the folder exists and is writable.',
            $absolute, Storage::disk(self::DISK)->path('')
        ));
        abort_unless($written === strlen($raw), 500, sprintf(
            'The image at %s is %s bytes but should be %s — the write was truncated.',
            $absolute, var_export($written, true), strlen($raw)
        ));

        $product->update(['image' => $path]);

        // Only drop the previous file once the new one is safely in place, and
        // never if another product still points at it (library picks share).
        if ($old && $old !== $path && ! Product::where('image', $old)->exists()) {
            Storage::disk(self::DISK)->delete($old);
            Storage::disk('public')->delete($old);
        }
    }

    public function destroy(Product $product): JsonResponse
    {
        if ($product->image) {
            if (! Product::where('image', $product->image)->where('id', '!=', $product->id)->exists()) {
                Storage::disk(self::DISK)->delete($product->image);
                Storage::disk('public')->delete($product->image);
            }
            $product->update(['image' => null]);
        }

        return response()->json($product->fresh());
    }
}
