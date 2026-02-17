<?php

class PluginSatisfactionclientInstall
{
    public static function install(): bool
    {
        global $DB;

        $migration = new Migration(PLUGIN_SATISFACTIONCLIENT_VERSION);

        $charset = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $keySign = DBConnection::getDefaultPrimaryKeySignOption();

        $table = 'glpi_plugin_satisfactionclient_answers';
        if (!$DB->tableExists($table)) {
            $query = <<<SQL
CREATE TABLE `$table` (
    `id` int $keySign NOT NULL auto_increment,
    `tickets_id` int $keySign NOT NULL,
    `entities_id` int $keySign NOT NULL,
    `users_id_requester` int $keySign NOT NULL,
    `users_id_technician` int $keySign NOT NULL DEFAULT 0,
    `itilsolutions_id` int $keySign DEFAULT NULL,
    `itilfollowups_id` int $keySign DEFAULT NULL,
    `question_key` varchar(64) NOT NULL,
    `question_label` varchar(255) NOT NULL,
    `answer_value` varchar(255) DEFAULT NULL,
    `answer_text` text,
    `date_answered` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `tickets_id` (`tickets_id`),
    KEY `entities_id` (`entities_id`),
    KEY `users_id_requester` (`users_id_requester`),
    KEY `users_id_technician` (`users_id_technician`),
    KEY `itilsolutions_id` (`itilsolutions_id`)
) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;
SQL;
            $DB->doQuery($query);
        }

        $questionsTable = 'glpi_plugin_satisfactionclient_questions';
        if (!$DB->tableExists($questionsTable)) {
            $query = <<<SQL
CREATE TABLE `$questionsTable` (
    `id` int $keySign NOT NULL auto_increment,
    `question_key` varchar(64) NOT NULL,
    `question_label` varchar(255) NOT NULL,
    `question_type` varchar(20) NOT NULL,
    `is_required` tinyint(1) NOT NULL DEFAULT 0,
    `is_visible` tinyint(1) NOT NULL DEFAULT 1,
    `visibility_mode` varchar(16) NOT NULL DEFAULT 'always',
    `scale` int NOT NULL DEFAULT 5,
    `position` int NOT NULL DEFAULT 0,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `question_key` (`question_key`),
    KEY `is_active` (`is_active`),
    KEY `position` (`position`)
) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;
SQL;
            $DB->doQuery($query);
        }

        if ($DB->tableExists($questionsTable)) {
            $dbu = new DbUtils();
            if ($dbu->countElementsInTable($questionsTable, []) === 0) {
                $defaults = [
                    [
                        'question_key' => 'service_rating',
                        'question_label' => 'Note du service (1-5)',
                        'question_type' => 'rating',
                        'is_required' => 1,
                        'scale' => 5,
                    ],
                    [
                        'question_key' => 'resolution_ok',
                        'question_label' => 'Solution repond a votre demande',
                        'question_type' => 'yesno',
                        'is_required' => 1,
                        'scale' => 5,
                    ],
                    [
                        'question_key' => 'comment',
                        'question_label' => 'Commentaire',
                        'question_type' => 'text',
                        'is_required' => 0,
                        'scale' => 5,
                    ],
                ];

                $position = 1;
                foreach ($defaults as $default) {
                    $default['position'] = $position;
                    $default['is_active'] = 1;
                    $position++;
                    $DB->insert($questionsTable, $default);
                }
            }
        }

        $configTable = 'glpi_plugin_satisfactionclient_configs';
        if (!$DB->tableExists($configTable)) {
            $query = <<<SQL
CREATE TABLE `$configTable` (
    `id` int $keySign NOT NULL auto_increment,
    `intro_text` text,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;
SQL;
            $DB->doQuery($query);
        }

        if ($DB->tableExists($configTable)) {
            if (!$DB->fieldExists($configTable, 'intro_text')) {
                $migration->addField($configTable, 'intro_text', 'text');
            }

            $dbu = new DbUtils();
            if ($dbu->countElementsInTable($configTable, []) === 0) {
                $DB->insert($configTable, [
                    'intro_text' => 'Merci de noter le service.',
                ]);
            }
        }

        $rulesTable = 'glpi_plugin_satisfactionclient_rules';
        if (!$DB->tableExists($rulesTable)) {
            $query = <<<SQL
CREATE TABLE `$rulesTable` (
    `id` int $keySign NOT NULL auto_increment,
    `trigger_key` varchar(64) NOT NULL,
    `operator` varchar(12) NOT NULL,
    `trigger_value` varchar(255) DEFAULT NULL,
    `target_key` varchar(64) NOT NULL,
    `action` varchar(16) NOT NULL DEFAULT 'always',
    `require_on_match` tinyint(1) NOT NULL DEFAULT 0,
    `mail_to` varchar(255) DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `date_creation` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `trigger_key` (`trigger_key`),
    KEY `target_key` (`target_key`),
    KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;
SQL;
            $DB->doQuery($query);
        }

        $migration->executeMigration();

        return true;
    }

    public static function uninstall(): bool
    {
        $migration = new Migration(PLUGIN_SATISFACTIONCLIENT_VERSION);
        $migration->dropTable('glpi_plugin_satisfactionclient_rules');
        $migration->dropTable('glpi_plugin_satisfactionclient_configs');
        $migration->dropTable('glpi_plugin_satisfactionclient_questions');
        $migration->dropTable('glpi_plugin_satisfactionclient_answers');
        $migration->executeMigration();

        return true;
    }
}
