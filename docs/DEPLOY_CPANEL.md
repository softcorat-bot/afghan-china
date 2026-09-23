# Putting Afghan China MIS on cPanel with MySQL

This is the whole deployment, start to finish, for a normal cPanel shared-hosting
account. It assumes nothing except that you can log into cPanel.

The system is two pieces and they are uploaded to two different places:

| Piece | What it is | Where it goes |
| --- | --- | --- |
| **API** (`backend/`) | Laravel 13, talks to MySQL | its own subdomain, document root pointed at `public/` |
| **App** (`frontend/dist/spa/`) | the built Quasar SPA — plain HTML, CSS and JS | the main domain's `public_html/` |

The example below uses `shop.example.com` for the app and `api.example.com`
for the API. Substitute your own names throughout.

---

## Before you start: what the host must provide

Check these in cPanel under **Select PHP Version** (or **MultiPHP Manager**).

- **PHP 8.3 or newer.** Laravel 13 will not run on 8.2.
- **Extensions:** `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`,
  `ctype`, `json`, `bcmath`, `fileinfo`, `curl`, and **`gd`**. `gd` is the one
  people forget — without it every uploaded photo fails, because the app
  re-encodes each image down to under 1 MB before storing it.
- **MySQL 5.7+ / MariaDB 10.3+.**

Raise two PHP limits while you are in there (**Options** tab):

```
upload_max_filesize = 16M
post_max_size       = 20M
```

Photos are stored exactly as uploaded — nothing is resized or re-encoded, on
the browser side or the server side — so this is not optional. The stock 2M
ceiling rejects a normal phone photo outright (often 5–12 MB); `acsc:doctor`
will refuse to call the install healthy until these are raised.

---

## 1. Create the MySQL database

cPanel → **MySQL® Databases**.

1. **Create a database.** Name it `acsc`. cPanel silently prefixes it with your
   account name, so what you actually get is something like `myshop_acsc`.
   Write down the full name.
2. **Create a user.** Same story: `acscuser` becomes `myshop_acscuser`. Use the
   password generator and save the password somewhere safe.
3. **Add the user to the database** and tick **ALL PRIVILEGES**.

You need all three values — full database name, full username, password — in
the next step. The name you typed is not the name you use.

---

## 2. Upload the API

Put the `backend/` folder somewhere **outside** `public_html`, for example
`/home/youracct/acsc-api`. Nothing in it should be reachable by URL except the
`public/` folder, which the next step handles.

If your host gives you Terminal (cPanel → **Terminal**), the easy path is:

```bash
cd ~
git clone <your-repo-url> acsc-src
mv acsc-src/backend acsc-api
cd acsc-api
composer install --no-dev --optimize-autoloader
```

**No Terminal, or no Composer?** Run `composer install --no-dev
--optimize-autoloader` on your own computer first, then upload the whole
`backend/` folder *including* the `vendor/` directory. Zip it before uploading —
`vendor/` is thousands of small files and File Manager's unzip is far faster
than an FTP transfer of each one.

---

## 3. Point a subdomain at the API

cPanel → **Domains** → **Create A New Domain**.

- Domain: `api.example.com`
- Document Root: `/home/youracct/acsc-api/public`   ← **the `public` folder, not the folder above it**

That last line is the single most common mistake. If the document root is
`acsc-api` instead of `acsc-api/public`, your `.env` file — database password
and all — becomes downloadable over the web.

Verify it before going further: visit `https://api.example.com`. You should get
the Laravel welcome page. If you instead see a file listing with `app`,
`config`, `.env` in it, the document root is wrong. Fix it now.

Then issue an SSL certificate for the subdomain (cPanel → **SSL/TLS Status** →
**Run AutoSSL**). Browsers block a plain-HTTP API called from an HTTPS page, so
both names must be HTTPS.

---

## 4. Configure `.env`

Copy `.env.example` to `.env` and edit it. The settings that matter:

```dotenv
APP_NAME="Afghan China MIS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com

# The SPA's address. This is what the API allows to call it (CORS) — get it
# wrong and every request fails in the browser with a CORS error while curl
# works fine.
FRONTEND_URL=https://shop.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myshop_acsc
DB_USERNAME=myshop_acscuser
DB_PASSWORD=the-password-you-saved

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# Leave false for a real shop. Set true only if you want the sample products,
# sales and loyalty customers to play with.
SEED_DEMO_DATA=false
```

`APP_DEBUG=false` is not optional on a public host — with it on, any error page
prints your database credentials to whoever triggered it.

Then generate the encryption key and build the schema:

```bash
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
```

`--force` is required because `APP_ENV=production`; Laravel is asking whether
you really mean to touch a live database.

With `SEED_DEMO_DATA=false` you get the things the app cannot start without —
the company, the main branch, the permission grid, the accounts, the AFN/USD/CNY
rates, and a "Walk-in Customer" the POS rings anonymous sales against — plus a
small **sample catalog**: 5 categories, 15 products with photos, and one example
customer. That sample catalog exists so you have something to click through
while setting the shop up.

