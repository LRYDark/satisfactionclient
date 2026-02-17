<?php

class PluginSatisfactionclientSurvey
{
    private const MODAL_ID = 'satisfactionclientModal';
    private const ANSWERS_TABLE = 'glpi_plugin_satisfactionclient_answers';

    private static function getQuestions(): array
    {
        $questions = PluginSatisfactionclientQuestion::getActiveQuestions();
        if (!empty($questions)) {
            return $questions;
        }

        if (PluginSatisfactionclientQuestion::hasAnyQuestions()) {
            return [];
        }

        return PluginSatisfactionclientQuestion::getDefaultQuestions();
    }

    public static function postShowItem(array $params): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }

        $item = $params['item'] ?? null;
        if (!($item instanceof Ticket)) {
            return;
        }

        if (!Session::getLoginUserID()) {
            return;
        }

        if (Session::getCurrentInterface() !== 'helpdesk') {
            return;
        }

        if (method_exists($item, 'isSolved') && !$item->isSolved()) {
            return;
        }

        if (method_exists($item, 'canApprove') && !$item->canApprove()) {
            return;
        }

        $rendered = true;

        echo self::renderModal();
        echo Html::scriptBlock(self::getJavascript());
    }

    public static function handleFollowupAdd($item): void
    {
        if (!($item instanceof ITILFollowup)) {
            return;
        }

        if (Session::getCurrentInterface() !== 'helpdesk') {
            return;
        }

        $input = $item->input ?? [];
        if (empty($input['items_id']) || ($input['itemtype'] ?? '') !== Ticket::getType()) {
            return;
        }

        if (empty($input['_close'])) {
            return;
        }

        $submitted = $input['satisfactionclient_submit'] ?? $_POST['satisfactionclient_submit'] ?? null;
        if (empty($submitted)) {
            return;
        }

        $answers = self::extractAnswers($input);
        if (empty($answers)) {
            return;
        }

        $ticketId = (int) $input['items_id'];
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return;
        }

        $solutionId = self::getLastSolutionId($ticketId);
        $requesterId = (int) (Session::getLoginUserID() ?: 0);
        if (self::hasExistingAnswers($ticketId, $solutionId, $requesterId)) {
            return;
        }

        $techId = self::getMainTechnicianId($ticketId);
        $followupId = (int) $item->getID();
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $questionMap = [];
        foreach (self::getQuestions() as $question) {
            $questionMap[$question['key']] = $question;
        }

        global $DB;
        foreach ($answers as $key => $value) {
            if (!isset($questionMap[$key])) {
                continue;
            }
            $question = $questionMap[$key];
            $cleanValue = trim((string) $value);

            $record = [
                'tickets_id' => $ticketId,
                'entities_id' => (int) $ticket->fields['entities_id'],
                'users_id_requester' => $requesterId,
                'users_id_technician' => $techId,
                'itilsolutions_id' => $solutionId,
                'itilfollowups_id' => $followupId,
                'question_key' => $question['key'],
                'question_label' => $question['label'],
                'answer_value' => null,
                'answer_text' => null,
                'date_answered' => $now,
            ];

            if ($question['type'] === 'text') {
                $record['answer_text'] = $cleanValue;
            } else {
                $record['answer_value'] = $cleanValue;
            }

            $DB->insert(self::ANSWERS_TABLE, $record);
        }

        self::notifyOnRules($ticket, $answers, $questionMap, $requesterId, $techId);
    }

    private static function extractAnswers(array $input): array
    {
        $raw = $input['satisfactionclient'] ?? $_POST['satisfactionclient'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $answers = [];
        foreach (self::getQuestions() as $question) {
            $key = $question['key'];
            if (array_key_exists($key, $raw)) {
                $answers[$key] = $raw[$key];
            }
        }

        return $answers;
    }

    private static function hasExistingAnswers(int $ticketId, int $solutionId, int $requesterId): bool
    {
        $criteria = [
            'tickets_id' => $ticketId,
        ];

        if ($solutionId > 0) {
            $criteria['itilsolutions_id'] = $solutionId;
        }
        if ($requesterId > 0) {
            $criteria['users_id_requester'] = $requesterId;
        }

        $dbu = new DbUtils();
        return $dbu->countElementsInTable(self::ANSWERS_TABLE, $criteria) > 0;
    }

    private static function getMainTechnicianId(int $ticketId): int
    {
        global $DB;

        $taskTable = TicketTask::getTable();
        $iterator = $DB->request([
            'SELECT' => [
                'users_id_tech',
                'SUM' => 'actiontime AS total_time',
            ],
            'FROM' => $taskTable,
            'WHERE' => [
                'tickets_id' => $ticketId,
                'actiontime' => ['>', 0],
                'users_id_tech' => ['>', 0],
            ],
            'GROUP' => 'users_id_tech',
            'ORDER' => ['total_time DESC'],
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return (int) $row['users_id_tech'];
        }

        $iterator = $DB->request([
            'SELECT' => ['users_id'],
            'FROM' => 'glpi_tickets_users',
            'WHERE' => [
                'tickets_id' => $ticketId,
                'type' => CommonITILActor::ASSIGN,
            ],
            'ORDER' => ['id ASC'],
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return (int) $row['users_id'];
        }

        return 0;
    }

    private static function getLastSolutionId(int $ticketId): int
    {
        global $DB;

        $table = ITILSolution::getTable();
        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM' => $table,
            'WHERE' => [
                'itemtype' => Ticket::getType(),
                'items_id' => $ticketId,
            ],
            'ORDER' => ['date_creation DESC', 'id DESC'],
            'LIMIT' => 1,
        ]);

        foreach ($iterator as $row) {
            return (int) $row['id'];
        }

        return 0;
    }

    private static function renderModal(): string
    {
        $questions = self::getQuestions();
        $modalId = self::MODAL_ID;

        ob_start();
        ?>
<div class="modal fade" id="<?php echo $modalId; ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="satisfactionclient-form" autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title">Satisfaction client</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted"><?php echo htmlescape(PluginSatisfactionclientConfig::getIntroText()); ?></p>
          <?php foreach ($questions as $question) {
              $key = $question['key'];
              $label = htmlescape($question['label']);
              $required = !empty($question['required']) ? 'required' : '';
              $requiredMark = !empty($question['required']) ? " <span class=\"text-danger\">*</span>" : '';
              $visibilityMode = $question['visibility_mode'] ?? 'always';
              if ($question['type'] === 'rating') {
                  $scale = (int) ($question['scale'] ?? 5);
                  ?>
                  <div class="mb-3" data-sc-question="<?php echo $key; ?>" data-sc-required="<?php echo !empty($question['required']) ? '1' : '0'; ?>" data-sc-visibility-mode="<?php echo htmlescape($visibilityMode); ?>">
                    <label class="form-label"><?php echo $label . $requiredMark; ?> <span class="sc-required-dynamic text-danger" style="display:none">*</span></label>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                      <div class="text-muted small me-2">Pas satisfait</div>
                      <div class="d-flex flex-wrap align-items-end gap-2">
                        <?php for ($i = 1; $i <= $scale; $i++) {
                            $id = 'sc_' . $key . '_' . $i;
                            ?>
                            <label class="text-center" style="min-width:24px;" for="<?php echo $id; ?>">
                              <span class="d-block small text-muted"><?php echo $i; ?></span>
                              <span class="d-block">
                                <input class="form-check-input m-0" type="radio" data-sc-key="<?php echo $key; ?>" name="satisfactionclient[<?php echo $key; ?>]" id="<?php echo $id; ?>" value="<?php echo $i; ?>" <?php echo $required; ?>>
                              </span>
                            </label>
                        <?php } ?>
                      </div>
                      <div class="text-muted small ms-2">Excellent</div>
                    </div>
                  </div>
                  <?php
              } elseif ($question['type'] === 'yesno') {
                  $yesId = 'sc_' . $key . '_yes';
                  $noId = 'sc_' . $key . '_no';
                  ?>
                  <div class="mb-3" data-sc-question="<?php echo $key; ?>" data-sc-required="<?php echo !empty($question['required']) ? '1' : '0'; ?>" data-sc-visibility-mode="<?php echo htmlescape($visibilityMode); ?>">
                    <label class="form-label"><?php echo $label . $requiredMark; ?> <span class="sc-required-dynamic text-danger" style="display:none">*</span></label>
                    <div class="d-flex gap-3">
                      <div class="form-check">
                        <input class="form-check-input" type="radio" data-sc-key="<?php echo $key; ?>" name="satisfactionclient[<?php echo $key; ?>]" id="<?php echo $yesId; ?>" value="1" <?php echo $required; ?>>
                        <label class="form-check-label" for="<?php echo $yesId; ?>">Oui</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="radio" data-sc-key="<?php echo $key; ?>" name="satisfactionclient[<?php echo $key; ?>]" id="<?php echo $noId; ?>" value="0">
                        <label class="form-check-label" for="<?php echo $noId; ?>">Non</label>
                      </div>
                    </div>
                  </div>
                  <?php
              } elseif (in_array($question['type'], ['short_text', 'email', 'number', 'date'], true)) {
                  $inputType = 'text';
                  if ($question['type'] === 'email') {
                      $inputType = 'email';
                  } elseif ($question['type'] === 'number') {
                      $inputType = 'number';
                  } elseif ($question['type'] === 'date') {
                      $inputType = 'date';
                  }
                  ?>
                  <div class="mb-3" data-sc-question="<?php echo $key; ?>" data-sc-required="<?php echo !empty($question['required']) ? '1' : '0'; ?>" data-sc-visibility-mode="<?php echo htmlescape($visibilityMode); ?>">
                    <label class="form-label" for="sc_<?php echo $key; ?>"><?php echo $label . $requiredMark; ?> <span class="sc-required-dynamic text-danger" style="display:none">*</span></label>
                    <input class="form-control" id="sc_<?php echo $key; ?>" data-sc-key="<?php echo $key; ?>" type="<?php echo $inputType; ?>" name="satisfactionclient[<?php echo $key; ?>]" <?php echo $required; ?>>
                  </div>
                  <?php
              } else {
                  ?>
                  <div class="mb-3" data-sc-question="<?php echo $key; ?>" data-sc-required="<?php echo !empty($question['required']) ? '1' : '0'; ?>" data-sc-visibility-mode="<?php echo htmlescape($visibilityMode); ?>">
                    <label class="form-label" for="sc_<?php echo $key; ?>"><?php echo $label . $requiredMark; ?> <span class="sc-required-dynamic text-danger" style="display:none">*</span></label>
                    <textarea class="form-control" id="sc_<?php echo $key; ?>" data-sc-key="<?php echo $key; ?>" name="satisfactionclient[<?php echo $key; ?>]" rows="3" <?php echo $required; ?>></textarea>
                  </div>
                  <?php
              }
          } ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="button" class="btn btn-primary" data-sc-submit>Valider</button>
        </div>
      </form>
    </div>
  </div>
</div>
        <?php
        return ob_get_clean();
    }

    private static function getJavascript(): string
    {
        $modalId = self::MODAL_ID;
        $rules = PluginSatisfactionclientRule::getActiveRules();
        $rulesJson = json_encode($rules, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<JAVASCRIPT
(function() {
  const modalEl = document.getElementById('$modalId');
  if (!modalEl) {
    return;
  }

  const approveBtn = document.querySelector('form button[name="add_close"]');
  if (!approveBtn) {
    return;
  }

  const approvalForm = approveBtn.closest('form');
  if (!approvalForm) {
    return;
  }

  if (approvalForm.dataset.scBound === '1') {
    return;
  }
  approvalForm.dataset.scBound = '1';

  if (modalEl.parentElement !== document.body) {
    document.body.appendChild(modalEl);
  }

  const modal = new bootstrap.Modal(modalEl, {backdrop: 'static', keyboard: false});
  const modalForm = modalEl.querySelector('#satisfactionclient-form');
  const submitBtn = modalEl.querySelector('[data-sc-submit]');
  const rules = $rulesJson || [];

  const getAnswer = function(key) {
    const inputs = modalForm.querySelectorAll('[data-sc-key="' + key + '"]');
    if (!inputs.length) {
      return '';
    }
    const type = inputs[0].type;
    if (type === 'radio') {
      const checked = modalForm.querySelector('input[name="satisfactionclient[' + key + ']"]:checked');
      return checked ? checked.value : '';
    }
    return inputs[0].value || '';
  };

  const isNumeric = function(value) {
    return value !== '' && !Number.isNaN(Number(value));
  };

  const evaluateRule = function(rule) {
    const actual = getAnswer(rule.trigger);
    const expected = rule.value ?? '';
    switch (rule.operator) {
      case 'eq':
        return actual === expected;
      case 'neq':
        return actual !== expected;
      case 'lt':
        return isNumeric(actual) && isNumeric(expected) && Number(actual) < Number(expected);
      case 'lte':
        return isNumeric(actual) && isNumeric(expected) && Number(actual) <= Number(expected);
      case 'gt':
        return isNumeric(actual) && isNumeric(expected) && Number(actual) > Number(expected);
      case 'gte':
        return isNumeric(actual) && isNumeric(expected) && Number(actual) >= Number(expected);
      case 'is_empty':
        return actual === '';
      case 'not_empty':
        return actual !== '';
      default:
        return false;
    }
  };

  const defaultVisibility = {};
  modalForm.querySelectorAll('[data-sc-question]').forEach(function(wrapper) {
    const key = wrapper.getAttribute('data-sc-question');
    const mode = (wrapper.getAttribute('data-sc-visibility-mode') || 'always').toLowerCase();
    defaultVisibility[key] = mode !== 'hide_by_default';
  });

  const applyRules = function() {
    const requiredMap = {};
    const visibilityMap = {};
    modalForm.querySelectorAll('[data-sc-question]').forEach(function(wrapper) {
      const key = wrapper.getAttribute('data-sc-question');
      requiredMap[key] = wrapper.getAttribute('data-sc-required') === '1';
      visibilityMap[key] = defaultVisibility[key] !== false;
    });

    rules.forEach(function(rule) {
      if (!rule || !rule.trigger || !rule.target) {
        return;
      }
      if (evaluateRule(rule)) {
        const action = (rule.action || 'show').toLowerCase();
        if (action === 'show') {
          visibilityMap[rule.target] = true;
        } else if (action === 'hide') {
          visibilityMap[rule.target] = false;
        } else if (action === 'require') {
          requiredMap[rule.target] = true;
        }
      }
    });

    modalForm.querySelectorAll('[data-sc-question]').forEach(function(wrapper) {
      const key = wrapper.getAttribute('data-sc-question');
      const isVisible = visibilityMap[key] !== false;
      wrapper.style.display = isVisible ? '' : 'none';
      const required = !!requiredMap[key];
      const inputs = modalForm.querySelectorAll('[data-sc-key="' + key + '"]');
      inputs.forEach(function(input) {
        if (!isVisible) {
          input.removeAttribute('required');
          input.disabled = true;
          if (input.type === 'radio' || input.type === 'checkbox') {
            input.checked = false;
          } else {
            input.value = '';
          }
          return;
        }
        input.disabled = false;
        if (required) {
          input.setAttribute('required', 'required');
        } else if (wrapper.getAttribute('data-sc-required') !== '1') {
          input.removeAttribute('required');
        }
      });

      const mark = wrapper.querySelector('.sc-required-dynamic');
      if (mark) {
        mark.style.display = required && wrapper.getAttribute('data-sc-required') !== '1' ? 'inline' : 'none';
      }
    });
  };

  approveBtn.addEventListener('click', function(event) {
    event.preventDefault();
    modal.show();
    applyRules();
  });

  modalForm.addEventListener('input', applyRules);
  modalForm.addEventListener('change', applyRules);

  submitBtn.addEventListener('click', function() {
    applyRules();
    if (!modalForm.reportValidity()) {
      return;
    }

    const formData = new FormData(modalForm);
    approvalForm.querySelectorAll('input[name^="satisfactionclient"]').forEach(function(el) {
      el.remove();
    });

    formData.forEach(function(value, key) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      input.value = value;
      approvalForm.appendChild(input);
    });

    const flag = document.createElement('input');
    flag.type = 'hidden';
    flag.name = 'satisfactionclient_submit';
    flag.value = '1';
    approvalForm.appendChild(flag);

    if (!approvalForm.querySelector('input[name="add_close"]')) {
      const closeInput = document.createElement('input');
      closeInput.type = 'hidden';
      closeInput.name = 'add_close';
      closeInput.value = '1';
      approvalForm.appendChild(closeInput);
    }

    modal.hide();
    approvalForm.submit();
  });
})();
JAVASCRIPT;
    }

    private static function notifyOnRules(
        Ticket $ticket,
        array $answers,
        array $questionMap,
        int $requesterId,
        int $techId
    ): void {
        $rules = PluginSatisfactionclientRule::getActiveRules();
        if (empty($rules)) {
            return;
        }

        $emailRules = [];
        foreach ($rules as $rule) {
            if (($rule['action'] ?? '') === 'email' && !empty($rule['mail_to'])) {
                $emailRules[] = $rule;
            }
        }
        if (empty($emailRules)) {
            return;
        }

        foreach ($emailRules as $rule) {
            if (!self::evaluateRule($rule, $answers)) {
                continue;
            }
            self::sendRuleEmail($ticket, $rule, $answers, $questionMap, $requesterId, $techId);
        }
    }

    private static function evaluateRule(array $rule, array $answers): bool
    {
        $key = (string) ($rule['trigger'] ?? '');
        $operator = (string) ($rule['operator'] ?? '');
        $expected = (string) ($rule['value'] ?? '');
        $actual = self::getAnswerValue($answers, $key);

        switch ($operator) {
            case 'eq':
                return $actual === $expected;
            case 'neq':
                return $actual !== $expected;
            case 'lt':
                return self::isNumeric($actual) && self::isNumeric($expected) && (float) $actual < (float) $expected;
            case 'lte':
                return self::isNumeric($actual) && self::isNumeric($expected) && (float) $actual <= (float) $expected;
            case 'gt':
                return self::isNumeric($actual) && self::isNumeric($expected) && (float) $actual > (float) $expected;
            case 'gte':
                return self::isNumeric($actual) && self::isNumeric($expected) && (float) $actual >= (float) $expected;
            case 'is_empty':
                return $actual === '';
            case 'not_empty':
                return $actual !== '';
            default:
                return false;
        }
    }

    private static function getAnswerValue(array $answers, string $key): string
    {
        if ($key === '' || !array_key_exists($key, $answers)) {
            return '';
        }

        $value = $answers[$key];
        if (is_array($value)) {
            return trim(implode(', ', $value));
        }

        return trim((string) $value);
    }

    private static function isNumeric(string $value): bool
    {
        return $value !== '' && is_numeric($value);
    }

    private static function sendRuleEmail(
        Ticket $ticket,
        array $rule,
        array $answers,
        array $questionMap,
        int $requesterId,
        int $techId
    ): void {
        global $CFG_GLPI;

        $recipients = self::parseRecipients((string) ($rule['mail_to'] ?? ''));
        if (empty($recipients)) {
            return;
        }

        $ticketId = (int) $ticket->getID();
        $ticketName = (string) ($ticket->fields['name'] ?? '');
        $entityLabel = Dropdown::getDropdownName('glpi_entities', (int) ($ticket->fields['entities_id'] ?? 0));
        $ticketLink = method_exists($ticket, 'getLinkURL') ? $ticket->getLinkURL() : '';
        if ($ticketLink !== '' && strpos($ticketLink, 'http') !== 0 && !empty($CFG_GLPI['url_base'])) {
            $base = rtrim($CFG_GLPI['url_base'], '/');
            $root = isset($CFG_GLPI['root_doc']) ? rtrim($CFG_GLPI['root_doc'], '/') : '';
            if ($root !== '' && strpos($ticketLink, $root . '/') === 0) {
                $ticketLink = $base . substr($ticketLink, strlen($root));
            } else {
                $ticketLink = $base . $ticketLink;
            }
        }

        $requester = self::getUserDisplay($requesterId);
        $technician = self::getUserDisplay($techId);

        $subject = sprintf('[Satisfaction] Ticket #%d - condition declenchee', $ticketId);

        $lines = [];
        $lines[] = 'Ticket : #' . $ticketId . ' ' . $ticketName;
        if ($entityLabel !== '') {
            $lines[] = 'Entite : ' . $entityLabel;
        }
        if ($ticketLink !== '') {
            $lines[] = 'Lien : ' . $ticketLink;
        }
        $lines[] = 'Demandeur : ' . $requester;
        $lines[] = 'Technicien : ' . $technician;
        $lines[] = '';
        $lines[] = 'Reponses :';

        foreach ($answers as $key => $value) {
            $label = $questionMap[$key]['label'] ?? $key;
            $displayValue = self::formatAnswerValue($value, $questionMap[$key]['type'] ?? '');
            $lines[] = '- ' . $label . ' : ' . $displayValue;
        }

        $mailer = new GLPIMailer();
        $email = $mailer->getEmail();
        $email->subject($subject);
        $email->text(implode(PHP_EOL, $lines));

        foreach ($recipients as $recipient) {
            $email->addTo($recipient);
        }

        if (!empty($CFG_GLPI['admin_email'])) {
            $email->from($CFG_GLPI['admin_email']);
        }

        $mailer->send();
    }

    private static function parseRecipients(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[;,\\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $emails = [];
        foreach ($parts as $part) {
            $email = trim($part);
            if ($email === '' || !GLPIMailer::validateAddress($email)) {
                continue;
            }
            $emails[] = $email;
        }

        return array_values(array_unique($emails));
    }

    private static function getUserDisplay(int $userId): string
    {
        if ($userId <= 0) {
            return 'N/A';
        }
        $user = new User();
        if (!$user->getFromDB($userId)) {
            return 'N/A';
        }
        $email = $user->getDefaultEmail();
        $name = $user->getFriendlyName();
        if ($email) {
            return $name . ' <' . $email . '>';
        }
        return $name ?: 'N/A';
    }

    private static function formatAnswerValue($value, string $type): string
    {
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        $value = trim((string) $value);

        if ($type === 'yesno') {
            if ($value === '1') {
                return 'Oui';
            }
            if ($value === '0') {
                return 'Non';
            }
        }

        return $value !== '' ? $value : '-';
    }
}
