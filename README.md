# Up Section Styles

WordPress plugin to create and export reusable style variations for sections and blocks. It registers a custom post type `Section Styles` and lets you infer styles from the editor content, then export them as JSON files under your active theme or merge defaults into `theme.json`.

Author: GEHIN Nicolas — Repository: https://github.com/NicolasCaen/up-section-styles

## Features
- `styles/sections/`
- `styles/blocks/`
- `styles/blocks/<type>/`
- Or merged directly into `theme.json` under `styles.blocks[<type>]`.

## Metabox Fields

- **Exporter en fichier du thème**
  - When checked, saving a `Section Style` will export a JSON file into your theme (unless export target is `theme.json`).

- **Cible d'export**
  - `Styles de section (styles/sections)` → writes to `styles/sections/`
  - `Styles pour blocks (styles/blocks)` → writes to `styles/blocks/`
  - `Style spécifique à un type de block (styles/blocks/<type>)` → writes to `styles/blocks/<type>/`
  - `Écrire dans theme.json (défaut du type de block)` → merges styles into `theme.json` at `styles.blocks[<type>]`

- **Mode type de block**
  - `Multiple (liste de blockTypes)` → uses a comma-separated list in the next field
  - `Un seul type de block` → show a dedicated input for a single block name (e.g., `core/paragraph`)

- **Type de block (single)**
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