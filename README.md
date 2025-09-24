# Up Section Styles (v1.1.1)

Plugin WordPress pour créer des variations de styles réutilisables (sections et blocks). Il enregistre un custom post type `Section Styles`, permet d’inférer des styles depuis le contenu de l’éditeur, puis d’exporter en JSON dans votre thème actif ou d’écrire dans `theme.json`.

Auteur: GEHIN Nicolas — Dépôt: https://github.com/NicolasCaen/up-section-styles

## Nouveautés 1.1.1
- Export XML via un handler `admin-post` dédié (téléchargement propre, sans warnings de headers).
- Export XML écrit le contenu des posts dans des sections CDATA (pas de double-échappement).
- Import XML décode les entités si nécessaire pour restaurer correctement les blocs Gutenberg.
- Nouvelle page « Import/Export » dans le menu `Section Styles` (Exporter tout, Importer un fichier, Importer les modèles par défaut).

## Nouveautés 1.1
- **Mode d’extraction “Section” enrichi**: détection étendue des attributs d’un `core/group` (couleurs, gradient, border, spacing, typography, layout, dimensions, elements h1..h6, link, button).
- **Export Thème (theme.json) depuis “Section”**:
  - Les attributs du group parent sont écrits dans `styles.*` (racine de theme.json).
  - Les attributs des sous-blocs sont écrits dans `styles.blocks[<type>]` (sans `elements` au niveau block).
  - Jamais de `styles.blocks["core/group"]` en mode “Section”.
- **Sélecteurs UI clarifiés**: le champ “Block spécifique” est masqué quand Cible=Thème + Extraction=Section.
- **Sélecteur multi block types** avec ajout/suppression (tags) quand Cible=“Blocks multiples”.
- **Deux modèles neutres** (insèrent/remplacent le contenu sans attributs) pour démarrer rapidement.
- **Mode debug** amélioré: notices de démarrage, avertissements quand aucun style n’est détecté.

## Import/Export XML

- **Où ?** Menu `Section Styles > Import/Export`.
- **Exporter**: bouton « Exporter tous les Section Styles (XML) » génère un fichier `section-styles-export-YYYYMMDD-HHMMSS.xml` via `admin-post.php`.
- **Importer depuis un fichier**: sélectionnez un `.xml` exporté et validez. Les posts sont créés/mis à jour par `slug`.
- **Importer les modèles par défaut**: importe `default/section-styles-default.xml` fourni par le plugin.

Notes:
- L’association se fait par `slug` (mise à jour si déjà présent, création sinon).
- Toutes les metas sont incluses; si besoin, on peut filtrer à l’avenir pour ne garder que `_up_section_style_*`.
- Les presets (`var(--wp--preset--color--...)`, spacings, fonts) doivent exister dans le thème cible pour un rendu identique.
- Les URLs absolues présentes dans le contenu (ex: domaine local) restent telles quelles et peuvent nécessiter un remplacement.

## Cibles d’export
- **Section** → écrit `styles/sections/<slug>.json`.
- **Block spécifique** → écrit `styles/blocks/<type>/<slug>.json`.
- **Blocks multiples** → écrit `styles/blocks/<slug>.json` avec plusieurs `blockTypes`.
- **Thème (theme.json)** → fusionne les styles dans `theme.json`.

## Modes d’extraction
- **Section**
  - Infère les styles depuis le group parent (racine) et ses éléments (h1..h6, link, button).
  - À l’export Thème: écrit les styles racine dans `styles.*` et les styles des sous-blocs dans `styles.blocks[<type>]`.
- **Block**
  - Infère les attributs du type de block choisi (ou de plusieurs types) et les fusionne.

## Utilisation
1. Créez/éditez un “Section Style”.
2. Construisez le contenu dans l’éditeur blocs (Group, titres, texte, boutons…).
3. Dans le metabox:
   - Cochez “Exporter en fichier du thème”.
   - Choisissez la **Cible d’export** (Section, Block spécifique, Blocks multiples, Thème).
   - Sélectionnez le **Mode d’extraction** (Section ou Block).
   - Selon la cible: choisissez un type de block (single) ou ajoutez plusieurs types (multiple).
   - Optionnel: activez **Mode debug**.
