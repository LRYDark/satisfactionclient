<?php
/**
 * PluginSatisfactionclientMailqueue — envoi des mails du plugin par la file d'attente des notifications de GLPI
 * (glpi_queuednotifications), comme les notifications natives, en plus de l'envoi immédiat d'avant :
 *   - le mail part aussitôt, comme avant ; la file ne sert que de filet : en cas d'échec, la ligne y reste et la
 *     tâche GLPI « queuednotification » la renvoie (réglages « Nombre d'essais » et « Délai entre deux essais » de
 *     la configuration des courriels) ;
 *   - visibles dans Administration → File d'attente des notifications, tracés dans les journaux mail / mail-error ;
 *   - si la file elle-même est indisponible (écriture refusée, erreur de base), envoi direct comme avant : la file
 *     n'empêche jamais un mail de partir.
 *
 * Copie propre à ce plugin : la même classe existe dans les autres plugins qui en ont besoin, chacun tourne sans
 * les autres.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginSatisfactionclientMailqueue
{
    /** Délai d'une ligne envoyée aussitôt : la tâche « queuednotification » ne la prend pas pendant l'envoi immédiat. */
    const IMMEDIATE_GUARD_SECONDS = 300;

    /**
     * Met un mail en file, une ligne par destinataire (une ligne de file n'a qu'un destinataire), et l'envoie
     * aussitôt si demandé : l'écran qui attend le résultat garde sa réponse immédiate ; une ligne en échec reste
     * en file et GLPI la réessaie.
     *
     * @param array $mail [
     *     'itemtype'         => string  objet du mail (Ticket, User…) : fil de discussion, pièces jointes
     *     'items_id'         => int
     *     'entities_id'      => int
     *     'event'            => string  nom de l'envoi, propre au plugin (identifiant du message)
     *     'subject'          => string
     *     'text'             => string  corps texte
     *     'html'             => ?string corps HTML (facultatif)
     *     'to'               => array   [[adresse, nom], …]
     *     'from'             => ?array  [adresse, nom] ; par défaut l'expéditeur de GLPI pour l'entité
     *     'replyto'          => ?array  [adresse, nom]
     *     'headers'          => array   en-têtes supplémentaires
     *     'attach_documents' => int     NotificationSetting::ATTACH_* (par défaut : aucun document)
     *     'itemtype_trigger' => ?string objet dont les documents sont joints (ATTACH_FROM_TRIGGER_ONLY)
     *     'items_id_trigger' => int
     *     'direct_fallback'  => bool    file indisponible → envoi direct du même mail (défaut : oui). À désactiver
     *                                   quand le mail porte une pièce jointe que seul l'appelant sait joindre.
     * ]
     * @return array ['queued' => int (lignes en file), 'sent' => int (mails partis, file ou repli direct),
     *                'direct' => int (partis par le repli direct), 'errors' => string[],
     *                'pending' => int[] (lignes en file pas encore parties : GLPI les renverra, ou discard())]
     */
    public static function send(array $mail, bool $send_now = false): array
    {
        $out = ['queued' => 0, 'sent' => 0, 'direct' => 0, 'errors' => [], 'pending' => []];

        $entities_id = (int) ($mail['entities_id'] ?? 0);
        $from        = $mail['from'] ?? null;
        if (!is_array($from) || trim((string) ($from[0] ?? '')) === '') {
            $sender = Config::getEmailSender($entities_id);
            $from   = [(string) ($sender['email'] ?? ''), (string) ($sender['name'] ?? '')];
        }
        $itemtype = (string) ($mail['itemtype'] ?? '');
        $items_id = (int) ($mail['items_id'] ?? 0);
        $event    = (string) ($mail['event'] ?? 'plugin');
        $headers  = array_merge([
            'Auto-Submitted'           => 'auto-generated',
            'X-Auto-Response-Suppress' => 'OOF, DR, NDR, RN, NRN',
        ], (array) ($mail['headers'] ?? []));
        $fallback = (bool) ($mail['direct_fallback'] ?? true);

        // Pièces jointes. « Aucun document » ne suffit pas : pour un mail HTML, GLPI lit quand même les documents de
        // l'objet du mail (ticket, utilisateur, entité) et intègre ceux dont le tag apparaît dans le corps — un ancien
        // document sans tag s'intégrerait à tort. Sans document voulu, la source des documents est donc un objet que
        // GLPI écarte sans rien lire (utilisateur n° 0) : aucun document, jamais, comme l'envoi direct d'avant.
        $attach       = (int) ($mail['attach_documents'] ?? NotificationSetting::ATTACH_NO_DOCUMENT);
        $trigger_type = $mail['itemtype_trigger'] ?? null;
        $trigger_id   = (int) ($mail['items_id_trigger'] ?? 0);
        if ($attach === NotificationSetting::ATTACH_NO_DOCUMENT) {
            $attach       = NotificationSetting::ATTACH_FROM_TRIGGER_ONLY;
            $trigger_type = User::class;
            $trigger_id   = 0;
        }

        foreach ((array) ($mail['to'] ?? []) as $recipient) {
            $email = trim((string) ($recipient[0] ?? ''));
            if ($email === '') {
                continue;
            }
            $now   = date('Y-m-d H:i:s');
            $input = [
                'itemtype'                 => $itemtype,
                'items_id'                 => $items_id,
                'notificationtemplates_id' => 0,
                'entities_id'              => $entities_id,
                'create_time'              => $now,
                'sender'                   => (string) $from[0],
                'sendername'               => (string) ($from[1] ?? ''),
                'name'                     => (string) ($mail['subject'] ?? ''),
                'body_text'                => (string) ($mail['text'] ?? ''),
                'recipient'                => $email,
                'recipientname'            => (string) ($recipient[1] ?? ''),
                'headers'                  => $headers,
                'event'                    => $event,
                'messageid'                => NotificationTarget::getMessageIdForEvent($itemtype !== '' ? $itemtype : null, $items_id, $event),
                'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
                'attach_documents'         => $attach,
                'itemtype_trigger'         => $trigger_type,
                'items_id_trigger'         => $trigger_id,
            ];
            if ($send_now) {
                // Envoi immédiat juste après : heure d'envoi à +5 min, pour que la tâche « queuednotification » (qui
                // prend les lignes dues dans la minute) ne l'envoie pas une seconde fois pendant l'envoi immédiat.
                // Envoyée, la ligne est close par GLPI ; en échec, GLPI la replanifie ; si le processus s'arrête avant
                // l'envoi, la tâche l'envoie 5 min plus tard.
                $input['send_time'] = date('Y-m-d H:i:s', time() + self::IMMEDIATE_GUARD_SECONDS);
            }
            if (!empty($mail['html'])) {
                $input['body_html'] = (string) $mail['html'];
            }
            $replyto = $mail['replyto'] ?? null;
            if (is_array($replyto) && trim((string) ($replyto[0] ?? '')) !== '') {
                $input['replyto']     = (string) $replyto[0];
                $input['replytoname'] = (string) ($replyto[1] ?? '');
            }

            $queue      = new QueuedNotification();
            $id         = 0;
            $queueError = 'mise en file refusée par GLPI';
            if (trim((string) $input['sender']) === '') {
                // Aucun expéditeur configuré dans GLPI : la file ne sait pas envoyer sans expéditeur, l'envoi direct
                // d'avant, lui, sait le faire (GLPI pose alors son en-tête Sender).
                $queueError = 'aucune adresse d\'expéditeur configurée dans GLPI';
            } else {
                try {
                    $id = (int) $queue->add($input);
                } catch (Throwable $e) {
                    $queueError = $e->getMessage();
                }
            }
            if ($id <= 0) {
                // File indisponible : tracé dans le journal mail-error de GLPI, et le mail part quand même, en direct,
                // comme avant. Une erreur n'est rendue à l'appelant que si le mail n'est pas parti.
                Toolbox::logInFile('mail-error', sprintf(
                    "File d'attente indisponible pour %s (%s) : %s\n",
                    $email,
                    $input['name'],
                    $queueError
                ));
                if ($fallback) {
                    self::sendDirect($input, $out);
                } else {
                    $out['errors'][] = sprintf('mise en file impossible pour %s : %s', $email, $queueError);
                }
                continue;
            }
            $out['queued']++;
            Toolbox::logInFile('mail', sprintf(
                __('%1$s: %2$s'),
                sprintf(__('An email to %s was added to queue'), $email),
                $input['name'] . "\n"
            ));

            if (!$send_now) {
                $out['pending'][] = $id;
                continue;
            }
            // Envoi immédiat, comme les notifications « à envoyer tout de suite » de GLPI. GLPI rend le nombre de mails
            // réellement partis ; les erreurs qu'il pose en message de session sont reprises pour l'appelant.
            $known = count($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? []);
            $sent  = 0;
            $threw = false;
            try {
                // Ligne relue en base : complète (valeurs par défaut de la table), comme GLPI l'attend.
                if ($queue->getFromDB($id)) {
                    $sent = (int) NotificationEventMailing::send([$queue->fields]);
                }
            } catch (Throwable $e) {
                // Transport impossible à ouvrir (SMTP, jeton) : la ligne reste en file, la tâche la renverra.
                $threw           = true;
                $out['errors'][] = $e->getMessage();
            }
            $errors = self::takeErrorMessages($known);
            if ($sent > 0) {
                $out['sent']++;
            } else {
                $out['pending'][] = $id;
                if ($errors !== []) {
                    $out['errors'][] = implode(' ; ', array_unique($errors));
                } elseif (!$threw) {
                    $out['errors'][] = sprintf('envoi à %s en échec', $email);
                }
            }
        }
        return $out;
    }

    /**
     * Retire de la file des lignes pas encore parties : pour un plugin qui réessaie lui-même au passage suivant
     * (sans ce retrait, le mail partirait deux fois : par GLPI et par le plugin).
     */
    public static function discard(array $ids): void
    {
        foreach ($ids as $id) {
            try {
                (new QueuedNotification())->delete(['id' => (int) $id], true);
            } catch (Throwable $e) {
                // Ligne déjà partie ou retirée : rien à faire.
            }
        }
    }

    /** Envoi direct d'un mail préparé pour la file (repli quand la file est indisponible), comme avant la file. */
    private static function sendDirect(array $input, array &$out): void
    {
        try {
            $mailer = new GLPIMailer();
            $email  = $mailer->getEmail();
            foreach ((array) $input['headers'] as $name => $value) {
                $email->getHeaders()->addTextHeader((string) $name, (string) $value);
            }
            if (trim((string) $input['sender']) !== '') {
                $email->from(new \Symfony\Component\Mime\Address($input['sender'], $input['sendername']));
            }
            if (!empty($input['replyto'])) {
                $email->replyTo(new \Symfony\Component\Mime\Address($input['replyto'], (string) ($input['replytoname'] ?? '')));
            }
            $email->to(new \Symfony\Component\Mime\Address($input['recipient'], $input['recipientname']));
            $email->subject($input['name']);
            $email->text($input['body_text']);
            if (!empty($input['body_html'])) {
                $email->html($input['body_html']);
            }
            if ($mailer->send()) {
                $out['sent']++;
                $out['direct']++;
                return;
            }
            $out['errors'][] = sprintf('envoi direct à %s en échec : %s', $input['recipient'], $mailer->getError());
        } catch (Throwable $e) {
            $out['errors'][] = sprintf('envoi direct à %s en échec : %s', $input['recipient'], $e->getMessage());
        }
    }

    /** Erreurs d'envoi posées par GLPI en message de session pendant l'envoi : reprises et retirées. */
    private static function takeErrorMessages(int $known): array
    {
        $all = $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? [];
        if (!is_array($all) || count($all) <= $known) {
            return [];
        }
        $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] = array_slice($all, 0, $known);
        $out = [];
        foreach (array_slice($all, $known) as $message) {
            $text = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', ' — ', (string) $message)), ENT_QUOTES, 'UTF-8'));
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return $out;
    }
}
