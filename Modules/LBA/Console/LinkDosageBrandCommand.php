<?php

namespace Modules\LBA\Console;

use App\Models\Brand;
use Illuminate\Console\Command;
use Modules\LBA\Dosage\DosageReferenceCatalog;

/**
 * Referensi Rumusan Dosis — link Brand/SKU(s) to a CENTRAL dosage master.
 *
 * NuBiom and Biodam reuse the SAME reference version as Babul Syiffa once their
 * product/SKU mapping is confirmed, so this binds several brand keys to one
 * version. It is idempotent: re-running never duplicates a link. A central
 * Brand row is matched by slug when present; otherwise the stable logical
 * `brand_key` still records the link.
 */
class LinkDosageBrandCommand extends Command
{
    protected $signature = 'lba:dosage:link-brand
        {--key=lba-dosage-babul-syiffa : Master key whose current version the brands reuse}
        {--brand-key=* : Brand/SKU keys to link (default: babul-syiffa nubiom biodam)}
        {--dry-run : Report what would be linked and write nothing}';

    protected $description = 'Link Brand/SKU key(s) to the current Referensi Rumusan Dosis master version (idempotent)';

    public function handle(DosageReferenceCatalog $catalog): int
    {
        $key = (string) $this->option('key');
        $dryRun = (bool) $this->option('dry-run');

        $brandKeys = array_values(array_filter((array) $this->option('brand-key')));

        if ($brandKeys === []) {
            $brandKeys = ['babul-syiffa', 'nubiom', 'biodam'];
        }

        foreach ($brandKeys as $brandKey) {
            $brandId = Brand::query()->where('slug', $brandKey)->value('id');
            $brandId = $brandId !== null ? (string) $brandId : null;

            $result = $catalog->linkBrand($key, $brandKey, $brandId, $dryRun);

            $prefix = $dryRun ? '[dry-run] ' : '';

            if (! $result['linked']) {
                $this->warn("{$prefix}No reference version for key [{$key}] — nothing to link for brand [{$brandKey}].");

                continue;
            }

            $state = $result['created'] ? 'linked' : 'already linked';
            $brand = $brandId ?? '—';
            $this->info("{$prefix}{$state} brand [{$brandKey}] (brand_id: {$brand}) to [{$key}] v{$result['version']}.");
        }

        if ($dryRun) {
            $this->comment('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }
}
