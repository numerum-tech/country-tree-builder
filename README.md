# 🗺️ Générateur d'identifiants de divisions administratives — v0.1

## 🚀 Démarrage rapide

```bash
# Mode CSV - Enrichir un fichier avec parent_id, division_id et division_slug
node division-id-tool.js data.csv output.csv
python3 division-id-tool.py data.csv output.csv
php division-id-tool.php data.csv output.csv

# Mode SQL - Générer un script d'import pour base de données
node division-id-tool.js --sql data.csv inserts.sql
python3 division-id-tool.py --sql data.csv inserts.sql
php division-id-tool.php --sql data.csv inserts.sql
```

## 📌 Contexte et problématique

Dans de nombreux pays, les données territoriales sont structurées selon des découpages administratifs hiérarchiques : régions, départements, communes, quartiers, etc. Toutefois, une problématique critique se pose dans les systèmes d'information publics et sectoriels :

> **A l'échel du pays, il n’existe pas d’identifiant universel, stable et interopérable pour les divisions administratives.**

Cela entraîne :
- Des divergences d’identifiants entre bases (SIG, recensement, santé, éducation...)
- Des difficultés d’alignement et de rapprochement de données multi-sources
- Une impossibilité de maintenir l’historique ou la traçabilité en cas de redécoupage

## 🎯 Objectif de l’outil

Ce module fournit une méthode universelle pour générer des identifiants courts, lisibles, stables et reproductibles, basés sur :
- Le **chemin hiérarchique** complet d’une division
- Un **préfixe standardisé** par type (région, commune, etc.)
- Un **hash base32 du chemin** pour garantir l’unicité

Exemple d’identifiant : `CM-X9Y2ZT`

## ✅ Caractéristiques de l'outil

- Génère un identifiant court : **`<préfixe>-<hash>`** (8 caractères)
- Utilise un **slug** normalisé pour les noms
- S'appuie sur un **répertoire typologique francophone** (voir tableau)
- Traite des fichiers CSV en **mode batch**
- Disponible en **Node.js**, **Python** et **PHP**
- Ignore les lignes non valides (trous hiérarchiques)
- Interrompt le traitement si un type n'a pas de préfixe défini
- **🆕 Génère des scripts SQL** pour l'import direct en base de données
- **🆕 Sans dépendances externes** : toutes les versions utilisent uniquement les modules standard

## 📘 Structure d’un identifiant

| Élément       | Exemple           | Description                                        |
|---------------|-------------------|----------------------------------------------------|
| Préfixe       | `CM`              | Code court basé sur le type de division            |
| Hash base32   | `X9Y2ZT`          | Hash du chemin hiérarchique encodé sur 6 caractères.|
| Identifiant   | `CM-X9Y2ZT`       | ID final stable et unique                          |

Vous pouvez ajuster le nombre de caractères du Hash base32 dans le script mais nous estimons que 6 est un bon compromis entre la longueur et le risque de collision des ID.

## 🧩 Référentiel de préfixes typologiques

Voir fichier : [Prefixes Types Division](./prefixes_types_division.md)

## 📂 Scripts fournis

### 1. Node.js — `division-id-tool.js`

#### 📦 Dépendances :
**Aucune !** Utilise uniquement les modules standard Node.js (fs, crypto).

#### ▶️ Utilisation :

**Mode CSV (enrichissement de données) :**
```bash
node division-id-tool.js data.csv output.csv
```