**No sales, expenses or loyalty history are created.** Your books start empty,
which is the part that matters.

When you are ready to enter real stock, delete the samples from **Products** —
select all, delete — and the same on **Customers** for `Ahmad Wali`. Keep
`Walk-in Customer`. Deleted rows go to **Trash**, so a mistake is recoverable.

### Photos need no extra step

Product photos are served **through the application** (`/api/catalog-photo/…`),
the same way its attachments are — not from `public/storage`. There is no
symlink to create and nothing for the web server to be configured for: if the
API answers, photos work. `php artisan storage:link` is not needed.

### Change the passwords. Do this now.

Every seeded account starts on the password `password`. On a laptop that is
convenient; on a public host it is an open door.

```bash
php artisan acsc:password admin@afghanchina.af
php artisan acsc:password support@briskcodes.com
```

It prompts twice, without echoing, so the password never reaches your shell
history. Repeat for every account listed by `php artisan acsc:password
someone@nowhere` (a bad address makes it print all of them).

### Check your work

```bash
php artisan acsc:doctor
```

This is the payoff for the whole section. It verifies the migrations ran, every
column the code writes exists, the PHP upload limits are big enough for a real
photo, photos genuinely write and read back from disk, and — in production —
that no account is still on the default password. Fix anything it flags before moving
on.

---

## 5. Build and upload the app

On your own computer:

```bash
cd frontend
pnpm install
pnpm build
```

This produces `frontend/dist/spa/`. Upload **the contents** of that folder into
`public_html/` for `shop.example.com` — the files themselves, not the `spa`
folder wrapped around them. `index.html` must sit directly in `public_html/`.

Then edit one file. Open `public_html/config.js` in cPanel's File Manager and
set your API address:

```js
window.__API_URL__ = 'https://api.example.com'
```

No trailing slash, and no `/api` on the end — the app appends that itself.

That file is deliberately not bundled, so **the API address can be changed later
without rebuilding anything.** If you move the API, or set the site up on a
staging domain first, you edit this one line in File Manager and reload.

No `.htaccess` and no rewrite rules are needed: the app uses hash-based routing
(`shop.example.com/#/products`) and relative asset paths, which also means it
works fine from a subfolder if you prefer `example.com/pos/`.

---

## 6. Confirm it works

1. Open `https://shop.example.com`. The login page should appear with the logo.
2. Log in as the administrator, using the new password you set in step 4.
3. Open **Products**. Photos should load — they come through the API, so if
   the page's data loads, its pictures load from the same place. If they
   somehow do not, run `php artisan acsc:doctor`.
4. Ring up a test sale in the POS and confirm the receipt prints.
5. Open the **Dashboard** and switch *Sales over time* through Day / Week /
   Month / Year. All four should draw. These are the queries that differ most
   between database engines, so if MySQL is unhappy, this is where it shows.

---

## When something goes wrong

| Symptom | Cause | Fix |
| --- | --- | --- |
| Browser console: `blocked by CORS policy` | `FRONTEND_URL` in `.env` doesn't exactly match the SPA's address (login shows *Cannot reach the server*) | Match it including `https://` and any `www.` — comma-separate several, e.g. `https://shop.example.com,https://www.shop.example.com` — then `php artisan optimize:clear` |
| Every API call fails, but the site loads | `config.js` points at the wrong address, or at `http://` while the page is `https://` | Fix `public_html/config.js` |
| `500` on every page, blank white screen | Missing `APP_KEY`, or `storage/` and `bootstrap/cache/` are not writable | `php artisan key:generate`; set both to `755` |
| `SQLSTATE[HY000] [1045] Access denied` | The database user was never added to the database, or you used the un-prefixed name | cPanel → MySQL® Databases → **Add User To Database**, ALL PRIVILEGES |
| `SQLSTATE[42000] ... key was too long` | Old MySQL (5.6) with a 767-byte index limit | Add `Schema::defaultStringLength(191);` to `boot()` in `app/Providers/AppServiceProvider.php`, then re-run `migrate:fresh --force` |
| Product images 404 | The photo files are not on the server (storage is never in git) | `php artisan db:seed --class=ProductImageSeeder --force`; then `php artisan acsc:doctor` |
| Uploading a photo fails on large files | `upload_max_filesize` too low | Raise it to 16M in **Select PHP Version → Options** |
| Changed `.env` but nothing changed | Config is cached | `php artisan optimize:clear` |
| Dashboard charts empty or erroring | Config cached from before the update | `php artisan optimize:clear`, then `php artisan acsc:doctor` |

When you are stuck, `php artisan acsc:doctor` is almost always the fastest way
to find out what is actually wrong. Its output is designed to be pasted to
whoever is helping you.

---

## Updating later

```bash
cd ~/acsc-api
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan acsc:doctor
```

Then rebuild the frontend and re-upload `dist/spa/`. **Keep your `config.js`** —
or re-apply your API address after uploading, since the fresh build ships with
it blank.

Take a database backup before any update: cPanel → **Backup** → *Download a
MySQL Database Backup*.
