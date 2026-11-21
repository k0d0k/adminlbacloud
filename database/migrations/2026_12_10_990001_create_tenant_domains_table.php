<?php

use App\Platform\Tenancy\Domain\TenantDomainResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase D (v3.3) — per-partner custom domain registry (CENTRAL data only).
     *
     * One row maps a single hostname to exactly ONE partner. The hostname is the
     * only thing stored (no scheme, port or path), lower-cased by the model, and
     * a database-level UNIQUE index keeps a host from ever pointing at two
     * partners at once.
     *
     * A row is NOT authoritative on its own: only `status = verified` with a
     * non-null `verified_at` makes the hostname load-bearing, and even then it
     * resolves ONLY to the partner's tenant id — it never grants a session,
     * membership or any data access (see {@see TenantDomainResolver}).
     *
     * The verification token is a random value the operator publishes in DNS so
     * ownership is proven out-of-band; it is central metadata, never a secret
     * that authenticates a user.
     */
    public function up(): void
    {
        if (Schema::hasTable('tenant_domains')) {
            return;
        }

        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('domain', 255)->unique();
            $table->string('status', 20)->default('pending');
            $table->string('verification_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