**Mode SQL (génération de script d'import) :**
```bash
node division-id-tool.js --sql data.csv inserts.sql
```

- Lit un fichier CSV **multi-niveaux** (chaque ligne = une division)
- Déduit dynamiquement le type de la dernière colonne renseignée
- Ignore les lignes avec des trous dans les colonnes intermédiaires
- Ajoute `division_id` et `division_slug` en mode CSV
- Génère des INSERT SQL pour les tables `country_division_types` et `country_divisions` en mode SQL

### 2. Python — `division-id-tool.py`

#### 📦 Dépendances :
**Aucune !** Utilise uniquement les modules standard Python (hashlib, csv, base64, sys, re, unicodedata).

#### ▶️ Utilisation :

**Mode CSV (enrichissement de données) :**
```bash
python3 division-id-tool.py data.csv output.csv
```

**Mode SQL (génération de script d'import) :**
```bash
python3 division-id-tool.py --sql data.csv inserts.sql
```

- Comporte les mêmes règles de validation et de génération que la version Node.js
- Produit des résultats identiques (mêmes IDs, mêmes slugs)

### 3. PHP — `division-id-tool.php`

#### 📦 Dépendances :
**Aucune !** Utilise uniquement les fonctions standard PHP.

#### ▶️ Utilisation :

**Mode CSV (enrichissement de données) :**
```bash
php division-id-tool.php data.csv output.csv
```

**Mode SQL (génération de script d'import) :**
```bash
php division-id-tool.php --sql data.csv inserts.sql
```

- Gère également les hiérarchies incomplètes et les types non reconnus
- Les trois versions produisent des résultats 100% identiques

## 🛢️ Modèle SQL recommandé

Un modèle SQL est fourni (`sample-country-tree.sql`) pour la gestion relationnelle des types et divisions administratives, intégrant :

- Une table `country_division_types` avec code, nom, niveau hiérarchique et lien vers le type parent
- Une table `country_divisions` où l'`id` est l'identifiant généré (ex: CM-X9Y2ZT), avec slug, dates de validité, type et relation hiérarchique

### Structure des tables :

**country_division_types :**
- `id` : identifiant numérique
- `name` : nom du type (REGION, COMMUNE, etc.)
- `code` : préfixe court (RG, CM, etc.)
- `level` : niveau hiérarchique (0 pour le plus haut)
- `parent_type_id` : référence au type parent

**country_divisions :**
- `id` : identifiant généré (ex: RG-G6CIUI) - **clé primaire**
- `type_id` : référence au type de division
- `parent_id` : référence à la division parente (NULL pour les régions)
- `name` : nom de la division
- `division_slug` : chemin hiérarchique complet en slug

Ce modèle est compatible MySQL et prend en charge la traçabilité des évolutions dans le temps (historique).

## 📌 Format attendu du fichier CSV d’entrée

```csv
pays,region,prefecture,commune,quartier
Togo,,,,
Togo,Plateaux,,,
Togo,Plateaux,Ogou,,
Togo,Plateaux,Ogou,Atakpamé,
Togo,Plateaux,Ogou,Atakpamé,Atakpamé Nord
```

Chaque ligne représente une division à un niveau donné, avec les colonnes remplies **de gauche à droite sans saut**. Toute ligne avec des colonnes vides en milieu de parcours est ignorée. La première ligne représente la hierarchie des types de division telle que défini administrativement dans le pays.

## 📄 Format de sortie CSV enrichi

```csv
REGION,PREFECTURE,COMMUNE,parent_id,division_id,division_slug
Maritime,,,RG-G6CIUI,maritime
Maritime,Agoe-Nyivé,,RG-G6CIUI,PR-5F4PAD,maritime.agoe-nyive
Maritime,Agoe-Nyivé,Agoe-Nyivé 2,PR-5F4PAD,CM-3DUSYO,maritime.agoe-nyive.agoe-nyive-2
```

Les colonnes ajoutées permettent de :
- Identifier chaque division de manière unique (`division_id`)
- Connaître la hiérarchie complète (`division_slug`)
- Naviguer dans l'arbre via les relations parent-enfant (`parent_id`)

## 🔐 Avantages de l’approche

- ✅ **Stable** : basé sur le nom + hiérarchie, pas un ID local
- ✅ **Lisible** : format court lisible humainement (préfixe)
- ✅ **Reproductible** : identique dans n’importe quel système
- ✅ **Interopérable** : facilite les correspondances inter-bases
- ✅ **Historisable** : le modèle peut être couplé à des dates de validité

## 📤 Export des données

### Mode CSV
Chaque script produit un fichier enrichi avec les colonnes supplémentaires :
- `parent_id` : identifiant de la division parente (NULL pour le niveau racine)
- `division_id` : identifiant court stable
- `division_slug` : chemin hiérarchique sous forme de slug URL-safe

### Mode SQL (--sql)
Génère un script SQL prêt à l'emploi contenant :
- Les INSERT pour `country_division_types` avec la hiérarchie des types
- Les INSERT pour `country_divisions` avec :
  - Les identifiants générés comme clés primaires
  - Les relations parent-enfant correctement établies
  - Les slugs hiérarchiques complets

Exemple de sortie SQL :
```sql
-- Insert division types
INSERT INTO country_division_types (id, name, code, level, parent_type_id, description) 
VALUES (1, 'REGION', 'RG', 0, null, 'Type de division: REGION');

-- Insert divisions
INSERT INTO country_divisions (id, type_id, parent_id, name, division_slug) 
VALUES ('RG-G6CIUI', 1, NULL, 'Maritime', 'maritime');
INSERT INTO country_divisions (id, type_id, parent_id, name, division_slug) 
VALUES ('PR-5F4PAD', 2, 'RG-G6CIUI', 'Agoe-Nyivé', 'maritime.agoe-nyive');
```

## 🧪 Extensions possibles
- Intégration avec une API REST ou interface web
- Génération de codes QR ou URI pour chaque division
- Liaison avec une base SIG (PostGIS, GeoJSON)
- Gestion d’historique (valid_from, valid_to)
