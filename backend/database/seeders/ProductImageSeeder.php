<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Product photo seeding, two tiers:
 *
 * 1. REAL photos — drop image files into `database/seed-images/` named by
 *    barcode, SKU, or product id (e.g. 6001240001.jpg, SKU-0005.png).
 *    Re-running this seeder picks them up and attaches them exactly as
 *    dropped in — replacing any generated placeholder.
 *
 * 2. Generated packshots — for products with no real photo yet, a clean
 *    studio-style mockup (light backdrop, soft shadow, category-coloured
 *    package with the product's initials and name) so the catalog, POS
 *    grid and dashboards never show empty tiles.
 */
class ProductImageSeeder extends Seeder
{
    /** category name => [rgb start, rgb end] for the package body */
    private array $palette = [
        'Grocery'     => [[67, 160, 71],  [27, 94, 32]],
        'Beverages'   => [[41, 121, 255], [13, 71, 161]],
        'Electronics' => [[126, 87, 194], [49, 27, 146]],
        'Household'   => [[255, 112, 67], [191, 54, 12]],
        'Stationery'  => [[38, 166, 154], [0, 77, 64]],
    ];

    public function run(): void
    {
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        $hasTtf = function_exists('imagettftext') && is_file($font);
        $cats = ProductCategory::withoutGlobalScopes()->get()->keyBy('id');

        foreach (Product::withoutGlobalScopes()->get() as $p) {
            $real = $this->findRealPhoto($p);

            if ($real !== null) {
                // A real photo always wins — including over a generated tile.
                $isGenerated = $p->image === null || str_contains((string) $p->image, '/seed-');
                if ($isGenerated) {
                    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION)) ?: 'jpg';
                    $dir = 'products/'.$p->company_id;
                    $path = $dir.'/seed-real-'.$p->id.'.'.$ext;
                    Storage::disk('local')->makeDirectory($dir);
                    Storage::disk('local')->put($path, file_get_contents($real));
                    $p->image = $path;
                    $p->save();
                }

                continue;
            }

            // A DB path whose file is missing on THIS machine (storage is not
            // in git) counts as no image — regenerate instead of 404ing.
            if ($p->image !== null && (Storage::disk('local')->exists($p->image)
                || Storage::disk('public')->exists($p->image))) {
                continue; // keeps real, existing uploads untouched
            }

            $this->generatePackshot($p, $cats, $font, $hasTtf);
        }
    }

    /** Look for a drop-in real photo named by barcode, SKU, or id. */
    private function findRealPhoto(Product $p): ?string
    {
        $dir = database_path('seed-images');
        foreach ([$p->barcode, $p->sku, (string) $p->id] as $key) {
            if (! $key) {
                continue;
            }
            foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
                $file = $dir.'/'.$key.'.'.$ext;
                if (is_file($file)) {
                    return $file;
                }
            }
        }

        return null;
    }

    private function generatePackshot(Product $p, $cats, string $font, bool $hasTtf): void
    {
        $catName = $cats[$p->category_id]->name ?? 'Grocery';
        [$c1, $c2] = $this->palette[$catName] ?? [[84, 110, 122], [38, 50, 56]];

        $w = 640; $h = 480;
        $img = imagecreatetruecolor($w, $h);

        // Studio backdrop: near-white falling to soft grey.
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $v = (int) (250 - 14 * $t);
            imageline($img, 0, $y, $w, $y, imagecolorallocate($img, $v, $v + 1, min(255, $v + 3)));
        }

        // Soft floor shadow under the package.
        for ($i = 5; $i >= 1; $i--) {
            $sh = imagecolorallocatealpha($img, 40, 50, 60, 96 + $i * 5);
            imagefilledellipse($img, (int) ($w / 2), 398, 300 + $i * 16, 40 + $i * 4, $sh);
        }

        // The package: rounded-corner box with a vertical colour gradient.
        $bx1 = 190; $by1 = 78; $bx2 = 450; $by2 = 388; $r = 26;
        for ($y = $by1; $y <= $by2; $y++) {
            $t = ($y - $by1) / ($by2 - $by1);
            $col = imagecolorallocate($img,
                (int) ($c1[0] + ($c2[0] - $c1[0]) * $t),
                (int) ($c1[1] + ($c2[1] - $c1[1]) * $t),
                (int) ($c1[2] + ($c2[2] - $c1[2]) * $t));
            $inset = 0;
            if ($y < $by1 + $r) {
                $dy = $by1 + $r - $y;
                $inset = $r - (int) sqrt(max(0, $r * $r - $dy * $dy));
            } elseif ($y > $by2 - $r) {
                $dy = $y - ($by2 - $r);
                $inset = $r - (int) sqrt(max(0, $r * $r - $dy * $dy));
            }
            imageline($img, $bx1 + $inset, $y, $bx2 - $inset, $y, $col);
        }

        // Glossy highlight down the left of the package.
        $gloss = imagecolorallocatealpha($img, 255, 255, 255, 100);
        imagefilledrectangle($img, $bx1 + 14, $by1 + 16, $bx1 + 44, $by2 - 16, $gloss);

        // White label band with the initials.
        $label = imagecolorallocatealpha($img, 255, 255, 255, 8);
        imagefilledrectangle($img, $bx1 + 24, 188, $bx2 - 24, 288, $label);

        $words = preg_split('/\s+/', trim($p->name));
        $initials = strtoupper(mb_substr($words[0], 0, 1).(isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));
        $ink = imagecolorallocate($img, $c2[0], $c2[1], $c2[2]);
        $grey = imagecolorallocate($img, 84, 98, 112);
        $white = imagecolorallocate($img, 255, 255, 255);

        if ($hasTtf) {
            $size = 64;
            $box = imagettfbbox($size, 0, $font, $initials);
            imagettftext($img, $size, 0, (int) (($w - ($box[2] - $box[0])) / 2), 262, $ink, $font, $initials);

            // Category tag on the package top.
            $tag = strtoupper($catName);
            $box2 = imagettfbbox(13, 0, $font, $tag);
            imagettftext($img, 13, 0, (int) (($w - ($box2[2] - $box2[0])) / 2), $by1 + 44, $white, $font, $tag);

            // Product name under the package, dark on the backdrop.
            $name = mb_strimwidth($p->name, 0, 30, '…');
            $box3 = imagettfbbox(19, 0, $font, $name);
            imagettftext($img, 19, 0, (int) (($w - ($box3[2] - $box3[0])) / 2), $h - 26, $grey, $font, $name);
        } else {
            imagestring($img, 5, (int) ($w / 2 - 12), 230, $initials, $ink);
        }

        $dir = 'products/'.$p->company_id;
        $path = $dir.'/seed-'.$p->id.'.jpg';
        Storage::disk('local')->makeDirectory($dir);
        ob_start();
        imagejpeg($img, null, 88);
        Storage::disk('local')->put($path, ob_get_clean());
        imagedestroy($img);

        $p->image = $path;
        $p->save();
    }
}
