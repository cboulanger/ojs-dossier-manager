<?php

/**
 * @file plugins/generic/dossierManager/DossierManagerSchemaMigration.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DossierManagerSchemaMigration
 *
 * @brief The plugin's tables. Idempotent: also used to upgrade existing
 *  installations when the plugin loads (see DossierManagerPlugin::upgradeSchema()).
 *  Raise SCHEMA_VERSION when changing it.
 */

namespace APP\plugins\generic\dossierManager;

use APP\core\Application;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DossierManagerSchemaMigration extends Migration
{
    /** Increase when tables or data migrations are added */
    const SCHEMA_VERSION = 1;

    /** Plugin name in plugin_settings */
    const PLUGIN_NAME = 'dossiermanagerplugin';

    public function up(): void
    {
        if (!Schema::hasTable('dm_dossiers')) {
            Schema::create('dm_dossiers', function (Blueprint $table) {
                $table->bigInteger('dossier_id')->autoIncrement();
                $table->bigInteger('context_id');
                $table->string('type', 255)->default('');
                // Localized values as JSON objects {locale: value}
                $table->text('title');
                $table->longText('description')->nullable();
                $table->bigInteger('issue_id')->nullable();
                $table->bigInteger('section_id')->nullable();
                $table->date('deadline')->nullable();
                $table->string('guidelines_url', 2047)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('show_contributors')->default(true);
                // Per-dossier overrides of the reminder settings (null: journal setting)
                $table->integer('reminder_days')->nullable();
                $table->integer('overdue_interval')->nullable();
                $table->integer('overdue_max')->nullable();
                $table->boolean('cancelled')->default(false);
                $table->datetime('date_invited')->nullable();
                $table->bigInteger('created_by')->nullable();
                $table->datetime('date_created');
                $table->datetime('date_modified');
                $table->index(['context_id'], 'dm_dossiers_context_id');
            });
        }

        if (!Schema::hasTable('dm_dossier_editors')) {
            Schema::create('dm_dossier_editors', function (Blueprint $table) {
                $table->bigInteger('dossier_id');
                $table->bigInteger('user_id');
                // default | immediate | digest (see Notifier)
                $table->string('notify_mode', 16)->default('default');
                $table->unique(['dossier_id', 'user_id'], 'dm_dossier_editors_pkey');
                $table->index(['user_id'], 'dm_dossier_editors_user_id');
            });
        }

        if (!Schema::hasTable('dm_contributions')) {
            Schema::create('dm_contributions', function (Blueprint $table) {
                $table->bigInteger('contribution_id')->autoIncrement();
                $table->bigInteger('dossier_id');
                $table->integer('seq')->default(0);
                $table->string('working_title', 1023)->default('');
                $table->bigInteger('user_id')->nullable();
                $table->date('deadline')->nullable();
                $table->text('note')->nullable();
                // planned | invited | declined | dropped (the rest is derived from the submission)
                $table->string('status', 16)->default('planned');
                $table->string('link_key', 64)->nullable();
                $table->bigInteger('submission_id')->nullable();
                $table->datetime('date_invited')->nullable();
                $table->datetime('reminder_sent')->nullable();
                $table->integer('overdue_count')->default(0);
                $table->datetime('last_overdue')->nullable();
                $table->boolean('reminders_paused')->default(false);
                $table->text('decline_reason')->nullable();
                $table->datetime('date_created');
                $table->index(['dossier_id'], 'dm_contributions_dossier_id');
                $table->index(['user_id'], 'dm_contributions_user_id');
                $table->index(['submission_id'], 'dm_contributions_submission_id');
                $table->unique(['link_key'], 'dm_contributions_link_key');
            });
        }

        if (!Schema::hasTable('dm_digest')) {
            Schema::create('dm_digest', function (Blueprint $table) {
                $table->bigInteger('digest_id')->autoIncrement();
                $table->bigInteger('dossier_id');
                $table->bigInteger('user_id');
                $table->text('message');
                $table->datetime('date_created');
                $table->index(['user_id'], 'dm_digest_user_id');
            });
        }

        DB::table('plugin_settings')->updateOrInsert(
            ['plugin_name' => self::PLUGIN_NAME, 'context_id' => Application::SITE_CONTEXT_ID, 'setting_name' => 'schemaVersion'],
            ['setting_value' => (string) self::SCHEMA_VERSION, 'setting_type' => 'int']
        );
    }

    public function down(): void
    {
        foreach (['dm_digest', 'dm_contributions', 'dm_dossier_editors', 'dm_dossiers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
