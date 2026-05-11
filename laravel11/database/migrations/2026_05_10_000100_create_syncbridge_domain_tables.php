<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('ps_id')->unique();
            $table->string('ay_style_key', 120)->nullable()->index();
            $table->string('reference', 120)->nullable();
            $table->string('name', 512)->default('');
            $table->text('description')->nullable();
            $table->text('description_short')->nullable();
            $table->string('export_title', 512)->nullable();
            $table->text('export_description')->nullable();
            $table->text('export_material_composition')->nullable();
            $table->longText('ps_api_payload')->nullable();
            $table->longText('ay_manual_required_attributes_json')->nullable();
            $table->longText('ay_missing_payload_json')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('weight', 8, 3)->default(0);
            $table->string('ean13', 20)->nullable();
            $table->unsignedInteger('category_ps_id')->nullable();
            $table->string('category_name', 255)->nullable();
            $table->unsignedInteger('ay_category_id')->nullable();
            $table->unsignedInteger('ay_brand_id')->nullable();
            $table->char('country_of_origin', 2)->nullable()->default('DE');
            $table->boolean('active')->default(true);
            $table->enum('sync_status', ['pending', 'syncing', 'synced', 'error', 'quarantined'])->default('pending')->index();
            $table->text('sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('ps_updated_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('ps_combo_id')->default(0);
            $table->string('sku', 160)->index();
            $table->string('ean13', 20)->nullable();
            $table->string('reference', 120)->nullable();
            $table->decimal('price_modifier', 10, 2)->default(0);
            $table->decimal('weight', 8, 3)->default(0);
            $table->integer('quantity')->default(0);
            $table->unsignedInteger('color_id')->nullable();
            $table->unsignedInteger('size_id')->nullable();
            $table->boolean('ay_pushed')->default(false);
            $table->unique(['product_id', 'ps_combo_id']);
            $table->timestamps();
        });

        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('ps_image_id', 60)->nullable();
            $table->string('source_url', 2048);
            $table->string('local_path', 512)->nullable();
            $table->string('public_url', 2048)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('file_size_bytes')->nullable();
            $table->enum('status', ['pending', 'processing', 'ok', 'error'])->default('pending')->index();
            $table->string('error_message', 512)->nullable();
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('ay_order_id', 120)->unique();
            $table->unsignedInteger('ps_order_id')->nullable()->index();
            $table->string('customer_email')->nullable();
            $table->string('customer_name')->nullable();
            $table->decimal('total_paid', 10, 2)->nullable();
            $table->decimal('total_products', 10, 2)->nullable();
            $table->decimal('total_shipping', 10, 2)->nullable();
            $table->decimal('discount_total', 10, 2)->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->char('shipping_country_iso', 2)->nullable();
            $table->char('billing_country_iso', 2)->nullable();
            $table->string('shipping_method', 120)->nullable();
            $table->string('payment_method', 120)->nullable();
            $table->longText('shipping_address_json')->nullable();
            $table->longText('billing_address_json')->nullable();
            $table->string('ay_status', 60)->nullable()->index();
            $table->unsignedTinyInteger('ps_state_id')->nullable();
            $table->enum('sync_status', ['pending', 'importing', 'imported', 'status_pushed', 'error', 'quarantined'])->default('pending')->index();
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->boolean('is_permanent_failure')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('ay_created_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedInteger('ay_order_item_id')->nullable();
            $table->string('sku', 160)->nullable()->index();
            $table->string('ean13', 20)->nullable()->index();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedInteger('combo_id')->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('item_status', 60)->nullable();
            $table->unique(['order_id', 'ay_order_item_id']);
        });

        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->char('run_id', 16)->unique();
            $table->string('command', 60)->index();
            $table->enum('status', ['running', 'completed', 'failed'])->default('running')->index();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('done_items')->default(0);
            $table->unsignedInteger('pushed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('current_product_id')->nullable();
            $table->string('current_phase', 120)->nullable();
            $table->string('last_message', 512)->nullable();
            $table->timestamp('started_at')->useCurrent()->index();
            $table->timestamp('finished_at')->nullable();
            $table->decimal('elapsed_sec', 8, 2)->nullable();
        });

        Schema::create('sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->char('run_id', 16)->nullable()->index();
            $table->enum('level', ['debug', 'info', 'notice', 'warning', 'error', 'critical'])->default('info')->index();
            $table->string('channel', 40)->default('sync')->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('sync_metrics', function (Blueprint $table): void {
            $table->id();
            $table->char('run_id', 16)->nullable()->index();
            $table->string('command', 60);
            $table->string('phase', 60)->default('run');
            $table->string('metric_key', 80)->index();
            $table->double('metric_value');
            $table->json('meta_json')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('ay_policy_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80)->default('mcp_docs');
            $table->string('version_tag', 80)->nullable();
            $table->json('payload_json');
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('attribute_maps', function (Blueprint $table): void {
            $table->id();
            $table->enum('map_type', ['color', 'size', 'second_size', 'attribute', 'attribute_required']);
            $table->string('ps_label', 120);
            $table->unsignedInteger('ay_group_id')->default(0);
            $table->string('ay_group_name', 120)->nullable();
            $table->unsignedInteger('ay_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['map_type', 'ps_label', 'ay_group_id']);
        });

        Schema::create('material_component_maps', function (Blueprint $table): void {
            $table->id();
            $table->string('ps_label', 120);
            $table->unsignedInteger('ay_material_id');
            $table->string('ay_material_label', 120)->nullable();
            $table->boolean('is_textile')->default(true);
            $table->timestamps();
            $table->unique(['ps_label', 'is_textile']);
            $table->index('ay_material_label');
        });

        Schema::create('material_cluster_maps', function (Blueprint $table): void {
            $table->id();
            $table->string('ps_label', 120)->unique();
            $table->unsignedInteger('ay_cluster_id');
            $table->string('ay_cluster_label', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('ay_required_group_defaults', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('ay_category_id');
            $table->unsignedInteger('ay_group_id')->index();
            $table->string('ay_group_name', 120)->nullable();
            $table->unsignedInteger('default_ay_id');
            $table->string('default_label', 160)->nullable();
            $table->timestamps();
            $table->unique(['ay_category_id', 'ay_group_id']);
        });

        Schema::create('product_material_composition', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->boolean('is_textile')->default(true);
            $table->unsignedInteger('cluster_id')->default(1);
            $table->string('cluster_label', 120)->nullable();
            $table->unsignedInteger('ay_material_id');
            $table->string('material_label', 120)->nullable();
            $table->unsignedSmallInteger('fraction')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'is_textile']);
        });

        Schema::create('product_sync_errors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('ps_id')->index();
            $table->char('run_id', 16)->nullable();
            $table->enum('phase', ['preflight', 'push', 'runtime'])->default('runtime');
            $table->string('reason_code', 64)->default('unknown')->index();
            $table->text('error_message');
            $table->json('error_details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'created_at']);
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key', 120)->primary();
            $table->text('value')->nullable();
            $table->enum('type', ['string', 'boolean', 'integer', 'json', 'password'])->default('string');
            $table->string('label', 255)->nullable();
            $table->string('group_name', 80)->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('retry_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('job_type', 80);
            $table->string('entity_key', 160);
            $table->json('payload_json')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->useCurrent();
            $table->enum('status', ['pending', 'done', 'dead'])->default('pending');
            $table->timestamps();
            $table->unique(['job_type', 'entity_key']);
            $table->index(['status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retry_jobs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('product_sync_errors');
        Schema::dropIfExists('product_material_composition');
        Schema::dropIfExists('ay_required_group_defaults');
        Schema::dropIfExists('material_cluster_maps');
        Schema::dropIfExists('material_component_maps');
        Schema::dropIfExists('attribute_maps');
        Schema::dropIfExists('ay_policy_snapshots');
        Schema::dropIfExists('sync_metrics');
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
