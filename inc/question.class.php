<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginSatisfactionclientQuestion
{
    private const TABLE = 'glpi_plugin_satisfactionclient_questions';
    private const VALID_TYPES = ['rating', 'yesno', 'text', 'short_text', 'number', 'date', 'email'];
    private static bool $schemaEnsured = false;
    private static ?array $cacheAll = null;
    private static ?array $cacheActive = null;

    private static function resetRuntimeCache(): void
    {
        self::$cacheAll = null;
        self::$cacheActive = null;
    }

    private static function ensureSchemaCached(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        self::ensureSchema();
        self::$schemaEnsured = true;
    }

    public static function ensureSchema(): void
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }

        if (!$DB->fieldExists(self::TABLE, 'is_visible')) {
            $DB->doQuery("ALTER TABLE `" . self::TABLE . "` ADD `is_visible` tinyint(1) NOT NULL DEFAULT 1");
        }
        if (!$DB->fieldExists(self::TABLE, 'visibility_mode')) {
            $DB->doQuery("ALTER TABLE `" . self::TABLE . "` ADD `visibility_mode` varchar(16) NOT NULL DEFAULT 'always'");
        }
    }

    public static function getDefaultQuestions(): array
    {
        return [
            [
                'key' => 'service_rating',
                'label' => 'Note du service (1-5)',
                'type' => 'rating',
                'required' => true,
                'scale' => 5,
            ],
            [
                'key' => 'resolution_ok',
                'label' => 'Solution repond a votre demande',
                'type' => 'yesno',
                'required' => true,
            ],
            [
                'key' => 'comment',
                'label' => 'Commentaire',
                'type' => 'text',
                'required' => false,
            ],
        ];
    }

    public static function ensureDefaults(): void
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }

        self::ensureSchemaCached();

        $dbu = new DbUtils();
        if ($dbu->countElementsInTable(self::TABLE, []) > 0) {
            return;
        }

        $position = 1;
        foreach (self::getDefaultQuestions() as $question) {
            $record = [
                'question_key' => $question['key'],
                'question_label' => $question['label'],
                'question_type' => $question['type'],
                'is_required' => !empty($question['required']) ? 1 : 0,
                'is_visible' => 1,
                'visibility_mode' => 'always',
                'scale' => (int) ($question['scale'] ?? 5),
                'position' => $position,
                'is_active' => 1,
            ];
            $position++;
            $DB->insert(self::TABLE, $record);
        }
    }

    public static function hasAnyQuestions(): bool
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return false;
        }

        $dbu = new DbUtils();
        return $dbu->countElementsInTable(self::TABLE, []) > 0;
    }

    public static function getActiveQuestions(): array
    {
        global $DB;

        if (self::$cacheActive !== null) {
            return self::$cacheActive;
        }

        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }

        self::ensureSchemaCached();

        $iterator = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => [
                'is_active' => 1,
            ],
            'ORDER' => ['position ASC', 'id ASC'],
        ]);

        $questions = [];
        foreach ($iterator as $row) {
            $questions[] = [
                'key' => $row['question_key'],
                'label' => $row['question_label'],
                'type' => $row['question_type'],
                'required' => !empty($row['is_required']),
                'visibility_mode' => $row['visibility_mode'] ?? 'always',
                'scale' => (int) $row['scale'],
            ];
        }

        self::$cacheActive = $questions;
        return self::$cacheActive;
    }

    public static function getAll(): array
    {
        global $DB;

        if (self::$cacheAll !== null) {
            return self::$cacheAll;
        }

        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }

        self::ensureSchemaCached();

        $iterator = $DB->request([
            'FROM' => self::TABLE,
            'ORDER' => ['position ASC', 'id ASC'],
        ]);

        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = $row;
        }

        self::$cacheAll = $rows;
        return self::$cacheAll;
    }

    public static function getById(int $id): ?array
    {
        global $DB;

        if ($id <= 0 || !$DB->tableExists(self::TABLE)) {
            return null;
        }

        $iterator = $DB->request([
            'FROM' => self::TABLE,
            'WHERE' => [
                'id' => $id,
            ],
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return $row;
        }

        return null;
    }

    public static function add(array $input, ?string &$error = null): bool
    {
        global $DB;

        $providedKey = trim((string) ($input['question_key'] ?? '')) !== '';
        $record = self::buildRecord($input, $error, true);
        if (empty($record)) {
            return false;
        }
        if ($record['question_type'] === 'rating') {
            $record['is_required'] = 1;
            $record['is_active'] = 1;
            if (self::countActiveRating() > 0) {
                $error = 'Une seule question de type note est autorisee.';
                return false;
            }
        } elseif (self::countActiveRating() === 0) {
            $error = 'Une question de type note est obligatoire.';
            return false;
        }

        if ($providedKey) {
            if (self::keyExists($record['question_key'])) {
                $error = 'La cle existe deja.';
                return false;
            }
        } else {
            $record['question_key'] = self::ensureUniqueKey($record['question_key']);
        }

        $ok = (bool) $DB->insert(self::TABLE, $record);
        if ($ok) {
            self::resetRuntimeCache();
        }
        return $ok;
    }

    public static function update(int $id, array $input, ?string &$error = null): bool
    {
        global $DB;

        if ($id <= 0) {
            $error = 'Identifiant invalide.';
            return false;
        }

        $current = self::getById($id);
        if (!$current) {
            $error = 'Identifiant invalide.';
            return false;
        }

        $providedKey = trim((string) ($input['question_key'] ?? '')) !== '';
        $record = self::buildRecord($input, $error, false);
        if (empty($record)) {
            return false;
        }

        if (!array_key_exists('position', $input)) {
            unset($record['position']);
        }

        if ($providedKey) {
            if (self::keyExists($record['question_key'], $id)) {
                $error = 'La cle existe deja.';
                return false;
            }
        } else {
            $record['question_key'] = self::ensureUniqueKey($record['question_key'], $id);
        }

        $wasRating = ($current['question_type'] ?? '') === 'rating';
        $wasActive = !empty($current['is_active']);
        $willBeRating = $record['question_type'] === 'rating';
        $willBeActive = !empty($record['is_active']);

        if ($willBeRating) {
            $record['is_required'] = 1;
            if ($willBeActive && self::countActiveRating($id) > 0) {
                $error = 'Une seule question de type note est autorisee.';
                return false;
            }
        }

        if ($wasRating && $wasActive && (!$willBeRating || !$willBeActive) && self::countActiveRating($id) === 0) {
            $error = 'Une question de type note est obligatoire.';
            return false;
        }

        if (!$willBeRating && self::countActiveRating() === 0 && !($wasRating && $wasActive)) {
            $error = 'Une question de type note est obligatoire.';
            return false;
        }

        $ok = (bool) $DB->update(self::TABLE, $record, ['id' => $id]);
        if ($ok) {
            self::resetRuntimeCache();
        }
        return $ok;
    }

    public static function delete(int $id): bool
    {
        global $DB;

        if ($id <= 0) {
            return false;
        }
        $current = self::getById($id);
        if ($current && ($current['question_type'] ?? '') === 'rating' && !empty($current['is_active'])) {
            if (self::countActiveRating($id) === 0) {
                return false;
            }
        }

        $ok = (bool) $DB->delete(self::TABLE, ['id' => $id]);
        if ($ok) {
            self::resetRuntimeCache();
        }
        return $ok;
    }

    public static function updateOrder(array $ids): void
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }

        $position = 1;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $DB->update(self::TABLE, ['position' => $position], ['id' => $id]);
            $position++;
        }
        self::resetRuntimeCache();
    }

    public static function getTypeLabel(string $type): string
    {
        switch ($type) {
            case 'rating':
                return 'Note';
            case 'yesno':
                return 'Oui/Non';
            case 'short_text':
                return 'Texte court';
            case 'number':
                return 'Nombre';
            case 'date':
                return 'Date';
            case 'email':
                return 'Email';
            default:
                return 'Texte long';
        }
    }

    private static function buildRecord(array $input, ?string &$error, bool $autoPosition): array
    {
        $keyInput = (string) ($input['question_key'] ?? '');
        $key = self::normalizeKey($keyInput);
        $label = trim((string) ($input['question_label'] ?? ''));
        $type = self::normalizeType($input['question_type'] ?? 'text');

        if ($label === '') {
            $error = 'Le libelle est obligatoire.';
            return [];
        }

        if ($key === '') {
            $key = self::normalizeKey($label);
        }

        if ($key === '') {
            $error = 'Cle invalide.';
            return [];
        }

        $scale = self::normalizeScale($input['scale'] ?? null, $type);
        $position = isset($input['position']) ? (int) $input['position'] : 0;
        if ($autoPosition && $position <= 0) {
            $position = self::getNextPosition();
        }
        return [
            'question_key' => $key,
            'question_label' => $label,
            'question_type' => $type,
            'is_required' => !empty($input['is_required']) ? 1 : 0,
            'is_visible' => 1,
            'visibility_mode' => self::normalizeVisibilityMode($input['visibility_mode'] ?? 'always'),
            'scale' => $scale,
            'position' => $position,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
        ];
    }

    private static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key);
        return trim($key, '_');
    }

    private static function normalizeType(?string $type): string
    {
        $type = strtolower(trim((string) $type));
        if ($type === 'textarea') {
            $type = 'text';
        }
        if (!in_array($type, self::VALID_TYPES, true)) {
            return 'text';
        }
        return $type;
    }

    private static function normalizeScale($scale, string $type): int
    {
        if ($type !== 'rating') {
            return 5;
        }

        $scale = (int) $scale;
        if ($scale < 2) {
            return 5;
        }

        return $scale;
    }

    private static function normalizeVisibilityMode($mode): string
    {
        $mode = strtolower(trim((string) $mode));
        if (!in_array($mode, ['always', 'hide_by_default', 'show_by_default'], true)) {
            return 'always';
        }
        return $mode;
    }

    private static function countActiveRating(int $excludeId = 0): int
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return 0;
        }

        $where = [
            'question_type' => 'rating',
            'is_active' => 1,
        ];
        if ($excludeId > 0) {
            $where['id'] = ['<>', $excludeId];
        }

        $dbu = new DbUtils();
        return (int) $dbu->countElementsInTable(self::TABLE, $where);
    }

    private static function getNextPosition(): int
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return 1;
        }

        $iterator = $DB->request([
            'SELECT' => ['MAX' => 'position AS maxpos'],
            'FROM' => self::TABLE,
        ]);

        foreach ($iterator as $row) {
            return ((int) $row['maxpos']) + 1;
        }

        return 1;
    }

    private static function ensureUniqueKey(string $base, int $excludeId = 0): string
    {
        $base = self::normalizeKey($base);
        if ($base === '') {
            return $base;
        }

        $key = $base;
        $suffix = 1;
        while (self::keyExists($key, $excludeId)) {
            $suffix++;
            $key = $base . '_' . $suffix;
        }

        return $key;
    }

    private static function keyExists(string $key, int $excludeId = 0): bool
    {
        global $DB;

        if ($key === '' || !$DB->tableExists(self::TABLE)) {
            return false;
        }

        $where = [
            'question_key' => $key,
        ];
        if ($excludeId > 0) {
            $where['id'] = ['<>', $excludeId];
        }

        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM' => self::TABLE,
            'WHERE' => $where,
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return true;
        }

        return false;
    }
}
