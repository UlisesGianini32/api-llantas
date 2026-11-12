<?php

use App\Support\AutopartesConnectionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = AutopartesConnectionGuard::CONNECTION_NAME;

    public function up(): void
    {
        $conn = $this->getConnection() ?? AutopartesConnectionGuard::CONNECTION_NAME;
        AutopartesConnectionGuard::assertSafeForSchemaChange($conn);
        $schema = Schema::connection($conn);

        $schema->create('automotive_branches', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique('auto_branch_code_unique');
            $table->string('name', 191);
            $table->boolean('is_active')->default(true)->index('auto_branch_active_idx');
            $table->timestamps();
        });

        // user_id es referencia aplicativa a api_llantas.users (sin FK SQL entre bases).
        $schema->create('automotive_branch_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_bu_branch')
                ->restrictOnDelete();
            $table->unsignedBigInteger('user_id')->index('auto_branch_user_id_idx');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'user_id'], 'auto_branch_user_unique');
        });

        $schema->create('automotive_branch_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_user_id')
                ->constrained('automotive_branch_users', 'id', 'fk_ap_cap_bu')
                ->cascadeOnDelete();
            $table->string('capability', 80);
            $table->timestamps();

            $table->unique(['branch_user_id', 'capability'], 'auto_branch_cap_unique');
        });

        $schema->create('automotive_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('automotive_branches', 'id', 'fk_ap_loc_branch')
                ->restrictOnDelete();
            $table->string('code', 48)->unique('auto_loc_code_unique');
            $table->string('name', 191);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true)->index('auto_loc_active_idx');
            $table->timestamps();
        });

        // Provisionar únicamente la sucursal HMO y su ubicación inicial dentro de la BD exclusiva de Autopartes.
        $db = DB::connection($conn);
        $now = now();

        $hmo = $db->table('automotive_branches')->where('code', 'HMO')->first();
        if (! $hmo) {
            $branchId = $db->table('automotive_branches')->insertGetId([
                'code' => 'HMO',
                'name' => 'HERMOSILLO',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $branchId = $hmo->id;
        }

        if (! $db->table('automotive_locations')->where('code', 'HMO-REC-01')->exists()) {
            $db->table('automotive_locations')->insert([
                'branch_id' => $branchId,
                'code' => 'HMO-REC-01',
                'name' => 'HERMOSILLO RECEPCIÓN AUTOPARTES',
                'description' => 'Ubicación física principal de recepción y despacho de Autopartes Hermosillo',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $conn = $this->getConnection() ?? AutopartesConnectionGuard::CONNECTION_NAME;
        AutopartesConnectionGuard::assertSafeForSchemaChange($conn);
        $schema = Schema::connection($conn);

        $schema->dropIfExists('automotive_locations');
        $schema->dropIfExists('automotive_branch_capabilities');
        $schema->dropIfExists('automotive_branch_users');
        $schema->dropIfExists('automotive_branches');
    }
};
