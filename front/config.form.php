<?php

include '../../../inc/includes.php';

$plugin = new Plugin();
if (!$plugin->isInstalled('satisfactionclient') || !$plugin->isActivated('satisfactionclient')) {
    Html::displayNotFoundError();
}

Session::checkRight('config', UPDATE);

PluginSatisfactionclientQuestion::ensureSchema();
PluginSatisfactionclientRule::ensureSchema();
PluginSatisfactionclientQuestion::ensureDefaults();
PluginSatisfactionclientConfig::ensureDefaults();

$csrf_token = Session::getNewCSRFToken();
$config_url = Plugin::getWebDir('satisfactionclient') . '/front/config.form.php';

function sc_sync_question_rules(string $questionKey, array $input): void
{
    global $DB;

    if ($questionKey === '') {
        return;
    }

    $rulesTable = 'glpi_plugin_satisfactionclient_rules';
    if (!$DB->tableExists($rulesTable)) {
        return;
    }

    $DB->delete($rulesTable, [
        'target_key' => $questionKey,
        'action' => ['show', 'hide', 'require', 'email'],
    ]);

    $visibilityMode = (string) ($input['visibility_mode'] ?? 'always');
    $displayTrigger = trim((string) ($input['display_trigger_key'] ?? ''));
    $displayOperator = trim((string) ($input['display_operator'] ?? 'eq'));
    $displayValue = trim((string) ($input['display_trigger_value'] ?? ''));

    if ($visibilityMode === 'hide_by_default' && $displayTrigger !== '') {
        $DB->insert($rulesTable, [
            'trigger_key' => $displayTrigger,
            'operator' => $displayOperator,
            'trigger_value' => $displayValue,
            'target_key' => $questionKey,
            'action' => 'show',
            'is_active' => 1,
            'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    } elseif ($visibilityMode === 'show_by_default' && $displayTrigger !== '') {
        $DB->insert($rulesTable, [
            'trigger_key' => $displayTrigger,
            'operator' => $displayOperator,
            'trigger_value' => $displayValue,
            'target_key' => $questionKey,
            'action' => 'hide',
            'is_active' => 1,
            'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    $requireTrigger = trim((string) ($input['require_trigger_key'] ?? ''));
    $requireOperator = trim((string) ($input['require_operator'] ?? 'eq'));
    $requireValue = trim((string) ($input['require_trigger_value'] ?? ''));
    if ($requireTrigger !== '') {
        $DB->insert($rulesTable, [
            'trigger_key' => $requireTrigger,
            'operator' => $requireOperator,
            'trigger_value' => $requireValue,
            'target_key' => $questionKey,
            'action' => 'require',
            'is_active' => 1,
            'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    $mailTrigger = trim((string) ($input['mail_trigger_key'] ?? ''));
    $mailOperator = trim((string) ($input['mail_operator'] ?? 'eq'));
    $mailValue = trim((string) ($input['mail_trigger_value'] ?? ''));
    $mailTo = trim((string) ($input['mail_to'] ?? ''));
    if ($mailTrigger !== '' && $mailTo !== '') {
        $DB->insert($rulesTable, [
            'trigger_key' => $mailTrigger,
            'operator' => $mailOperator,
            'trigger_value' => $mailValue,
            'target_key' => $questionKey,
            'action' => 'email',
            'mail_to' => $mailTo,
            'is_active' => 1,
            'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }
}

if (isset($_POST['export_config'])) {
    $export = [
        'version' => PLUGIN_SATISFACTIONCLIENT_VERSION,
        'exported_at' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        'intro_text' => PluginSatisfactionclientConfig::getIntroText(),
        'questions' => PluginSatisfactionclientQuestion::getAll(),
        'rules' => PluginSatisfactionclientRule::getAll(),
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="satisfactionclient_config.json"');
    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_POST['import_config'])) {
    $file = $_FILES['config_file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        Session::addMessageAfterRedirect('Fichier invalide.', true, ERROR);
        Html::redirect($config_url);
    }

    $content = file_get_contents($file['tmp_name']);
    $data = json_decode($content, true);
    if (!is_array($data)) {
        Session::addMessageAfterRedirect('JSON invalide.', true, ERROR);
        Html::redirect($config_url);
    }

    global $DB;
    $DB->beginTransaction();
    try {
        PluginSatisfactionclientConfig::updateIntroText((string) ($data['intro_text'] ?? ''));

        $questionsTable = 'glpi_plugin_satisfactionclient_questions';
        $rulesTable = 'glpi_plugin_satisfactionclient_rules';

        if ($DB->tableExists($questionsTable)) {
            $DB->delete($questionsTable, ['id' => ['>', 0]]);
            $position = 1;
            foreach (($data['questions'] ?? []) as $question) {
                if (!is_array($question)) {
                    continue;
                }
                $record = [
                    'question_key' => $question['question_key'] ?? '',
                    'question_label' => $question['question_label'] ?? '',
                    'question_type' => $question['question_type'] ?? 'text',
                    'is_required' => !empty($question['is_required']) ? 1 : 0,
                    'is_visible' => 1,
                    'visibility_mode' => $question['visibility_mode'] ?? 'always',
                    'scale' => (int) ($question['scale'] ?? 5),
                    'position' => (int) ($question['position'] ?? $position),
                    'is_active' => !empty($question['is_active']) ? 1 : 0,
                ];
                if (trim((string) $record['question_key']) === '' || trim((string) $record['question_label']) === '') {
                    continue;
                }
                $DB->insert($questionsTable, $record);
                $position++;
            }
        }

        if ($DB->tableExists($rulesTable)) {
            $DB->delete($rulesTable, ['id' => ['>', 0]]);
            foreach (($data['rules'] ?? []) as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $record = [
                    'trigger_key' => $rule['trigger_key'] ?? '',
                    'operator' => $rule['operator'] ?? 'eq',
                    'trigger_value' => $rule['trigger_value'] ?? '',
                    'target_key' => $rule['target_key'] ?? '',
                    'action' => $rule['action'] ?? 'always',
                    'require_on_match' => !empty($rule['require_on_match']) ? 1 : 0,
                    'mail_to' => $rule['mail_to'] ?? '',
                    'is_active' => !empty($rule['is_active']) ? 1 : 0,
                    'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ];
                if (trim((string) $record['trigger_key']) === '' || trim((string) $record['target_key']) === '') {
                    continue;
                }
                $DB->insert($rulesTable, $record);
            }
        }

        $DB->commit();
        Session::addMessageAfterRedirect('Configuration importee.', false, INFO);
    } catch (Throwable $e) {
        $DB->rollBack();
        Session::addMessageAfterRedirect('Erreur lors de l\'import.', true, ERROR);
    }
    Html::redirect($config_url);
}

if (isset($_POST['add_question'])) {
    $error = null;
    $ok = PluginSatisfactionclientQuestion::add($_POST, $error);
    if ($ok) {
        $questionKey = trim((string) ($_POST['question_key'] ?? ''));
        if ($questionKey === '') {
            $label = (string) ($_POST['question_label'] ?? '');
            $questionKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($label)));
            $questionKey = trim($questionKey, '_');
        }
        sc_sync_question_rules($questionKey, $_POST);
        Session::addMessageAfterRedirect('Question ajoutee.', false, INFO);
    } else {
        Session::addMessageAfterRedirect($error ?: 'Erreur lors de la creation.', true, ERROR);
    }
    Html::redirect($config_url);
}

if (isset($_POST['update_question'])) {
    $error = null;
    $id = (int) ($_POST['id'] ?? 0);
    $ok = PluginSatisfactionclientQuestion::update($id, $_POST, $error);
    if ($ok) {
        $questionKey = trim((string) ($_POST['question_key'] ?? ''));
        if ($questionKey !== '') {
            sc_sync_question_rules($questionKey, $_POST);
        }
        Session::addMessageAfterRedirect('Question mise a jour.', false, INFO);
    } else {
        Session::addMessageAfterRedirect($error ?: 'Erreur lors de la mise a jour.', true, ERROR);
    }
    Html::redirect($config_url);
}

if (isset($_POST['delete_question'])) {
    $id = (int) ($_POST['delete_question'] ?? 0);
    if (PluginSatisfactionclientQuestion::delete($id)) {
        Session::addMessageAfterRedirect('Question supprimee.', false, INFO);
    } else {
        Session::addMessageAfterRedirect('Erreur lors de la suppression.', true, ERROR);
    }
    Html::redirect($config_url);
}

if (isset($_POST['update_intro'])) {
    $text = (string) ($_POST['intro_text'] ?? '');
    if (PluginSatisfactionclientConfig::updateIntroText($text)) {
        Session::addMessageAfterRedirect('Texte mis a jour.', false, INFO);
    } else {
        Session::addMessageAfterRedirect('Erreur lors de la mise a jour du texte.', true, ERROR);
    }
    Html::redirect($config_url);
}

if (isset($_POST['save_order'])) {
    $order = $_POST['order'] ?? [];
    if (!is_array($order)) {
        $order = [];
    }
    PluginSatisfactionclientQuestion::updateOrder($order);
    Session::addMessageAfterRedirect('Ordre mis a jour.', false, INFO);
    Html::redirect($config_url);
}

if (isset($_POST['add_rule'])) {
    $error = null;
    if (PluginSatisfactionclientRule::add($_POST, $error)) {
        Session::addMessageAfterRedirect('Regle ajoutee.', false, INFO);
    } else {
        Session::addMessageAfterRedirect($error ?: 'Erreur lors de la creation de la regle.', true, ERROR);
    }
    Html::redirect($config_url);
}

if (isset($_POST['update_rule'])) {
    $error = null;
    $id = (int) ($_POST['rule_id'] ?? 0);
    if (PluginSatisfactionclientRule::update($id, $_POST, $error)) {
        Session::addMessageAfterRedirect('Regle mise a jour.', false, INFO);
    } else {
        Session::addMessageAfterRedirect($error ?: 'Erreur lors de la mise a jour de la regle.', true, ERROR);
    }
    Html::redirect($config_url);
}

if (isset($_POST['delete_rule'])) {
    $id = (int) ($_POST['delete_rule'] ?? 0);
    if (PluginSatisfactionclientRule::delete($id)) {
        Session::addMessageAfterRedirect('Regle supprimee.', false, INFO);
    } else {
        Session::addMessageAfterRedirect('Erreur lors de la suppression de la regle.', true, ERROR);
    }
    Html::redirect($config_url);
}

$editId = (int) ($_GET['id'] ?? 0);
$editQuestion = $editId > 0 ? PluginSatisfactionclientQuestion::getById($editId) : null;
$questions = PluginSatisfactionclientQuestion::getAll();
$questionLabelMap = [];
foreach ($questions as $question) {
    $questionLabelMap[$question['question_key']] = $question['question_label'];
}
$introText = PluginSatisfactionclientConfig::getIntroText();
$rules = PluginSatisfactionclientRule::getAll();
$rulesByTarget = [];
foreach ($rules as $rule) {
    $target = $rule['target_key'] ?? '';
    if ($target === '') {
        continue;
    }
    $action = $rule['action'] ?? '';
    if (in_array($action, ['show', 'hide'], true)) {
        $rulesByTarget[$target]['display'] = $rule;
        $rulesByTarget[$target]['visibility_mode'] = $action === 'show'
            ? 'hide_by_default'
            : 'show_by_default';
    } elseif ($action === 'require') {
        $rulesByTarget[$target]['require'] = $rule;
    } elseif ($action === 'email') {
        $rulesByTarget[$target]['mail'] = $rule;
    }
}
$editRuleId = (int) ($_GET['rule_id'] ?? 0);
$editRule = null;
if ($editRuleId > 0) {
    foreach ($rules as $rule) {
        if ((int) $rule['id'] === $editRuleId) {
            $editRule = $rule;
            break;
        }
    }
}

Html::header('Satisfaction client', $config_url, 'plugins', 'satisfactionclient', 'config');

echo "<div class='card mb-3'>";
echo "<div class='card-header'><h3 class='card-title mb-0'>Import / Export</h3></div>";
echo "<div class='card-body'>";
echo "<div class='row g-3 align-items-end'>";
echo "<div class='col-md-6'>";
echo "<label class='form-label mb-1'>Importer une configuration (JSON)</label>";
echo "<form method='post' action='" . $config_url . "' enctype='multipart/form-data'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrf_token]);
echo "<input class='form-control' type='file' name='config_file' accept='application/json' required>";
echo "<div class='mt-2'>";
echo Html::submit('Importer', ['name' => 'import_config', 'class' => 'btn btn-primary']);
echo "</div>";
echo "</form>";
echo "</div>";

echo "<div class='col-md-6'>";
echo "<label class='form-label mb-1'>Exporter la configuration actuelle</label>";
echo "<form method='post' action='" . $config_url . "'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrf_token]);
echo "<div class='mt-2'>";
echo Html::submit('Exporter', ['name' => 'export_config', 'class' => 'btn btn-outline-primary']);
echo "</div>";
echo "</form>";
echo "<div class='text-muted mt-2'>L'import remplace toutes les questions et regles existantes.</div>";
echo "</div>";

echo "</div>";
echo "</div>";
echo "</div>";

echo "<div class='card mb-3'>";
echo "<div class='card-header'><h3 class='card-title mb-0'>Texte d'introduction du modal</h3></div>";
echo "<div class='card-body'>";
echo "<form method='post' action='" . $config_url . "'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrf_token]);
echo "<textarea class='form-control' name='intro_text' rows='1'>" . htmlescape($introText) . "</textarea>";
echo "<div class='mt-2'>";
echo Html::submit('Enregistrer', ['name' => 'update_intro', 'class' => 'btn btn-primary']);
echo "</div>";
echo "</form>";
echo "</div>";
echo "</div>";

echo "<div class='modal fade' id='scQuestionModal' tabindex='-1' aria-hidden='true'>";
echo "<div class='modal-dialog modal-lg'>";
echo "<div class='modal-content'>";
echo "<form method='post' action='" . $config_url . "' id='sc_question_form'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrf_token]);
echo "<input type='hidden' name='id' id='sc_question_id' value=''>";
echo "<input type='hidden' name='question_key' id='sc_question_key' value=''>";
echo "<div class='modal-header'>";
echo "<h5 class='modal-title' id='scQuestionModalTitle'>Nouvelle question</h5>";
echo "<span class='ms-2 text-muted' id='scQuestionModalName'></span>";
echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>";
echo "</div>";
echo "<div class='modal-body'>";
echo "<div class='row g-3'>";

echo "<div class='col-md-6'>";
echo "<label class='form-label mb-1'>Libelle</label>";
echo Html::input('question_label', [
    'value' => '',
    'id' => 'sc_question_label',
    'class' => 'form-control',
    'required' => true,
    'maxlength' => 255,
]);
echo "</div>";

echo "<div class='col-md-3'>";
echo "<label class='form-label mb-1'>Type</label>";
echo "<select name='question_type' id='sc_question_type' class='form-select'>";
$types = [
    'rating' => 'Note',
    'yesno' => 'Oui/Non',
    'short_text' => 'Texte court',
    'text' => 'Texte long',
    'number' => 'Nombre',
    'date' => 'Date',
    'email' => 'Email',
];
foreach ($types as $value => $label) {
    echo "<option value='" . htmlescape($value) . "'>" . htmlescape($label) . "</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='col-md-3'>";
echo "<label class='form-label mb-1'>Echelle</label>";
echo Html::input('scale', [
    'type' => 'number',
    'min' => 2,
    'value' => 5,
    'id' => 'sc_scale',
    'class' => 'form-control',
]);
echo "</div>";

echo "<div class='col-md-6 d-flex align-items-end'>";
echo "<div class='form-check me-3'>";
echo "<input class='form-check-input' type='checkbox' name='is_required' id='sc_required' value='1'>";
echo "<label class='form-check-label' for='sc_required'>Requis</label>";
echo "</div>";
echo "<div class='form-check'>";
echo "<input class='form-check-input' type='checkbox' name='is_active' id='sc_active' value='1' checked>";
echo "<label class='form-check-label' for='sc_active'>Actif</label>";
echo "</div>";
echo "</div>";

echo "<div class='col-12'><hr></div>";
echo "<div class='col-12'>";
echo "<h6 class='mb-2'>Condition pour afficher la question</h6>";
echo "<div class='text-muted small mb-2' id='sc_display_summary'></div>";
echo "</div>";

echo "<div class='col-md-4'>";
echo "<label class='form-label mb-1'>Mode</label>";
echo "<select name='visibility_mode' id='sc_visibility_mode' class='form-select'>";
echo "<option value='always'>Toujours visible</option>";
echo "<option value='hide_by_default'>Masque par defaut, sauf si</option>";
echo "<option value='show_by_default'>Affiche par defaut, sauf si</option>";
echo "</select>";
echo "</div>";

echo "<div class='col-md-4' id='sc_display_trigger_group'>";
echo "<label class='form-label mb-1'>Question source</label>";
echo "<select name='display_trigger_key' id='sc_display_trigger_key' class='form-select'>";
echo "<option value=''>--</option>";
foreach ($questions as $question) {
    $key = $question['question_key'];
    $typeAttr = htmlescape((string) ($question['question_type'] ?? 'text'));
    $scaleAttr = (int) ($question['scale'] ?? 0);
    echo "<option value='" . htmlescape($key) . "' data-type='" . $typeAttr . "' data-scale='" . $scaleAttr . "'>"
        . htmlescape($question['question_label']) . "</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='col-md-2' id='sc_display_operator_group'>";
echo "<label class='form-label mb-1'>Operateur</label>";
echo "<select name='display_operator' id='sc_display_operator' class='form-select'>";
$operators = PluginSatisfactionclientRule::getOperators();
foreach ($operators as $value => $label) {
    echo "<option value='" . htmlescape($value) . "'>" . htmlescape($label) . "</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='col-md-2' id='sc_display_value_group'>";
echo "<label class='form-label mb-1'>Valeur</label>";
echo "<div id='sc_display_value_container'>";
echo Html::input('display_trigger_value', [
    'value' => '',
    'id' => 'sc_display_value',
    'class' => 'form-control',
    'placeholder' => 'ex: 3',
]);
echo "</div>";
echo "</div>";

echo "<div class='col-12'><hr></div>";
echo "<div class='col-12'>";
echo "<h6 class='mb-2'>Regle pour rendre obligatoire</h6>";
echo "<div class='text-muted small mb-2' id='sc_require_summary'></div>";
echo "</div>";

echo "<div class='col-md-4'>";
echo "<label class='form-label mb-1'>Question source</label>";
echo "<select name='require_trigger_key' id='sc_require_trigger_key' class='form-select'>";
echo "<option value=''>--</option>";
foreach ($questions as $question) {
    $key = $question['question_key'];
    $typeAttr = htmlescape((string) ($question['question_type'] ?? 'text'));
    $scaleAttr = (int) ($question['scale'] ?? 0);
    echo "<option value='" . htmlescape($key) . "' data-type='" . $typeAttr . "' data-scale='" . $scaleAttr . "'>"
        . htmlescape($question['question_label']) . "</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='col-md-2'>";
echo "<label class='form-label mb-1'>Operateur</label>";
echo "<select name='require_operator' id='sc_require_operator' class='form-select'>";
foreach ($operators as $value => $label) {
    echo "<option value='" . htmlescape($value) . "'>" . htmlescape($label) . "</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='col-md-2'>";
echo "<label class='form-label mb-1'>Valeur</label>";
echo "<div id='sc_require_value_container'>";
echo Html::input('require_trigger_value', [
    'value' => '',
    'id' => 'sc_require_value',
    'class' => 'form-control',
    'placeholder' => 'ex: 3',
]);
echo "</div>";
echo "</div>";

echo "<div class='col-12'><hr></div>";
echo "<div class='col-12'>";
echo "<h6 class='mb-2'>Condition pour l'envoie d'un mail</h6>";
echo "<div class='text-muted small mb-2' id='sc_mail_summary'></div>";
echo "</div>";

echo "<div class='col-md-4'>";
echo "<label class='form-label mb-1'>Question source</label>";
echo "<select name='mail_trigger_key' id='sc_mail_trigger_key' class='form-select'>";
echo "<option value=''>--</option>";
foreach ($questions as $question) {
    $key = $question['question_key'];
    $typeAttr = htmlescape((string) ($question['question_type'] ?? 'text'));
    $scaleAttr = (int) ($question['scale'] ?? 0);
    echo "<option value='" . htmlescape($key) . "' data-type='" . $typeAttr . "' data-scale='" . $scaleAttr . "'>"
        . htmlescape($question['question_label']) . "</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='col-md-2'>";
echo "<label class='form-label mb-1'>Operateur</label>";
echo "<select name='mail_operator' id='sc_mail_operator' class='form-select'>";
foreach ($operators as $value => $label) {
    echo "<option value='" . htmlescape($value) . "'>" . htmlescape($label) . "</option>";
}
echo "</select>";
echo "</div>";

echo "<div class='col-md-2'>";
echo "<label class='form-label mb-1'>Valeur</label>";
echo "<div id='sc_mail_value_container'>";
echo Html::input('mail_trigger_value', [
    'value' => '',
    'id' => 'sc_mail_value',
    'class' => 'form-control',
    'placeholder' => 'ex: 3',
]);
echo "</div>";
echo "</div>";

echo "<div class='col-md-4'>";
echo "<label class='form-label mb-1'>Envoyer un mail a</label>";
echo Html::input('mail_to', [
    'value' => '',
    'id' => 'sc_mail_to',
    'class' => 'form-control',
    'placeholder' => 'email1@domaine.com, email2@domaine.com',
]);
echo "</div>";
echo "</div>";
echo "</div>";
echo "<div class='modal-footer'>";
echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Annuler</button>";
echo "<button type='submit' class='btn btn-primary' id='sc_question_submit' name='add_question'>Enregistrer</button>";
echo "</div>";
echo "</form>";
echo "</div>";
echo "</div>";
echo "</div>";


echo Html::scriptBlock(<<<JAVASCRIPT
(function() {
  const boot = function() {
  const triggerSelect = document.getElementById('sc_trigger_key');
  const operatorSelect = document.querySelector('select[name="operator"]');
  const valueContainer = document.getElementById('sc_trigger_value_container');
    if (!operatorSelect || !valueContainer) {
      return;
    }

  const getCurrentValue = function() {
    const current = valueContainer.querySelector('[name="trigger_value"]');
    return current ? current.value : '';
  };

  const createValueControl = function(type, scale, currentValue) {
    let control;
    if (type === 'yesno') {
      control = document.createElement('select');
      control.className = 'form-select';
      const emptyOption = document.createElement('option');
      emptyOption.value = '';
      emptyOption.textContent = '--';
      control.appendChild(emptyOption);
      const yesOption = document.createElement('option');
      yesOption.value = '1';
      yesOption.textContent = 'Oui';
      const noOption = document.createElement('option');
      noOption.value = '0';
      noOption.textContent = 'Non';
      control.appendChild(yesOption);
      control.appendChild(noOption);
      if (currentValue === '1' || currentValue === '0') {
        control.value = currentValue;
      }
    } else {
      control = document.createElement('input');
      control.className = 'form-control';
      if (type === 'rating') {
        control.type = 'number';
        control.min = '1';
        if (scale && Number.isFinite(scale)) {
          control.max = String(scale);
        }
        control.step = '1';
      } else if (type === 'number') {
        control.type = 'number';
        control.step = '1';
      } else if (type === 'date') {
        control.type = 'date';
      } else if (type === 'email') {
        control.type = 'email';
      } else {
        control.type = 'text';
      }
      control.value = currentValue || '';
    }
    control.name = 'trigger_value';
    control.id = 'sc_trigger_value';
    return control;
  };

  const getSelectedType = function() {
    if (!triggerSelect) {
      return 'text';
    }
    const selected = triggerSelect.options[triggerSelect.selectedIndex];
    if (!selected) {
      return 'text';
    }
    return (selected.dataset.type || 'text').toLowerCase();
  };

  const getSelectedScale = function() {
    if (!triggerSelect) {
      return 0;
    }
    const selected = triggerSelect.options[triggerSelect.selectedIndex];
    if (!selected) {
      return 0;
    }
    return parseInt(selected.dataset.scale || '0', 10);
  };

  const updateValueControl = function() {
    if (!triggerSelect) {
      return;
    }
    const type = getSelectedType();
    const scale = getSelectedScale();
    const currentValue = getCurrentValue();
    const nextControl = createValueControl(type, scale, currentValue);
    valueContainer.innerHTML = '';
    valueContainer.appendChild(nextControl);
  };

  const updateOperatorOptions = function() {
    if (!operatorSelect) {
      return;
    }
    const type = getSelectedType();
    let allowed;
    if (type === 'yesno') {
      allowed = ['eq', 'neq', 'is_empty', 'not_empty'];
    } else if (type === 'rating' || type === 'number' || type === 'date') {
      allowed = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'is_empty', 'not_empty'];
    } else {
      allowed = ['eq', 'neq', 'is_empty', 'not_empty'];
    }
    let hasSelected = false;
    Array.prototype.forEach.call(operatorSelect.options, function(option) {
      const isAllowed = allowed.indexOf(option.value) !== -1;
      option.disabled = !isAllowed;
      option.hidden = !isAllowed;
      if (!isAllowed && option.selected) {
        option.selected = false;
      }
      if (isAllowed && option.selected) {
        hasSelected = true;
      }
    });
    if (!hasSelected) {
      for (let i = 0; i < operatorSelect.options.length; i++) {
        const option = operatorSelect.options[i];
        if (!option.disabled) {
          option.selected = true;
          break;
        }
      }
    }
  };

  const toggleValue = function() {
    const op = operatorSelect.value;
    const disable = op === 'is_empty' || op === 'not_empty';
    const current = valueContainer.querySelector('[name="trigger_value"]');
    if (!current) {
      return;
    }
    current.disabled = disable;
    if (disable) {
      current.value = '';
    }
  };

    operatorSelect.addEventListener('change', toggleValue);
    if (triggerSelect) {
      triggerSelect.addEventListener('change', function() {
        updateOperatorOptions();
        updateValueControl();
        toggleValue();
      });
    }
    updateOperatorOptions();
    updateValueControl();
    toggleValue();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
JAVASCRIPT
);

echo Html::scriptBlock(<<<JAVASCRIPT
(function() {
  const modalEl = document.getElementById('scRuleModal');
  if (!modalEl) {
    return;
  }
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  const addBtn = document.getElementById('sc_rule_add_btn');
  const form = document.getElementById('sc_rule_form');
  const submitBtn = document.getElementById('sc_rule_submit');
  const titleEl = document.getElementById('scRuleModalTitle');
  const idInput = document.getElementById('sc_rule_id');
  const triggerSelect = document.getElementById('sc_trigger_key');
  const operatorSelect = document.getElementById('sc_rule_operator');
  const actionSelect = document.getElementById('sc_rule_action');
  const requireInput = document.getElementById('sc_rule_require');
  const valueContainer = document.getElementById('sc_trigger_value_container');
  const targetSelect = document.getElementById('sc_rule_target');
  const activeInput = document.getElementById('sc_rule_active');
  const summaryEl = document.getElementById('sc_rule_summary');

  const setSelectValue = function(select, value) {
    if (!select) return;
    Array.prototype.forEach.call(select.options, function(option) {
      option.selected = option.value === value;
    });
  };

  const resetForm = function() {
    if (form) form.reset();
    if (idInput) idInput.value = '';
    if (submitBtn) submitBtn.name = 'add_rule';
    if (titleEl) titleEl.textContent = 'Nouvelle regle';
    if (operatorSelect) setSelectValue(operatorSelect, 'eq');
    if (actionSelect) setSelectValue(actionSelect, 'show');
    if (triggerSelect) setSelectValue(triggerSelect, '');
    if (targetSelect) setSelectValue(targetSelect, '');
    if (activeInput) activeInput.checked = true;
    if (requireInput) requireInput.checked = false;
    if (triggerSelect) {
      triggerSelect.dispatchEvent(new Event('change'));
    }
    if (operatorSelect) {
      operatorSelect.dispatchEvent(new Event('change'));
    }
    const input = valueContainer ? valueContainer.querySelector('[name="trigger_value"]') : null;
    if (input) input.value = '';
  };

  const openForEdit = function(button) {
    resetForm();
    if (idInput) idInput.value = button.getAttribute('data-rule-id') || '';
    if (submitBtn) submitBtn.name = 'update_rule';
    if (titleEl) titleEl.textContent = 'Modifier la regle';
    setSelectValue(triggerSelect, button.getAttribute('data-trigger-key') || '');
    setSelectValue(operatorSelect, button.getAttribute('data-operator') || 'eq');
    setSelectValue(actionSelect, button.getAttribute('data-action') || 'show');
    if (triggerSelect) {
      triggerSelect.dispatchEvent(new Event('change'));
    }
    if (operatorSelect) {
      operatorSelect.dispatchEvent(new Event('change'));
    }
    if (requireInput) requireInput.checked = button.getAttribute('data-require') === '1';
    if (activeInput) activeInput.checked = button.getAttribute('data-active') !== '0';
    const input = valueContainer ? valueContainer.querySelector('[name="trigger_value"]') : null;
    if (input) input.value = button.getAttribute('data-trigger-value') || '';
    setSelectValue(targetSelect, button.getAttribute('data-target-key') || '');
    modal.show();
  };

  if (addBtn) {
    addBtn.addEventListener('click', function() {
      resetForm();
      modal.show();
      buildSummary();
    });
  }

  document.querySelectorAll('.sc-rule-edit').forEach(function(btn) {
    btn.addEventListener('click', function() {
      openForEdit(btn);
      buildSummary();
    });
  });

  const buildSummary = function() {
    if (!summaryEl) {
      return;
    }
    const triggerLabel = triggerSelect && triggerSelect.selectedOptions.length
      ? triggerSelect.selectedOptions[0].textContent.trim()
      : '...';
    const targetLabel = targetSelect && targetSelect.selectedOptions.length
      ? targetSelect.selectedOptions[0].textContent.trim()
      : '...';
    const operatorLabel = operatorSelect && operatorSelect.selectedOptions.length
      ? operatorSelect.selectedOptions[0].textContent.trim()
      : '';
    const actionValue = actionSelect ? actionSelect.value : 'show';
    const actionLabel = actionValue === 'hide' ? 'masqué' : 'affiché';
    let valueText = '';
    const valueInput = valueContainer ? valueContainer.querySelector('[name="trigger_value"]') : null;
    if (operatorSelect && (operatorSelect.value === 'is_empty' || operatorSelect.value === 'not_empty')) {
      valueText = '';
    } else if (valueInput) {
      let displayValue = valueInput.value || '';
      if (valueInput.tagName === 'SELECT' && valueInput.selectedOptions.length) {
        displayValue = valueInput.selectedOptions[0].textContent.trim();
      }
      if (displayValue !== '') {
        valueText = ' ' + displayValue;
      } else {
        valueText = ' ...';
      }
    } else {
      valueText = ' ...';
    }
    const requiredText = requireInput && requireInput.checked ? ' et obligatoire' : '';
    summaryEl.textContent = 'Si ' + triggerLabel + ' ' + operatorLabel + valueText
      + ' alors ' + targetLabel + ' est ' + actionLabel + requiredText;
  };

  if (form) {
    form.addEventListener('input', buildSummary);
    form.addEventListener('change', buildSummary);
  }
})();
JAVASCRIPT
);

echo "<div class='card'>";
echo "<div class='card-header'><h3 class='card-title mb-0'>Questions</h3></div>";
echo "<div class='card-body'>";

if (empty($questions)) {
    echo "<div class='alert alert-info mb-0'>Aucune question configuree.</div>";
} else {
    echo "<div class='d-flex justify-content-between align-items-center mb-2'>";
    echo "<div class='text-muted'>Glisser-deposer les lignes pour changer l'ordre d'affichage.</div>";
    echo "<div class='d-flex gap-2'>";
    echo "<button type='button' class='btn btn-sm btn-primary' id='sc_question_add_btn'>Ajouter une question</button>";
    echo Html::submit('Enregistrer l\'ordre', ['name' => 'save_order', 'class' => 'btn btn-sm btn-outline-primary', 'form' => 'sc-order-form']);
    echo "</div>";
    echo "</div>";
    echo "<form method='post' action='" . $config_url . "' id='sc-order-form' class='mb-2'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => $csrf_token]);
    echo "</form>";

    echo "<div class='table-responsive'>";
    echo "<table class='table table-hover align-middle' id='sc-questions-table'>";
    echo "<thead><tr>";
    echo "<th></th>";
    echo "<th>Ordre</th>";
    echo "<th>Cle</th>";
    echo "<th>Libelle</th>";
    echo "<th>Type</th>";
    echo "<th>Echelle</th>";
    echo "<th>Requis</th>";
    echo "<th>Actif</th>";
    echo "<th class='text-end'>Actions</th>";
    echo "</tr></thead>";
    echo "<tbody>";
    $displayIndex = 1;
    foreach ($questions as $row) {
        $rowId = (int) $row['id'];
        $typeLabel = PluginSatisfactionclientQuestion::getTypeLabel($row['question_type']);
        $scaleLabel = $row['question_type'] === 'rating' ? (int) $row['scale'] : '-';
        echo "<tr data-id='" . $rowId . "'>";
        echo "<td class='text-muted text-center'><span class='sc-drag-handle' draggable='true' title='Deplacer' style='cursor: move;'>::</span>";
        echo "<input type='hidden' name='order[]' value='" . $rowId . "' form='sc-order-form'>";
        echo "</td>";
        echo "<td class='sc-order-index'>" . $displayIndex . "</td>";
        echo "<td>" . htmlescape($row['question_key']) . "</td>";
        echo "<td>" . htmlescape($row['question_label']) . "</td>";
        echo "<td>" . htmlescape($typeLabel) . "</td>";
        echo "<td>" . $scaleLabel . "</td>";
        echo "<td>" . (!empty($row['is_required']) ? 'Oui' : 'Non') . "</td>";
        echo "<td>" . (!empty($row['is_active']) ? 'Oui' : 'Non') . "</td>";
        echo "<td class='text-end'>";
        $displayRule = $rulesByTarget[$row['question_key']]['display'] ?? null;
        $requireRule = $rulesByTarget[$row['question_key']]['require'] ?? null;
        $mailRule = $rulesByTarget[$row['question_key']]['mail'] ?? null;
        $visibilityMode = $rulesByTarget[$row['question_key']]['visibility_mode'] ?? 'always';
        $qAttrs = [
            'data-question-id' => $rowId,
            'data-question-key' => $row['question_key'] ?? '',
            'data-question-label' => $row['question_label'] ?? '',
            'data-question-type' => $row['question_type'] ?? 'text',
            'data-question-scale' => $row['scale'] ?? 5,
            'data-question-required' => !empty($row['is_required']) ? '1' : '0',
            'data-question-active' => !empty($row['is_active']) ? '1' : '0',
            'data-visibility-mode' => $visibilityMode,
            'data-display-trigger' => $displayRule['trigger_key'] ?? '',
            'data-display-operator' => $displayRule['operator'] ?? 'eq',
            'data-display-value' => $displayRule['trigger_value'] ?? '',
            'data-require-trigger' => $requireRule['trigger_key'] ?? '',
            'data-require-operator' => $requireRule['operator'] ?? 'eq',
            'data-require-value' => $requireRule['trigger_value'] ?? '',
            'data-mail-trigger' => $mailRule['trigger_key'] ?? '',
            'data-mail-operator' => $mailRule['operator'] ?? 'eq',
            'data-mail-value' => $mailRule['trigger_value'] ?? '',
            'data-mail-to' => $mailRule['mail_to'] ?? '',
        ];
        $qAttrString = '';
        foreach ($qAttrs as $attr => $value) {
            $qAttrString .= ' ' . $attr . '="' . htmlescape((string) $value) . '"';
        }
        echo "<button type='button' class='btn btn-sm btn-outline-primary me-2 sc-question-edit'{$qAttrString}>Modifier</button>";
        echo "<form method='post' action='" . $config_url . "' style='display:inline-block'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => $csrf_token]);
        echo "<button class='btn btn-sm btn-outline-danger' type='submit' name='delete_question' value='" . $rowId . "' onclick=\"return confirm('Supprimer cette question ?');\">Supprimer</button>";
        echo "</form>";
        echo "</td>";
        echo "</tr>";
        $displayIndex++;
    }
    echo "</tbody>";
    echo "</table>";
    echo "</div>";
}

echo "</div>";
echo "</div>";

echo Html::scriptBlock(<<<JAVASCRIPT
(function() {
  const setupCondition = function(prefix) {
    const triggerSelect = document.getElementById(prefix + '_trigger_key');
    const operatorSelect = document.getElementById(prefix + '_operator');
    const valueContainer = document.getElementById(prefix + '_value_container');
    if (!triggerSelect || !operatorSelect || !valueContainer) {
      return null;
    }

    const createValueControl = function(type, scale, currentValue) {
      let control;
      if (type === 'yesno') {
        control = document.createElement('select');
        control.className = 'form-select';
        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = '--';
        control.appendChild(emptyOption);
        const yesOption = document.createElement('option');
        yesOption.value = '1';
        yesOption.textContent = 'Oui';
        const noOption = document.createElement('option');
        noOption.value = '0';
        noOption.textContent = 'Non';
        control.appendChild(yesOption);
        control.appendChild(noOption);
        if (currentValue === '1' || currentValue === '0') {
          control.value = currentValue;
        }
      } else {
        control = document.createElement('input');
        control.className = 'form-control';
        if (type === 'rating') {
          control.type = 'number';
          control.min = '1';
          if (scale && Number.isFinite(scale)) {
            control.max = String(scale);
          }
          control.step = '1';
        } else if (type === 'number') {
          control.type = 'number';
          control.step = '1';
        } else if (type === 'date') {
          control.type = 'date';
        } else if (type === 'email') {
          control.type = 'email';
        } else {
          control.type = 'text';
        }
        control.value = currentValue || '';
      }
      var namePrefix = prefix === 'sc_display'
        ? 'display'
        : (prefix === 'sc_require' ? 'require' : (prefix === 'sc_mail' ? 'mail' : prefix));
      control.name = namePrefix + '_trigger_value';
      control.id = prefix + '_value';
      return control;
    };

    const updateValueControl = function() {
      const selected = triggerSelect.options[triggerSelect.selectedIndex];
      const type = selected ? (selected.dataset.type || 'text').toLowerCase() : 'text';
      const scale = selected ? parseInt(selected.dataset.scale || '0', 10) : 0;
      const namePrefix = prefix === 'sc_display'
        ? 'display'
        : (prefix === 'sc_require' ? 'require' : (prefix === 'sc_mail' ? 'mail' : prefix));
      const current = valueContainer.querySelector('[name="' + namePrefix + '_trigger_value"]');
      const currentValue = current ? current.value : '';
      const nextControl = createValueControl(type, scale, currentValue);
      valueContainer.innerHTML = '';
      valueContainer.appendChild(nextControl);
    };

    const updateOperatorOptions = function() {
      const selected = triggerSelect.options[triggerSelect.selectedIndex];
      const type = selected ? (selected.dataset.type || 'text').toLowerCase() : 'text';
      let allowed;
      if (type === 'yesno') {
        allowed = ['eq', 'neq', 'is_empty', 'not_empty'];
      } else if (type === 'rating' || type === 'number' || type === 'date') {
        allowed = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'is_empty', 'not_empty'];
      } else {
        allowed = ['eq', 'neq', 'is_empty', 'not_empty'];
      }
      Array.prototype.forEach.call(operatorSelect.options, function(option) {
        const isAllowed = allowed.indexOf(option.value) !== -1;
        option.disabled = !isAllowed;
        option.hidden = !isAllowed;
        if (!isAllowed && option.selected) {
          option.selected = false;
        }
      });
      if (!operatorSelect.value) {
        for (let i = 0; i < operatorSelect.options.length; i++) {
          const option = operatorSelect.options[i];
          if (!option.disabled) {
            option.selected = true;
            break;
          }
        }
      }
    };

    const toggleValue = function() {
      const namePrefix = prefix === 'sc_display'
        ? 'display'
        : (prefix === 'sc_require' ? 'require' : (prefix === 'sc_mail' ? 'mail' : prefix));
      const current = valueContainer.querySelector('[name="' + namePrefix + '_trigger_value"]');
      if (!current) {
        return;
      }
      const op = operatorSelect.value;
      const disable = op === 'is_empty' || op === 'not_empty';
      current.disabled = disable;
      if (disable) {
        current.value = '';
      }
    };

    triggerSelect.addEventListener('change', function() {
      updateOperatorOptions();
      updateValueControl();
      toggleValue();
    });
    operatorSelect.addEventListener('change', toggleValue);
    updateOperatorOptions();
    updateValueControl();
    toggleValue();

    return { triggerSelect, operatorSelect, valueContainer };
  };

  const modalEl = document.getElementById('scQuestionModal');
  if (!modalEl) {
    return;
  }
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  const addBtn = document.getElementById('sc_question_add_btn');
  const form = document.getElementById('sc_question_form');
  const submitBtn = document.getElementById('sc_question_submit');
  const titleEl = document.getElementById('scQuestionModalTitle');
  const nameEl = document.getElementById('scQuestionModalName');
  const idInput = document.getElementById('sc_question_id');
  const keyInput = document.getElementById('sc_question_key');
  const labelInput = document.getElementById('sc_question_label');
  const typeSelect = document.getElementById('sc_question_type');
  const scaleInput = document.getElementById('sc_scale');
  const visibilitySelect = document.getElementById('sc_visibility_mode');
  const requiredInput = document.getElementById('sc_required');
  const activeInput = document.getElementById('sc_active');
  const displaySummary = document.getElementById('sc_display_summary');
  const requireSummary = document.getElementById('sc_require_summary');
  const mailSummary = document.getElementById('sc_mail_summary');
  const mailToInput = document.getElementById('sc_mail_to');

  const displayControls = setupCondition('sc_display');
  const requireControls = setupCondition('sc_require');
  const mailControls = setupCondition('sc_mail');
  const displayTriggerGroup = document.getElementById('sc_display_trigger_group');
  const displayOperatorGroup = document.getElementById('sc_display_operator_group');
  const displayValueGroup = document.getElementById('sc_display_value_group');

  const setSelectValue = function(select, value) {
    if (!select) return;
    Array.prototype.forEach.call(select.options, function(option) {
      option.selected = option.value === value;
    });
  };

  const buildDisplaySummary = function() {
    if (!displaySummary) {
      return;
    }
    const mode = visibilitySelect ? visibilitySelect.value : 'always';
    if (mode === 'always') {
      displaySummary.textContent = 'Toujours visible';
      return;
    }
    const triggerLabel = displayControls && displayControls.triggerSelect.selectedOptions.length
      ? displayControls.triggerSelect.selectedOptions[0].textContent.trim()
      : '...';
    const operatorLabel = displayControls && displayControls.operatorSelect.selectedOptions.length
      ? displayControls.operatorSelect.selectedOptions[0].textContent.trim()
      : '';
    const valueInput = displayControls ? displayControls.valueContainer.querySelector('[name="display_trigger_value"]') : null;
    let valueText = '';
    if (displayControls && (displayControls.operatorSelect.value === 'is_empty' || displayControls.operatorSelect.value === 'not_empty')) {
      valueText = '';
    } else if (valueInput) {
      let displayValue = valueInput.value || '';
      if (valueInput.tagName === 'SELECT' && valueInput.selectedOptions.length) {
        displayValue = valueInput.selectedOptions[0].textContent.trim();
      }
      valueText = displayValue ? ' ' + displayValue : ' ...';
    }
    const modeText = mode === 'hide_by_default' ? 'masquee' : 'affichee';
    displaySummary.textContent = 'Par defaut la question est ' + modeText
      + ', sauf si ' + triggerLabel + ' ' + operatorLabel + valueText;
  };

  const toggleDisplayCondition = function() {
    if (!visibilitySelect) {
      return;
    }
    const enabled = visibilitySelect.value !== 'always';
    [displayTriggerGroup, displayOperatorGroup, displayValueGroup].forEach(function(group) {
      if (group) {
        group.style.display = enabled ? '' : 'none';
      }
    });
    if (!enabled) {
      if (displayControls) {
        setSelectValue(displayControls.triggerSelect, '');
        setSelectValue(displayControls.operatorSelect, 'eq');
        displayControls.triggerSelect.dispatchEvent(new Event('change'));
      }
    }
  };

  const buildRequireSummary = function() {
    if (!requireSummary) {
      return;
    }
    const triggerLabel = requireControls && requireControls.triggerSelect.selectedOptions.length
      ? requireControls.triggerSelect.selectedOptions[0].textContent.trim()
      : '...';
    const operatorLabel = requireControls && requireControls.operatorSelect.selectedOptions.length
      ? requireControls.operatorSelect.selectedOptions[0].textContent.trim()
      : '';
    const valueInput = requireControls ? requireControls.valueContainer.querySelector('[name="require_trigger_value"]') : null;
    let valueText = '';
    if (requireControls && (requireControls.operatorSelect.value === 'is_empty' || requireControls.operatorSelect.value === 'not_empty')) {
      valueText = '';
    } else if (valueInput) {
      let displayValue = valueInput.value || '';
      if (valueInput.tagName === 'SELECT' && valueInput.selectedOptions.length) {
        displayValue = valueInput.selectedOptions[0].textContent.trim();
      }
      valueText = displayValue ? ' ' + displayValue : ' ...';
    }
    requireSummary.textContent = 'Obligatoire si ' + triggerLabel + ' ' + operatorLabel + valueText;
  };

  const buildMailSummary = function() {
    if (!mailSummary) {
      return;
    }
    const triggerLabel = mailControls && mailControls.triggerSelect.selectedOptions.length
      ? mailControls.triggerSelect.selectedOptions[0].textContent.trim()
      : '...';
    const operatorLabel = mailControls && mailControls.operatorSelect.selectedOptions.length
      ? mailControls.operatorSelect.selectedOptions[0].textContent.trim()
      : '';
    const valueInput = mailControls ? mailControls.valueContainer.querySelector('[name="mail_trigger_value"]') : null;
    let valueText = '';
    if (mailControls && (mailControls.operatorSelect.value === 'is_empty' || mailControls.operatorSelect.value === 'not_empty')) {
      valueText = '';
    } else if (valueInput) {
      let displayValue = valueInput.value || '';
      if (valueInput.tagName === 'SELECT' && valueInput.selectedOptions.length) {
        displayValue = valueInput.selectedOptions[0].textContent.trim();
      }
      valueText = displayValue ? ' ' + displayValue : ' ...';
    }
    const mailTo = mailToInput ? mailToInput.value.trim() : '';
    const mailLabel = mailTo !== '' ? mailTo : '...';
    mailSummary.textContent = 'Si ' + triggerLabel + ' ' + operatorLabel + valueText + ' alors envoyer un mail a ' + mailLabel;
  };

  const resetForm = function() {
    if (form) form.reset();
    if (idInput) idInput.value = '';
    if (keyInput) keyInput.value = '';
    if (labelInput) labelInput.value = '';
    if (scaleInput) scaleInput.value = 5;
    if (submitBtn) submitBtn.name = 'add_question';
    if (titleEl) titleEl.textContent = 'Nouvelle question';
    if (nameEl) nameEl.textContent = '';
    if (typeSelect) setSelectValue(typeSelect, 'rating');
    if (visibilitySelect) setSelectValue(visibilitySelect, 'always');
    if (requiredInput) requiredInput.checked = false;
    if (activeInput) activeInput.checked = true;
    if (keyInput) keyInput.readOnly = false;
    if (displayControls) {
      setSelectValue(displayControls.triggerSelect, '');
      setSelectValue(displayControls.operatorSelect, 'eq');
      displayControls.triggerSelect.dispatchEvent(new Event('change'));
    }
    if (requireControls) {
      setSelectValue(requireControls.triggerSelect, '');
      setSelectValue(requireControls.operatorSelect, 'eq');
      requireControls.triggerSelect.dispatchEvent(new Event('change'));
    }
    if (mailControls) {
      setSelectValue(mailControls.triggerSelect, '');
      setSelectValue(mailControls.operatorSelect, 'eq');
      mailControls.triggerSelect.dispatchEvent(new Event('change'));
    }
    if (mailToInput) {
      mailToInput.value = '';
    }
    buildDisplaySummary();
    buildRequireSummary();
    buildMailSummary();
    toggleDisplayCondition();
  };

  const openForEdit = function(button) {
    resetForm();
    if (idInput) idInput.value = button.getAttribute('data-question-id') || '';
    if (keyInput) {
      keyInput.value = button.getAttribute('data-question-key') || '';
      keyInput.readOnly = true;
    }
    if (labelInput) labelInput.value = button.getAttribute('data-question-label') || '';
    if (scaleInput) scaleInput.value = button.getAttribute('data-question-scale') || 5;
    if (submitBtn) submitBtn.name = 'update_question';
    if (titleEl) titleEl.textContent = 'Modifier la question';
    if (nameEl) nameEl.textContent = button.getAttribute('data-question-label') || '';
    setSelectValue(typeSelect, button.getAttribute('data-question-type') || 'text');
    setSelectValue(visibilitySelect, button.getAttribute('data-visibility-mode') || 'always');
    if (requiredInput) requiredInput.checked = button.getAttribute('data-question-required') === '1';
    if (activeInput) activeInput.checked = button.getAttribute('data-question-active') !== '0';
    if (displayControls) {
      setSelectValue(displayControls.triggerSelect, button.getAttribute('data-display-trigger') || '');
      setSelectValue(displayControls.operatorSelect, button.getAttribute('data-display-operator') || 'eq');
      displayControls.triggerSelect.dispatchEvent(new Event('change'));
      const valueInput = displayControls.valueContainer.querySelector('[name="display_trigger_value"]');
      if (valueInput) valueInput.value = button.getAttribute('data-display-value') || '';
    }
    if (requireControls) {
      setSelectValue(requireControls.triggerSelect, button.getAttribute('data-require-trigger') || '');
      setSelectValue(requireControls.operatorSelect, button.getAttribute('data-require-operator') || 'eq');
      requireControls.triggerSelect.dispatchEvent(new Event('change'));
      const valueInput = requireControls.valueContainer.querySelector('[name="require_trigger_value"]');
      if (valueInput) valueInput.value = button.getAttribute('data-require-value') || '';
    }
    if (mailControls) {
      setSelectValue(mailControls.triggerSelect, button.getAttribute('data-mail-trigger') || '');
      setSelectValue(mailControls.operatorSelect, button.getAttribute('data-mail-operator') || 'eq');
      mailControls.triggerSelect.dispatchEvent(new Event('change'));
      const valueInput = mailControls.valueContainer.querySelector('[name="mail_trigger_value"]');
      if (valueInput) valueInput.value = button.getAttribute('data-mail-value') || '';
    }
    if (mailToInput) {
      mailToInput.value = button.getAttribute('data-mail-to') || '';
    }
    buildDisplaySummary();
    buildRequireSummary();
    buildMailSummary();
    toggleDisplayCondition();
    modal.show();
  };

  if (addBtn) {
    addBtn.addEventListener('click', function() {
      resetForm();
      modal.show();
    });
  }

  document.querySelectorAll('.sc-question-edit').forEach(function(btn) {
    btn.addEventListener('click', function() {
      openForEdit(btn);
    });
  });

  if (labelInput && keyInput) {
    let manual = keyInput.value.trim() !== '';
    const slugify = function(value) {
      return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
    };
    const updateAutoKey = function() {
      if (manual || keyInput.readOnly) {
        return;
      }
      keyInput.value = slugify(labelInput.value);
    };
    keyInput.addEventListener('input', function() {
      manual = keyInput.value.trim() !== '';
    });
    labelInput.addEventListener('input', updateAutoKey);
    updateAutoKey();
  }

  if (typeSelect && scaleInput) {
    const toggleScale = function() {
      const isRating = typeSelect.value === 'rating';
      scaleInput.disabled = !isRating;
      if (!isRating && scaleInput.value === '') {
        scaleInput.value = '5';
      }
    };
    typeSelect.addEventListener('change', toggleScale);
    toggleScale();
  }

  const table = document.getElementById('sc-questions-table');
  if (table) {
    const tbody = table.querySelector('tbody');
    let draggingRow = null;
    const refreshIndex = function() {
      const rows = tbody.querySelectorAll('tr[data-id]');
      rows.forEach(function(row, index) {
        const cell = row.querySelector('.sc-order-index');
        if (cell) {
          cell.textContent = String(index + 1);
        }
      });
    };
    tbody.addEventListener('dragstart', function(event) {
      const handle = event.target.closest('.sc-drag-handle');
      if (!handle) {
        return;
      }
      draggingRow = handle.closest('tr[data-id]');
      if (!draggingRow) {
        return;
      }
      event.dataTransfer.effectAllowed = 'move';
      draggingRow.classList.add('table-active');
    });
    tbody.addEventListener('dragend', function() {
      if (draggingRow) {
        draggingRow.classList.remove('table-active');
      }
      draggingRow = null;
    });
    tbody.addEventListener('dragover', function(event) {
      if (!draggingRow) {
        return;
      }
      const row = event.target.closest('tr[data-id]');
      if (!row || row === draggingRow) {
        return;
      }
      event.preventDefault();
      const rect = row.getBoundingClientRect();
      const before = event.clientY < rect.top + rect.height / 2;
      tbody.insertBefore(draggingRow, before ? row : row.nextSibling);
      refreshIndex();
    });
    tbody.addEventListener('drop', function(event) {
      if (draggingRow) {
        event.preventDefault();
      }
    });
    refreshIndex();
  }

  if (form) {
    form.addEventListener('input', function() {
      buildDisplaySummary();
      buildRequireSummary();
      buildMailSummary();
    });
    form.addEventListener('change', function() {
      buildDisplaySummary();
      buildRequireSummary();
      buildMailSummary();
    });
  }

  if (visibilitySelect) {
    visibilitySelect.addEventListener('change', function() {
      toggleDisplayCondition();
      buildDisplaySummary();
    });
  }
  toggleDisplayCondition();
})();
JAVASCRIPT
);

Html::footer();
