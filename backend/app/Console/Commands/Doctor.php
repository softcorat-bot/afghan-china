<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `php artisan acsc:doctor` — one command that says exactly why the app is
 * unhappy: pending migrations, columns the code expects but the database
 * lacks, a missing storage link, product photos whose files vanished.
 * Run it whenever anything answers "Server Error".
 */
class Doctor extends Command
{
    protected $signature = 'acsc:doctor';

    protected $description = 'Diagnose schema/storage drift after an update';

    /** table => columns the current code writes */
    private array $expect = [
        'sales' => ['counter_id', 'shift_id', 'channel', 'refunded_amount'],
        'shifts' => ['counter_id'],
        'products' => ['warehouse_qty', 'main_price', 'wholesale_price', 'image'],
        'customers' => ['type', 'company_name', 'credit_limit', 'balance'],
        'users' => ['pin', 'basic_salary', 'is_super_admin'],
        'stock_adjustments' => ['location'],
        'stock_transfers' => ['source', 'destination'],
        'expenses' => ['spent_on', 'category', 'amount', 'method'],
        'companies' => ['receipt_settings', 'pos_settings'],
    ];

    private array $expectTables = [
        'counters', 'attendance_records', 'payroll_runs', 'payroll_items',
        'stock_transfers', 'main_price_logs', 'refunds', 'expenses',
    ];

