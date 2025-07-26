# Préfixes recommandés pour les types de divisions administratives (francophones)

Ce tableau propose un ensemble standardisé de **préfixes courts (2 lettres)** à utiliser pour générer des identifiants stables, accompagnés du **nom de type** en français, d’un **slug** (pour matching) et du **niveau hiérarchique généralement associé**.

| Préfixe | Nom du type           | Slug                  | Niveau hiérarchique estimé |
| ------- | --------------------- | --------------------- | -------------------------- |
| PA      | Pays                  | pays                  | 0                          |
| RG      | Région                | region                | 1                          |
| **PR**  | Préfecture            | prefecture            | 2                          |
| **PV**  | Province              | province              | 1 ou 2                     |
| DP      | Département           | departement           | 2 ou 3                     |
| DI      | District              | district              | 2 ou 3                     |
| AR      | Arrondissement        | arrondissement        | 3                          |
| CM      | Commune               | commune               | 3 ou 4                     |
| VL      | Ville                 | ville                 | 3 ou 4                     |
| QR      | Quartier              | quartier              | 4 ou 5                     |
| **LC**  | Localité              | localite              | 4 ou 5                     |
| SV      | Secteur villageois    | secteur-villageois    | 4 ou 5                     |
| VG      | Village               | village               | 5                          |
| ZS      | Zone sanitaire        | zone-sanitaire        | variable                   |
| ZD      | Zone de développement | zone-de-developpement | variable                   |
| ZE      | Zone électorale       | zone-electorale       | variable                   |
| CC      | Canton                | canton                | variable (souvent rural)   |
| TR      | Territoire            | territoire            | 2 ou 3                     |
| MT      | Métropole             | metropole             | 1                          |

## Notes

- Le **niveau hiérarchique** dépend des pays, ce tableau reste indicatif.
- Le **slug** peut être utilisé pour faire correspondre automatiquement les types lors de l’import CSV.
- Le préfixe doit être **unique dans votre système** : en cas de doublon ou conflit local, vous pouvez en adapter la forme (ex: `RG1`, `DP2`).
- Dans le cas du **Togo** :
  - `PR` est recommandé pour **Préfecture**
  - `LC` peut regrouper les **villes** et **villages** sous le terme générique de **Localité**
  - `PV` reste disponible pour d’autres utilisations comme les **provinces** dans d’autres pays

## Utilisation recommandée

Lors de la génération d’un identifiant court :

```text
<préfixe>-<hash_base32>
```

Exemple : `CM-AB3D2X` pour une **commune**.

## Version

- Format : Markdown compatible GitHub
- Dernière mise à jour : 2025-07-22

