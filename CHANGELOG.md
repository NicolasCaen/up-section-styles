# Changelog

Toutes les modifications notables de ce projet seront documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/) et ce projet adhère au [Versioning Sémantique](https://semver.org/lang/fr/).

## [1.1.1] - 2025-09-24
### Corrigé
- Export XML déplacé vers un handler `admin-post` dédié (`handle_export_all`) pour éviter les warnings "Cannot modify header information".
- Ajout de `send_download()` pour nettoyer les buffers et envoyer les headers proprement lors du téléchargement.
- Export XML: écriture du contenu de post dans une section CDATA et usage de nodes texte (plus de `htmlspecialchars`) pour éviter la double-échappement.
- Import XML: décodage des entités HTML si nécessaire avant insertion du contenu pour garantir la restauration des blocs Gutenberg.
- Lint: `get_page_by_path(..., 'OBJECT', ...)` pour la compatibilité.

## [1.1.0] - 2025-09-24
### Ajouté
- Mode d’extraction « Section » enrichi pour `core/group` : couleurs (background, text, gradient), bordures (width, radius, color, style), espacements (padding, margin, blockGap), typographie (fontSize, letterSpacing, lineHeight, textDecoration, writingMode, fontStyle, fontWeight, textTransform, textAlign), layout (justifyContent, alignItems, flexWrap), dimensions (minHeight, aspectRatio), elements (`h1..h6`, `link`, `button`).
- Export Thème (theme.json) depuis « Section » :
  - Les attributs du group parent sont écrits dans `styles.*` (racine).
  - Les attributs des sous-blocs sont écrits dans `styles.blocks[<type>]`.
  - Jamais d’écriture sous `styles.blocks["core/group"]`.
  - Les `elements` sont exclus du niveau block (ils appartiennent à la racine).
- UI :
  - Masquage du champ « Block spécifique » quand Cible = Thème et Mode d’extraction = Section.
  - Sélecteur multi block types (ajout/suppression par tags) quand Cible = « Blocks multiples ».
  - Deux boutons de modèles neutres (remplacent le contenu) :
    - Titre + Paragraphe + Bouton
    - H1–H4 + Texte + Bouton
- Debug : notices de démarrage, avertissements si aucun style n’est détecté, aperçu des clés écrites.

### Modifié
- Fusion dans theme.json en « overlay » (les nouvelles valeurs écrasent les existantes) pour `styles.*` et `styles.blocks[<type>]`.

## [1.0.0] - 2025-09-24
### Ajouté
- Version initiale stable.
- Custom Post Type « Section Styles » avec metabox d’export.
- Cibles d’export :
  - Section → `styles/sections/<slug>.json`
  - Block spécifique → `styles/blocks/<type>/<slug>.json`
  - Blocks multiples → `styles/blocks/<slug>.json`
  - Thème (theme.json) → `styles.blocks[<type>]`
- Modes d’extraction : Section (basique) et Block (inférence par type).
- Validation des types de blocks via `WP_Block_Type_Registry` et normalisation des presets (couleurs, espacements, font-size) en variables CSS.

[1.1.1]: https://github.com/NicolasCaen/up-section-styles/releases/tag/v1.1.1
[1.1.0]: https://github.com/NicolasCaen/up-section-styles/releases/tag/v1.1.0
[1.0.0]: https://github.com/NicolasCaen/up-section-styles/releases/tag/v1.0.0
