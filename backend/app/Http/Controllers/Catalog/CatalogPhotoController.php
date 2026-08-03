<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves every catalog photo THROUGH the application — the same way the
 * attachment system (ported from the construction ERP) serves its files.
 *
 * This exists because the previous design served photos as static files from
 * `public/storage`, and that path depends on everything the API does not: a
 * symlink `storage:link` may not be allowed to create, a document root set
 * exactly right, a web server willing to serve the directory. Any one of
 * those being off produces the bug reported over and over — uploads succeed,
 * then every image 404s — and which one is off differs per machine.
 *
 * A route has none of those dependencies. If `/api/login` works, this works,
 * because it is the same pipeline end to end. That is the whole design.
 */
class CatalogPhotoController extends Controller
{
    /** The only directories this route will ever serve. */
    private const ROOTS = ['products', 'library'];

    public function show(string $path): BinaryFileResponse
    {
        abort_if(str_contains($path, '..'), 404);
        abort_unless(collect(self::ROOTS)->contains(fn ($r) => str_starts_with($path, $r.'/')), 404);

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        abort_unless(in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true), 404);

        // New home first (private disk), then the old public disk, so photos
        // uploaded before this change keep working without a migration step.
        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return response()->file(Storage::disk($disk)->path($path), [
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }

        abort(404);
    }
}
