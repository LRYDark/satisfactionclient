# Journal des changements

## 1.1.4 — 2026-10-06

- **Garanties d'envoi** : si la file d'attente est indisponible (écriture refusée, erreur de base), le mail part en direct comme avant — la file n'empêche jamais un envoi. Un mail envoyé aussitôt est mis en file avec une heure d'envoi décalée de 5 minutes, pour que la tâche « queuednotification » ne l'envoie pas une seconde fois pendant l'envoi immédiat.
- **Mails des règles de satisfaction envoyés par la file d'attente des notifications de GLPI**, aussitôt, comme
  avant : en cas d'échec, le mail reste en file et GLPI le renvoie automatiquement (avant : échec silencieux).
  Visibles dans Administration → File d'attente des notifications. Un mail par destinataire, rattaché au ticket.
  Aucune migration de base.
