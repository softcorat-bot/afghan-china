<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\ExpiryTrackedCategory;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ExpiryTrackedCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        // Get all companies
        $companies = Company::all();

        // Categories that require expiry tracking
        $trackedCategories = [
            'Medicine' => 60,
            'Food' => 14,
            'Beverages' => 30,
            'Cosmetics' => 30,
            'Dairy' => 7,
            'Frozen Foods' => 30,
            'Condiments' => 30,
            'Vitamins' => 60,
            'Shampoo' => 24,
            'Lotion' => 24,
        ];

        foreach ($companies as $company) {
            // Find matching categories
            foreach ($trackedCategories as $categoryName => $warningDays) {
                $category = ProductCategory::where('company_id', $company->id)
                    ->where('name', $categoryName)
                    ->first();

                if ($category) {
                    ExpiryTrackedCategory::updateOrCreate(
                        [
                            'company_id' => $company->id,
                            'category_id' => $category->id,
                        ],
                        [
                            'require_expiry' => true,
                            'warning_days' => $warningDays,
                        ]
                    );
                }
            }
        }
    }
}
