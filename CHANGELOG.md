# Journal des changements

## 1.2.0 — 2026-10-12

Première version pour GLPI 12 (12.0.x). La série 1.1.x reste celle de GLPI 11.

- **Compatible GLPI 12 uniquement.** GLPI 12 déclare le type des propriétés de ses classes (`$rightname`,
  `$dohistory`…) ; une classe du plugin qui les redéfinit doit porter le même type, ce que GLPI 11 refuse. Les deux
  séries ne sont donc pas interchangeables.
- API retirées par GLPI 12 remplacées : `Plugin::getWebDir()`, `Html::displayNotFoundError()`.
- **Plus de jeton CSRF.** GLPI 12 contrôle l'origine de la requête (en-têtes `Sec-Fetch-Site` / `Origin`) : les
  champs `_glpi_csrf_token`, les en-têtes `X-Glpi-Csrf-Token` et les appels à `Session::checkCSRF()` /
  `getNewCSRFToken()` sont retirés.
- Inclusion de `inc/includes.php` retirée (obsolète).
- Aucune migration de base.

## 1.1.4 — 2026-10-06

- **Garanties d'envoi** : si la file d'attente est indisponible (écriture refusée, erreur de base), le mail part en direct comme avant — la file n'empêche jamais un envoi. Un mail envoyé aussitôt est mis en file avec une heure d'envoi décalée de 5 minutes, pour que la tâche « queuednotification » ne l'envoie pas une seconde fois pendant l'envoi immédiat.
- **Mails des règles de satisfaction envoyés par la file d'attente des notifications de GLPI**, aussitôt, comme
  avant : en cas d'échec, le mail reste en file et GLPI le renvoie automatiquement (avant : échec silencieux).
  Visibles dans Administration → File d'attente des notifications. Un mail par destinataire, rattaché au ticket.
  Aucune migration de base.
