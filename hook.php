<?php
/** Instalação e atualização idempotentes. */

function plugin_assignature_install(): bool
{
    global $DB;

    $sign = DBConnection::getDefaultPrimaryKeySignOption();
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    if (!$DB->tableExists('glpi_plugin_assignature_sources')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_assignature_sources` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `slug` VARCHAR(64) NOT NULL,
            `name` VARCHAR(150) NOT NULL,
            `adapter` VARCHAR(30) NOT NULL DEFAULT 'generic',
            `endpoint_url` VARCHAR(500) NOT NULL,
            `token_encrypted` TEXT NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `last_sync` DATETIME DEFAULT NULL,
            `last_error` TEXT DEFAULT NULL,
            `date_creation` DATETIME DEFAULT NULL,
            `date_mod` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_assignature_batches')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_assignature_batches` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `method` VARCHAR(20) NOT NULL,
            `status` VARCHAR(20) NOT NULL,
            `technician_users_id` INT {$sign} NOT NULL,
            `technician_profiles_id` INT {$sign} NOT NULL DEFAULT 0,
            `technician_name` VARCHAR(255) NOT NULL DEFAULT '',
            `technician_document_type` VARCHAR(10) NOT NULL DEFAULT '',
            `technician_document_encrypted` TEXT DEFAULT NULL,
            `technician_signature_png` MEDIUMTEXT DEFAULT NULL,
            `signer_name` VARCHAR(255) NOT NULL DEFAULT '',
            `document_type` VARCHAR(10) NOT NULL DEFAULT '',
            `document_encrypted` TEXT DEFAULT NULL,
            `signature_png` MEDIUMTEXT DEFAULT NULL,
            `file_name` VARCHAR(255) NOT NULL DEFAULT '',
            `file_extension` VARCHAR(10) NOT NULL DEFAULT '',
            `file_sha256` CHAR(64) NOT NULL DEFAULT '',
            `created_by_users_id` INT {$sign} NOT NULL,
            `completed_by_users_id` INT {$sign} NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `completed_at` DATETIME DEFAULT NULL,
            `printed_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`), KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
    }
    foreach ([
        'technician_profiles_id' => "INT {$sign} NOT NULL DEFAULT 0",
        'technician_name' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'technician_document_type' => "VARCHAR(10) NOT NULL DEFAULT ''",
        'technician_document_encrypted' => 'TEXT DEFAULT NULL',
        'technician_signature_png' => 'MEDIUMTEXT DEFAULT NULL',
        'printed_at' => 'DATETIME DEFAULT NULL',
    ] as $field => $definition) {
        if (!$DB->fieldExists('glpi_plugin_assignature_batches', $field)) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_assignature_batches` ADD `{$field}` {$definition}") or die($DB->error());
        }
    }
    if (!$DB->tableExists('glpi_plugin_assignature_technicians')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_assignature_technicians` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `full_name` VARCHAR(255) NOT NULL,
            `document_type` VARCHAR(10) NOT NULL,
            `document_encrypted` TEXT NOT NULL,
            `signature_png` MEDIUMTEXT NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `date_mod` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
    }
    if (!$DB->tableExists('glpi_plugin_assignature_technicians_sources')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_assignature_technicians_sources` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `technicians_id` INT {$sign} NOT NULL,
            `sources_id` INT {$sign} NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `technician_source` (`technicians_id`, `sources_id`),
            KEY `sources_id` (`sources_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
    }
    if (!$DB->tableExists('glpi_plugin_assignature_settings')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_assignature_settings` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `printer_queue` VARCHAR(100) NOT NULL DEFAULT 'HP_PeB',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
        $DB->insert('glpi_plugin_assignature_settings', ['printer_queue' => 'HP_PeB']);
    }

    if (!$DB->tableExists('glpi_plugin_assignature_requests')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_assignature_requests` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `sources_id` INT {$sign} NOT NULL,
            `external_id` VARCHAR(100) NOT NULL,
            `code` VARCHAR(100) NOT NULL DEFAULT '',
            `title` VARCHAR(255) NOT NULL DEFAULT '',
            `summary` TEXT DEFAULT NULL,
            `payload_json` MEDIUMTEXT DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `batches_id` INT {$sign} NOT NULL DEFAULT 0,
            `signature_png` MEDIUMTEXT DEFAULT NULL,
            `signer_name` VARCHAR(255) NOT NULL DEFAULT '',
            `signer_document` VARCHAR(100) NOT NULL DEFAULT '',
            `signed_by_users_id` INT {$sign} NOT NULL DEFAULT 0,
            `signed_at` DATETIME DEFAULT NULL,
            `receipt_key` CHAR(48) NOT NULL DEFAULT '',
            `callback_state` VARCHAR(20) NOT NULL DEFAULT 'none',
            `callback_error` TEXT DEFAULT NULL,
            `date_creation` DATETIME DEFAULT NULL,
            `date_mod` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `source_external` (`sources_id`, `external_id`),
            KEY `status` (`status`), KEY `source_status` (`sources_id`, `status`),
            KEY `batches_id` (`batches_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
    }
    if (!$DB->fieldExists('glpi_plugin_assignature_requests', 'batches_id')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_assignature_requests` ADD `batches_id` INT {$sign} NOT NULL DEFAULT 0, ADD KEY `batches_id` (`batches_id`)") or die($DB->error());
    }
    $directory = GLPI_PLUGIN_DOC_DIR . '/assignature';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível criar o diretório privado de comprovantes do Assignat-ure.');
    }

    if (!$DB->tableExists('glpi_plugin_assignature_scopes')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_assignature_scopes` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `users_id` INT {$sign} NOT NULL,
            `sources_id` INT {$sign} NOT NULL,
            `see_pending` TINYINT(1) NOT NULL DEFAULT 0,
            `see_done` TINYINT(1) NOT NULL DEFAULT 0,
            `can_sign` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), UNIQUE KEY `user_source` (`users_id`, `sources_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}") or die($DB->error());
    }

    require_once __DIR__ . '/inc/profile.class.php';
    PluginAssignatureProfile::install();

    if (class_exists('CronTask')) {
        require_once __DIR__ . '/inc/sync.class.php';
        CronTask::register('PluginAssignatureSync', 'sync', 300, [
            'state' => CronTask::STATE_WAITING,
            'mode' => CronTask::MODE_EXTERNAL,
            'comment' => 'Assignat-ure: sincronizar pedidos e confirmações',
            'logs_lifetime' => 30,
        ]);
    }
    return true;
}

function plugin_assignature_update(): bool
{
    return plugin_assignature_install();
}

function plugin_assignature_uninstall(): bool
{
    global $DB;
    require_once __DIR__ . '/inc/profile.class.php';
    PluginAssignatureProfile::uninstall();
    if (class_exists('CronTask')) {
        CronTask::unregister('PluginAssignatureSync', 'sync');
    }
    foreach (['scopes', 'requests', 'batches', 'settings', 'technicians_sources', 'technicians', 'sources'] as $table) {
        $name = 'glpi_plugin_assignature_' . $table;
        if ($DB->tableExists($name)) {
            $DB->doQuery("DROP TABLE `{$name}`");
        }
    }
    return true;
}
