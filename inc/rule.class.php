<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginSatisfactionclientRule
{
    private const TABLE = 'glpi_plugin_satisfactionclient_rules';
    private const VALID_OPERATORS = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'is_empty', 'not_empty'];
    private const VALID_ACTIONS = ['require', 'show', 'hide', 'email'];

    public static function ensureSchema(): void
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }

        if (!$DB->fieldExists(self::TABLE, 'action')) {
            $DB->doQuery("ALTER TABLE `" . self::TABLE . "` ADD `action` varchar(16) NOT NULL DEFAULT 'require'");
        }
        if (!$DB->fieldExists(self::TABLE, 'require_on_match')) {
            $DB->doQuery("ALTER TABLE `" . self::TABLE . "` ADD `require_on_match` tinyint(1) NOT NULL DEFAULT 0");
        }
        if (!$DB->fieldExists(self::TABLE, 'mail_to')) {
            $DB->doQuery("ALTER TABLE `" . self::TABLE . "` ADD `mail_to` varchar(255) DEFAULT NULL");
        }
    }

    public static function getAll(): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }

        self::ensureSchema();

        $iterator = $DB->request([
            'FROM' => self::TABLE,
            'ORDER' => ['id ASC'],
        ]);

        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public static function getActiveRules(): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }

        self::ensureSchema();

        $iterator = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => [
                'is_active' => 1,
            ],
            'ORDER' => ['id ASC'],
        ]);

        $rules = [];
        foreach ($iterator as $row) {
            $action = $row['action'] ?? 'show';
            $requireOnMatch = !empty($row['require_on_match']) ? 1 : 0;
            if (!in_array($action, self::VALID_ACTIONS, true)) {
                $action = 'show';
            }
            $rules[] = [
                'trigger' => $row['trigger_key'],
                'operator' => $row['operator'],
                'value' => $row['trigger_value'],
                'target' => $row['target_key'],
                'action' => $action,
                'require_on_match' => $requireOnMatch,
                'mail_to' => $row['mail_to'] ?? '',
            ];
        }

        return $rules;
    }

    public static function add(array $input, ?string &$error = null): bool
    {
        global $DB;

        $record = self::buildRecord($input, $error);
        if (empty($record)) {
            return false;
        }

        $record['date_creation'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        return (bool) $DB->insert(self::TABLE, $record);
    }

    public static function update(int $id, array $input, ?string &$error = null): bool
    {
        global $DB;

        if ($id <= 0) {
            $error = 'Identifiant invalide.';
            return false;
        }

        $record = self::buildRecord($input, $error);
        if (empty($record)) {
            return false;
        }

        return (bool) $DB->update(self::TABLE, $record, ['id' => $id]);
    }

    public static function delete(int $id): bool
    {
        global $DB;

        if ($id <= 0) {
            return false;
        }

        return (bool) $DB->delete(self::TABLE, ['id' => $id]);
    }

    public static function getOperators(): array
    {
        return [
            'eq' => '=',
            'neq' => '!=',
            'lt' => '<',
            'lte' => '<=',
            'gt' => '>',
            'gte' => '>=',
            'is_empty' => 'est vide',
            'not_empty' => 'non vide',
        ];
    }

    private static function buildRecord(array $input, ?string &$error): array
    {
        $trigger = trim((string) ($input['trigger_key'] ?? ''));
        $target = trim((string) ($input['target_key'] ?? ''));
        $operator = strtolower(trim((string) ($input['operator'] ?? '')));
        $action = strtolower(trim((string) ($input['action'] ?? 'show')));
        $requireOnMatch = !empty($input['require_on_match']) ? 1 : 0;
        $value = trim((string) ($input['trigger_value'] ?? ''));
        $mailTo = trim((string) ($input['mail_to'] ?? ''));

        if ($trigger === '' || $target === '') {
            $error = 'Question source et cible obligatoires.';
            return [];
        }

        if (!in_array($operator, self::VALID_OPERATORS, true)) {
            $operator = 'eq';
        }
        if (!in_array($action, self::VALID_ACTIONS, true)) {
            $action = 'show';
        }

        if (in_array($operator, ['is_empty', 'not_empty'], true)) {
            $value = '';
        }

        return [
            'trigger_key' => $trigger,
            'operator' => $operator,
            'trigger_value' => $value,
            'target_key' => $target,
            'action' => $action,
            'require_on_match' => $requireOnMatch,
            'mail_to' => $mailTo,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
        ];
    }
}
