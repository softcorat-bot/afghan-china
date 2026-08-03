<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Support\Tenant;
use Illuminate\Database\Seeder;

class CatalogDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::withoutGlobalScopes()->where('is_main', true)->first()
            ?? Company::withoutGlobalScopes()->first();
        if (! $company) {
            return;
        }
        Tenant::set($company->id);

        $cats = [];
        foreach ([
            ['Grocery', 'خواروبار', 'shopping_basket'],
            ['Beverages', 'نوشیدنی‌ها', 'local_drink'],
            ['Electronics', 'الکترونیک', 'devices'],
            ['Household', 'لوازم خانه', 'home'],
            ['Stationery', 'قرطاسیه', 'edit'],
        ] as $i => [$en, $fa]) {
            $cats[$en] = ProductCategory::firstOrCreate(
                ['company_id' => $company->id, 'name' => $en],
                ['name_fa' => $fa, 'sort' => $i, 'active' => true]
            );
        }

        // barcode, name, name_fa, category, cost, sale, compare_at, stock, tax
        $products = [
            ['6001240001', 'Rice 5kg Bag', 'برنج ۵ کیلویی', 'Grocery', 380, 450, 500, 60, 0],
            ['6001240002', 'Cooking Oil 1L', 'روغن یک لیتری', 'Grocery', 90, 120, null, 120, 0],
            ['6001240003', 'Sugar 1kg', 'بوره یک کیلو', 'Grocery', 45, 60, null, 200, 0],
            ['6001240004', 'Green Tea 500g', 'چای سبز', 'Beverages', 110, 150, 180, 80, 0],
            ['6001240005', 'Cola 1.5L', 'کولا', 'Beverages', 35, 55, null, 150, 5],
            ['6001240006', 'Mineral Water 1.5L', 'آب معدنی', 'Beverages', 10, 20, null, 300, 0],
            ['6001240007', 'USB-C Cable', 'کیبل شارژر', 'Electronics', 60, 130, 160, 40, 5],
            ['6001240008', 'LED Bulb 9W', 'لامپ ال‌ای‌دی', 'Electronics', 40, 80, null, 90, 5],
            ['6001240009', 'Power Bank 10000mAh', 'پاور بانک', 'Electronics', 420, 650, 750, 25, 5],
            ['6001240010', 'Dish Soap 500ml', 'مایع ظرفشویی', 'Household', 55, 85, null, 70, 0],
            ['6001240011', 'Laundry Powder 2kg', 'پودر لباسشویی', 'Household', 160, 220, 250, 45, 0],
            ['6001240012', 'Notebook A4', 'کتابچه', 'Stationery', 25, 45, null, 160, 0],
            ['6001240013', 'Ballpoint Pen', 'قلم', 'Stationery', 5, 12, null, 500, 0],
            ['6001240014', 'Instant Noodles', 'نودل', 'Grocery', 12, 20, null, 8, 0],
            ['6001240015', 'Chocolate Bar', 'چاکلیت', 'Grocery', 20, 35, null, 220, 0],
        ];

        foreach ($products as [$barcode, $name, $nameFa, $cat, $cost, $sale, $cmp, $stock, $tax]) {
            $sku = 'SKU-'.substr($barcode, -4);
            Product::firstOrCreate(
                ['company_id' => $company->id, 'sku' => $sku],
                [
                    'name' => $name,
                    'name_fa' => $nameFa,
                    'barcode' => $barcode,
                    'category_id' => $cats[$cat]->id,
                    'unit' => 'pcs',
                    'status' => 'active',
                    'cost_price' => $cost,
                    'sale_price' => $sale,
                    'compare_at_price' => $cmp,
                    'tax_rate' => $tax,
                    'track_inventory' => true,
                    'stock_qty' => $stock,
                    'warehouse_qty' => $stock * 3,
                    'min_stock' => 10,
                    'active' => true,
                ]
            );
        }

        // Existing rows (firstOrCreate skips them): give any empty warehouse a
        // demo reserve so the transfer flow has something to move.
        Product::where('warehouse_qty', 0)->where('track_inventory', true)
            ->update(['warehouse_qty' => \Illuminate\Support\Facades\DB::raw('stock_qty * 3')]);

        Customer::firstOrCreate(
            ['company_id' => $company->id, 'phone' => '0700000001'],
            ['name' => 'Walk-in Customer', 'active' => true]
        );
        Customer::firstOrCreate(
            ['company_id' => $company->id, 'phone' => '0700123456'],
            ['name' => 'Ahmad Wali', 'active' => true]
        );

        foreach ([
            ['Kabul Wholesale Traders', '0788111222'],
            ['Herat Import Co.', '0799333444'],
            ['China Town Distributors', '0700555666'],
        ] as [$name, $phone]) {
            Supplier::firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['phone' => $phone, 'active' => true]
            );
        }

        Tenant::clear();
    }
}
