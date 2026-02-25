# Satisfaction client

Plugin GLPI de questionnaire de satisfaction personnalisable: il permet de définir vos questions, leur ordre, leurs règles conditionnelles et les actions associées (ex: notification email), puis de les exploiter sur les tickets.

Le plugin est conçu pour adapter la collecte de satisfaction à votre processus (support, intervention, hotline, etc.) sans modifier le cœur de GLPI.

## Ce que fait le plugin

- Gère un questionnaire de satisfaction configurable.
- Permet plusieurs types de questions (texte, note/échelle, etc. selon configuration du plugin).
- Gère des règles conditionnelles (affichage, obligation, envoi email selon réponse).
- Permet l'import/export JSON de la configuration.
- Permet de réordonner et d'activer/désactiver les questions.

## Fonctionnement (parcours type)

1. Ouvrir la configuration du plugin.
2. Définir le texte d'introduction affiché avant les questions.
3. Créer les questions (libellé, type, clé, ordre, obligatoire, actif/inactif).
4. Ajouter les règles conditionnelles (quand afficher, rendre obligatoire, envoyer un email).
5. Exporter la configuration JSON pour sauvegarde/versionning.
6. Tester le questionnaire sur un ticket.

## Configuration plugin (lecture rapide)

### Texte d'introduction

- Sert à expliquer au client / utilisateur final l'objectif du questionnaire.
- Bon usage: préciser la durée, la confidentialité et le contexte de la demande de satisfaction.

### Questions

Les principaux réglages d'une question servent à:
- définir le libellé affiché (`question_label`)
- fournir une clé technique stable (`question_key`) utile pour les exports/intégrations
- choisir le type de réponse (`question_type`)
- définir une échelle si question notée (`scale`)
- rendre la question obligatoire (`is_required`)
- activer/désactiver une question sans la supprimer (`is_active`)
- contrôler l'ordre d'affichage (`position`)

### Règles conditionnelles

Les règles permettent de faire varier le questionnaire selon les réponses.
Exemples d'usage:
- afficher une question de détail uniquement si la note est faible
- rendre une question obligatoire si une réponse spécifique est choisie
- envoyer un email à une adresse interne si une réponse correspond à un cas sensible

### Import / export JSON

- `export_config`: produit un JSON de la configuration actuelle (questions + règles).
- `import_config`: réimporte une configuration (utile pour migration entre instances).
- L'import remplace la configuration existante: faire une sauvegarde avant import.

## Prérequis

- GLPI 11.x
- PHP compatible GLPI
- Tables du plugin créées (questions, règles, réponses) via l'installation du plugin

## Droits / profils

- La configuration nécessite les droits GLPI d'administration / configuration adaptés.
- Les hooks du plugin s'exécutent sur les objets ciblés (ex: ticket / suivi) selon le flux GLPI.

## Architecture (résumé court)

- Une configuration centrale pilote l'introduction, les questions et les règles.
- Des classes dédiées gèrent questions / règles / réponses.
- L'import JSON est validé avant écriture et exécuté de façon atomique (transaction).

## Vérifications rapides après mise à jour

- Ouvrir la configuration et sauvegarder sans erreur.
- Créer une question test puis la désactiver/réactiver.
- Ajouter une règle conditionnelle simple.
- Exporter puis réimporter la configuration JSON sur un environnement de test.
