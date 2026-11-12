<?php

use App\Support\AutopartesConnectionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = AutopartesConnectionGuard::CONNECTION_NAME;

    public function up(): void
    {
        $conn = $this->getConnection() ?? AutopartesConnectionGuard::CONNECTION_NAME;
        AutopartesConnectionGuard::assertSafeForSchemaChange($conn);
        $schema = Schema::connection($conn);

        $schema->create('automotive_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue', 191)->index('auto_jobs_queue_idx');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');

            $table->index(['queue', 'reserved_at'], 'auto_jobs_queue_reserved_idx');
        });

        $schema->create('automotive_job_batches', function (Blueprint $table): void {
            $table->string('id', 191)->primary('auto_job_batches_primary');
            $table->string('name', 191);
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        $schema->create('automotive_failed_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('uuid', 191)->unique('auto_failed_jobs_uuid_unique');
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        $conn = $this->getConnection() ?? AutopartesConnectionGuard::CONNECTION_NAME;
        AutopartesConnectionGuard::assertSafeForSchemaChange($conn);
        $schema = Schema::connection($conn);

        $schema->dropIfExists('automotive_failed_jobs');
        $schema->dropIfExists('automotive_job_batches');
        $schema->dropIfExists('automotive_jobs');
    }
};
