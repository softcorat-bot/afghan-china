# Real product photos (drop-in)

Put real product photos in this folder and run:

    php artisan db:seed --class=ProductImageSeeder

Naming — one image per product, matched in this order:

    {barcode}.jpg      e.g. 6001240001.jpg
    {sku}.jpg          e.g. SKU-0005.png
    {id}.jpg           e.g. 12.webp

Supported formats: jpg / jpeg / png / webp. Any size — every photo is
automatically optimized (max 1280px, progressive JPEG, under 1 MB)
before it is attached. A real photo always replaces the generated
placeholder; photos uploaded by hand in the app are never overwritten.