4. Enregistrez. Les fichiers sont écrits dans `wp-content/themes/<votre-thème>/styles/…` ou fusionnés dans `theme.json`.

## Modèles neutres (UI du metabox)
- “Insérer un modèle neutre (Titre + Paragraphe + Bouton)”
- “Insérer modèle (H1–H4 + Texte + Bouton)”
Ces modèles n’ajoutent aucun attribut. Ils servent de base pour définir ensuite les styles au niveau du Group et des sous-blocs.

## Détails d’inférence (extraits)
- Couleurs: background, text, gradient (normalisation presets → CSS vars).
- Border: width, radius, color, style (solid par défaut si width sans style).
- Espacements: padding, margin, blockGap.
- Typographie: fontSize (preset→var), letterSpacing, lineHeight, textDecoration, writingMode, fontStyle, fontWeight, textTransform, textAlign.
- Layout: justifyContent, alignItems, flexWrap.
- Dimensions: minHeight, aspectRatio.
- Elements: link (color + textDecoration none), button (text/background), h1..h6 (color), heading (color depuis le premier core/heading interne).

## Debug et validation
- “Mode debug” affiche des notices (démarrage, avertissements/succès).
- Les types de block sont validés via `WP_Block_Type_Registry`.
- Les presets (couleurs, espacements, font-size) sont normalisés en variables CSS.

## Changelog
- 1.1.0
  - Export Thème depuis “Section”: styles racine + styles des sous-blocs, sans `elements` au niveau block.
  - Masquage du champ “Block spécifique” quand Thème + Section.
  - Sélecteur multi block types (UI tags), debug renforcé, inférence Section étendue.
- 1.0.0
  - Première version stable: export Sections/Blocks/Block spécifique, écriture dans theme.json pour un type de block.
  - Required when `Mode type de block = Un seul type de block`

- **BlockTypes (multiple)**
  - Comma-separated list used when `Mode type de block = Multiple`

- **Mode debug**
  - When enabled, the plugin shows verbose notices upon export with the target, file path, and a short preview of the styles written.

## Behavior

### Sections (Multiple)
- The plugin infers styles from content (e.g., background/text colors from Group/Cover, button/link colors, headings, etc.) and merges them at runtime. When exporting, a style variation JSON is created in the `styles/sections/` directory.

### Single Block Mode
- In `Un seul type de block`, the plugin targets a specific block type in the content and captures its direct attributes, mapping them to theme.json schema:
  - `styles.color.background`, `styles.color.text`, `styles.color.gradient`
  - `styles.border.width`, `styles.border.radius`, `styles.border.color`, `styles.border.style` (defaults to `solid` when width is present)
  - `styles.spacing.padding`, `styles.spacing.margin`, `styles.spacing.blockGap`
  - `styles.typography.fontSize` (preset → CSS var), plus `letterSpacing`, `lineHeight`, `textDecoration`, `writingMode`, `fontStyle`, `fontWeight`, `textTransform`, `textAlign`
  - `styles.dimensions.minHeight`, `styles.dimensions.aspectRatio`
  - `styles.elements.link.color.text` (+ `textDecoration: none`)
  - Special normalization for `core/heading`: text color is promoted to `styles.color.text`.

Exports go to `styles/blocks/<type>/` or merge into `theme.json` depending on the selected export target.

## Notes

- Export file name is based on the post slug or title (`<slug>.json`).
- The plugin shows an admin notice after saving with the output path (or an error if the folder cannot be created).
- Runtime merging keeps the site editor preview consistent even without writing files.

## Roadmap

- Optional capture for advanced backgrounds (image/overlay/opacity/duotone) and effects.
- Additional layout keys as needed.