    public function handle(): int
    {
        $this->line('');
        $this->info('  Afghan China MIS — doctor');
        $this->line('  '.str_repeat('─', 46));

        $problems = [];

        // 1. migrations
        try {
            $pending = collect(DB::table('migrations')->pluck('migration'));
            $files = collect(glob(database_path('migrations/*.php')))
                ->map(fn ($f) => basename($f, '.php'));
            $missing = $files->diff($pending);
            if ($missing->isNotEmpty()) {
                $problems[] = "{$missing->count()} migration(s) never ran — run: php artisan migrate";
                foreach ($missing as $m) {
                    $this->line("    <fg=yellow>pending</> {$m}");
                }
            } else {
                $this->line('    <fg=green>✔</> migrations up to date ('.$files->count().')');
            }
        } catch (\Throwable $e) {
            $problems[] = 'cannot read the migrations table: '.$e->getMessage();
        }

        // 2. tables the code needs
        foreach ($this->expectTables as $t) {
            if (! Schema::hasTable($t)) {
                $problems[] = "table `{$t}` is missing — run: php artisan migrate";
                $this->line("    <fg=red>✘</> table {$t} missing");
            }
        }

        // 3. columns the code writes
        foreach ($this->expect as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $have = Schema::getColumnListing($table);
            foreach ($cols as $c) {
                if (! in_array($c, $have, true)) {
                    $problems[] = "`{$table}.{$c}` is missing — run: php artisan migrate";
                    $this->line("    <fg=red>✘</> {$table}.{$c} missing");
                }
            }
        }
        if (empty($problems)) {
            $this->line('    <fg=green>✔</> every expected table and column exists');
        }

        // 4. Product photo files. Photos ride the API (`/api/catalog-photo/…`)
        //    from the private disk — the attachment approach — so no storage
        //    link matters; a photo is "present" if it is on the private disk
        //    or still on the legacy public disk from before the switch.
        if (Schema::hasTable('products')) {
            $missingFiles = 0;
            foreach (DB::table('products')->whereNotNull('image')->pluck('image') as $rel) {
                if (! is_file(storage_path('app/private/'.$rel)) && ! is_file(storage_path('app/public/'.$rel))) {
                    $missingFiles++;
                }
            }
            if ($missingFiles > 0) {
                $problems[] = "{$missingFiles} product photo file(s) missing — run: php artisan db:seed --class=ProductImageSeeder";
                $this->line("    <fg=yellow>!</> {$missingFiles} product photo file(s) not on disk");
            } else {
                $this->line('    <fg=green>✔</> every product photo file is on disk');
            }
        }

        // 5. PHP upload limits. Photos are stored exactly as the browser sends
        //    them — nothing is shrunk client-side or re-encoded server-side —
        //    so a normal phone photo (often 5–12 MB) has no safety net if
        //    these are left at PHP's stock defaults. This is a real problem,
        //    not a suggestion: below the line, uploading a real photo fails.
        $upload = $this->bytes(ini_get('upload_max_filesize'));
        $post = $this->bytes(ini_get('post_max_size'));
        if ($upload < 16 * 1024 * 1024 || $post < 20 * 1024 * 1024) {
            $ini = php_ini_loaded_file() ?: 'your php.ini';
            $problems[] = sprintf(
                'php.ini upload limits are too low for real photos (upload_max_filesize=%s, post_max_size=%s) — set upload_max_filesize=16M and post_max_size=20M in %s, then restart PHP',
                ini_get('upload_max_filesize'), ini_get('post_max_size'), $ini
            );
            $this->line(sprintf(
                '    <fg=red>✘</> php.ini upload limits too low (upload_max_filesize=%s, post_max_size=%s)',
                ini_get('upload_max_filesize'), ini_get('post_max_size')
            ));
            $this->line("        set upload_max_filesize=16M and post_max_size=20M in {$ini}, then restart PHP");
        } else {
            $this->line('    <fg=green>✔</> php.ini upload limits are generous enough for real photos');
        }

        // 6. The photo pipeline, end to end. Photos are written to the private
        //    disk and streamed by the /api/catalog-photo route — the same
        //    pipeline as every API call, which is the point: there is no
        //    symlink or web-server layer left to disagree with the app. The
        //    probe proves the private disk writes where the route reads.
        $probe = 'diagnostics/doctor-probe.txt';
        $absolute = storage_path('app/private/'.$probe);
        $root = rtrim(\Illuminate\Support\Facades\Storage::disk('local')->path(''), '/\\');
        \Illuminate\Support\Facades\Storage::disk('local')->put($probe, 'ok');

        if (! is_file($absolute)) {
            $problems[] = 'the private disk does not write where the app reads — every photo will 404';
            $this->line('    <fg=red>✘</> photo pipeline broken');
            $this->line("        the disk is rooted at  <fg=yellow>{$root}</>");
            $this->line("        but the app looks in   <fg=yellow>".dirname($absolute)."</>");
            $this->line('        fix the local disk root in config/filesystems.php, then run: php artisan optimize:clear');
        } else {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($probe);
            $this->line('    <fg=green>✔</> photos write and read back from '.$root);
        }

        // And that a recorded photo really resolves through the same lookup
        // the serving route uses.
        $sample = DB::table('products')->whereNotNull('image')->value('image');
        if ($sample) {
            $onPrivate = is_file(storage_path('app/private/'.$sample));
            $onLegacy = is_file(storage_path('app/public/'.$sample));
            if ($onPrivate || $onLegacy) {
                $this->line('    <fg=green>✔</> sample photo streams from /api/catalog-photo/'.$sample
                    .($onPrivate ? '' : ' (legacy public-disk file — still served)'));
            } else {
                $problems[] = "the photo recorded for a product is not on disk: {$sample}";
                $this->line('    <fg=red>✘</> recorded photo missing on disk');
                $this->line('        fix with: php artisan db:seed --class=ProductImageSeeder --force');
            }
        }

        // Seeded accounts all start on the password "password". That is fine on
        // a laptop and a break-in on a public host, so say so loudly once the
        // app is actually live rather than trusting the deployment guide to be
        // read. Checked in production only, and only against those accounts.
        if (app()->environment('production')) {
            $weak = collect(DB::table('users')->get(['email', 'password']))
                ->filter(fn ($u) => \Illuminate\Support\Facades\Hash::check('password', $u->password))
                ->pluck('email');

            if ($weak->isNotEmpty()) {
                $problems[] = $weak->count().' account(s) still use the default password — change them before going live';
                $this->line('    <fg=red>✘</> default password still set on: '.$weak->take(4)->implode(', ')
                    .($weak->count() > 4 ? ' …' : ''));
                $this->line('        fix with: php artisan acsc:password <email>');
            } else {
                $this->line('    <fg=green>✔</> no account is left on the default password');
            }
        }

        $this->line('  '.str_repeat('─', 46));
        if (empty($problems)) {
            $this->info('  Healthy — nothing to fix.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->error('  '.count($problems).' problem(s) found:');
        foreach ($problems as $p) {
            $this->line('   • '.$p);
        }
        $this->line('');
        $this->line('  Fix everything at once:  <fg=cyan>php artisan migrate && php artisan db:seed --class=ProductImageSeeder && php artisan optimize:clear</>');
        $this->line('');

        return self::FAILURE;
    }

    /** "16M" / "1G" / "512K" → bytes. */
    private function bytes(string $value): int
    {
        $value = trim($value);
        $n = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
