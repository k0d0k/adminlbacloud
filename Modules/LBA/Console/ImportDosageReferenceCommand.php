<?php

namespace Modules\LBA\Console;

use App\Models\Brand;
use Illuminate\Console\Command;
use Modules\LBA\Dosage\DosageReferenceCatalog;

/**
 * Referensi Rumusan Dosis — provision the CENTRAL dosage-reference master.
 *
 * Imports the Lampiran A source (226 numbered entries, four phase values each,
 * 226 `Tambahan` notes) into a versioned central master. Idempotent: an
 * unchanged file is a no-op; a changed file appends a NEW version. Supports a
 * `--dry-run` that reports the counts (226 entries / 904 phase values / 226
 * `Tambahan`) without writing anything.
 *
 * The imported version starts as DRAFT (the source is a DRAF INTERNAL master)
 * unless `--activate` confirms it. The same reference version may back more than
 * one brand/SKU once its product mapping is confirmed (`--brand-key`).
 */
class ImportDosageReferenceCommand extends Command
{
    protected $signature = 'lba:dosage:import-reference
        {--file= : Absolute path to the Lampiran A source file (default: repo root markdown)}
        {--key=lba-dosage-babul-syiffa : Stable master key for this reference}
        {--label= : Human label for the reference version}
        {--brand= : Central brand id or slug to link this version to}
        {--brand-key= : Logical brand/SKU key (e.g. babul-syiffa, nubiom, biodam)}
        {--activate : Mark the imported version active instead of draft}
        {--dry-run : Report counts and write nothing}';

    protected $description = 'Import the central Referensi Rumusan Dosis master from the Lampiran A source (idempotent)';

    public function handle(DosageReferenceCatalog $catalog): int
    {
        $file = (string) ($this->option('file') ?: base_path('Formulir_LBA_dan_Lampiran_A_Babul_Syiffa.md'));

        if (! is_file($file)) {
            $this->error("Source file not found: {$file}");

            return self::FAILURE;
        }

        $key = (string) $this->option('key');
        $brandId = null;

        if ($brand = $this->option('brand')) {
            $brandId = (string) (Brand::query()->whereKey($brand)->value('id')
                ?? Brand::query()->where('slug', $brand)->value('id'));

            if ($brandId === '') {
                $this->error("Brand not found: {$brand}");

                return self::FAILURE;
            }
        }

        $result = $catalog->importFromFile($file, [
            'key' => $key,
            'label' => $this->option('label') ?: 'Referensi Rumusan Dosis '.$key,
            'brand_id' => $brandId,
            'brand_key' => $this->option('brand-key') ?: null,
            'source_file' => basename($file),
            'dry_run' => (bool) $this->option('dry-run'),
            'activate' => (bool) $this->option('activate'),
        ]);

        $prefix = $result['dry_run'] ? '[dry-run] ' : '';

        if ($result['unchanged']) {
            $this->info("{$prefix}Reference [{$result['key']}] v{$result['version']} already imported (checksum unchanged).");
        } else {
            $verb = $result['dry_run'] ? 'would import' : 'imported';
            $this->info("{$prefix}{$verb} reference [{$result['key']}] v{$result['version']}.");
        }

        $this->line('  entries:       '.$result['entries']);
        $this->line('  phase values:  '.$result['phase_values']);
        $this->line('  tambahan:      '.$result['tambahan']);
        $this->line('  checksum:      '.$result['checksum']);
        $this->line('  status:        '.($this->option('activate') ? 'active' : 'draft'));

        if ($result['dry_run']) {
            $this->comment('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }
}
