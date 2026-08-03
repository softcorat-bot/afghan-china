<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bulk product import. The client parses the CSV and posts row objects here;
 * each row is upserted by barcode, then SKU, then created. Categories are
 * resolved by name (created on demand). Bad rows are reported, not fatal.
 */
class ProductImportController extends Controller
{
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
        ]);

        $companyId = Tenant::id();
        $catCache = [];
        $created = 0;
        $updated = 0;
        $photos = 0;
        $imageErrors = 0;
        $errors = [];

        foreach ($data['rows'] as $i => $row) {
            $line = $i + 1;
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = ['row' => $line, 'message' => 'Missing name'];
                continue;
            }

            try {
                $categoryId = null;
                $catName = trim((string) ($row['category'] ?? ''));
                if ($catName !== '') {
                    $key = mb_strtolower($catName);
                    if (! isset($catCache[$key])) {
                        $catCache[$key] = ProductCategory::firstOrCreate(
                            ['company_id' => $companyId, 'name' => $catName],
                            ['active' => true]
                        )->id;
                    }
                    $categoryId = $catCache[$key];
                }

                $attrs = [
                    'name' => $name,
                    'name_fa' => $this->str($row, 'name_fa'),
                    'sku' => $this->str($row, 'sku'),
                    'barcode' => $this->str($row, 'barcode'),
                    'category_id' => $categoryId,
                    'brand' => $this->str($row, 'brand'),
                    'unit' => $this->str($row, 'unit') ?: 'pcs',
                    'status' => in_array(($row['status'] ?? 'active'), ['active', 'draft', 'archived'], true) ? ($row['status'] ?? 'active') : 'active',
                    'cost_price' => $this->num($row, 'cost_price'),
                    'sale_price' => $this->num($row, 'sale_price'),
                    'tax_rate' => $this->num($row, 'tax_rate'),
                    'track_inventory' => array_key_exists('track_inventory', $row) ? (bool) $row['track_inventory'] : true,
                    'stock_qty' => $this->num($row, 'stock_qty'),
                    'min_stock' => $this->num($row, 'min_stock'),
                ];

                $barcode = $attrs['barcode'];
                $sku = $attrs['sku'];
                $existing = null;
                if ($barcode) {
                    $existing = Product::where('barcode', $barcode)->first();
                }
                if (! $existing && $sku) {
                    $existing = Product::where('sku', $sku)->first();
                }

                if ($existing) {
                    $existing->update(array_filter($attrs, fn ($v) => $v !== null && $v !== ''));
                    $product = $existing;
                    $updated++;
                } else {
                    $product = Product::create(array_merge(['active' => true], $attrs));
                    $created++;
                }

                // A photo link in the sheet is fetched and attached. A bad URL
                // is reported against its row — it never fails the import,
                // because the product data itself is already saved.
                $imageUrl = $this->str($row, 'image_url');
                if ($imageUrl) {
                    $problem = $this->attachImageFromUrl($product, $imageUrl);
                    if ($problem) {
                        $errors[] = ['row' => $line, 'message' => 'Photo: '.$problem];
                        $imageErrors++;
                    } else {
                        $photos++;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => $line, 'message' => $e->getMessage()];
            }
        }

        $note = "Imported products (+{$created} new, {$updated} updated";
        $note .= $photos ? ", {$photos} photo(s))" : ')';
        ActivityLog::log('created', 'Product', $note);

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'photos' => $photos,
            'photo_errors' => $imageErrors,
            'errors' => $errors,
            'total' => count($data['rows']),
        ]);
    }

    private function str(array $row, string $k): ?string
    {
        $v = $row[$k] ?? null;

        return $v === null ? null : trim((string) $v);
    }

    private function num(array $row, string $k): float
    {
        return (float) ($row[$k] ?? 0);
    }

    /**
     * Download a photo named in the sheet and attach it to the product,
     * stored exactly as downloaded. Returns null on success, or a short
     * reason to report against the row.
     *
     * Deliberately conservative: http/https only, no redirects to private
     * addresses, a hard size cap and a short timeout, because the URLs come
     * from a spreadsheet somebody else wrote.
     */
    private function attachImageFromUrl(Product $product, string $url): ?string
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return 'not an http(s) link';
        }
        // Never let a sheet make the server fetch its own private network.
        // If the host does not resolve here we let the request itself fail
        // rather than guessing — only a confirmed private address is refused.
        $host = $parts['host'] ?? '';
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP) &&
            ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'that address is not allowed';
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(12)
                ->withOptions(['allow_redirects' => ['max' => 3, 'strict' => true]])
                ->get($url);
        } catch (\Throwable $e) {
            return 'could not be downloaded';
        }

        if (! $response->successful()) {
            return 'the link returned HTTP '.$response->status();
        }

        $raw = $response->body();
        if ($raw === '' || strlen($raw) > 25 * 1024 * 1024) {
            return strlen($raw) === 0 ? 'the link returned nothing' : 'the file is larger than 25 MB';
        }

        // getimagesizefromstring() parses the file's own header — it needs no
        // GD extension — so this rejects a link that returned an HTML error
        // page or some other non-image body before it is ever saved as a
        // product photo, stored exactly as downloaded, no re-encoding.
        if (@getimagesizefromstring($raw) === false) {
            return 'that file is not an image';
        }
        $ext = $this->extensionFor($response->header('Content-Type'), $url);

        if ($product->image) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($product->image);
            \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image);
        }

        // Private disk, served by the catalog-photo route — the attachment
        // approach; nothing here depends on storage:link or the web server.
        $dir = 'products/'.Tenant::id();
        $path = $dir.'/p'.$product->id.'-'.time().'.'.$ext;
        \Illuminate\Support\Facades\Storage::disk('local')->makeDirectory($dir);
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $raw);

        if (! \Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
            return 'could not be written to disk — check folder permissions';
        }

        $product->update(['image' => $path]);

        return null;
    }

    /**
     * A real file extension for the downloaded bytes — never the literal
     * ".img" a previous version of this fell back to, which no browser maps
     * to an image type, so the photo saved fine and then never rendered.
     * The Content-Type header is trusted first since it describes the bytes
     * actually received; the URL's own extension is a fallback for a server
     * that did not send one.
     */
    private function extensionFor(?string $contentType, string $url): string
    {
        $byMime = [
            'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
            'image/webp' => 'webp', 'image/gif' => 'gif',
        ];
        $mime = strtolower(trim(explode(';', $contentType ?? '')[0]));
        if (isset($byMime[$mime])) {
            return $byMime[$mime];
        }

        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $ext : 'jpg';
    }
}
