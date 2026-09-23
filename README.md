# videoFix
Crop Fix Manager: a work queue of studio takes whose auto-crop cut the sign off, tracked per camera angle until re-rendered.

## What it does
- `index.html`: search over `matched_transcriptions`, an unresolved and a resolved queue, and per item the edges the sign leaves (`oob`: top/left/right/bottom).
- A fix is keyed by the middle camera's `m_file` and covers `m_file`, `l_file`, `r_file`, each with its own status.
- `api.php?action=`: `search`, `add_fix`/`remove_fix`, `get_unresolved`/`get_resolved`, `get_fixes`, `update_status` (render callback), `update_oob`, `populate_from_labels` (seeds from `form_data.labels LIKE '%GEBAAR UIT DE BEELD%'`).
- State lives in `<webroot>/videofix_data/crop_fixes.json` (via `sc_paths.php`, written under `flock`), not in the DB and not in the checkout. Seeded from `seed/crop_fixes.json` when missing. `readCropFixes()` upgrades old entry shapes in place.
- Render side: `signlab_drs/services/crop_fix.py` reads `/videoFix/crop_fixes.json` (`.htaccess` rewrites it to `api.php?action=crop_fixes_json`), re-crops, then calls `api.php?action=update_status`. `signlab_drs/tools/reprocess_all_fixes.py` re-renders every entry (read-only).

## Where it runs
- Production VPS: `/web/videoFix`, https://signcollect.nl/videoFix/ (the signCollect-v2 menu links `/videoFix/`, so the path is fixed).
- Demo: dev2 `/web/videoFix`, dev-1 `/srv/signcollect/web/videoFix`.

## Status
Production.

## How to run / deploy
No build step. Deployed by `signlab_signcollect-stack` (`repos.tsv`: `videoFix	signlab_videoFix	main`):
https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack
The deploy must provide `<webroot>/videofix_data/` (owner `www-data`, mode 2775) and move a live `videoFix/crop_fixes.json` there before its `reset --hard`.

## Configuration
- `mysql_config.php` in THIS directory (`require_once 'mysql_config.php'`, not the shared `/web/mysql_config.php`). Copy `mysql_config.example.php`; gitignored.
- The stack does not symlink its root shim here, so a fresh host returns 500 until the file exists. Open: how production supplies it.
- `<webroot>/videofix_data/` must be writable by `www-data`. Needs `mod_rewrite` + `AllowOverride` for the `crop_fixes.json` URL.

## Dependencies
- MySQL `admin_gebarenoverleg`: reads `matched_transcriptions`, `form_data`; never writes.
- `/userProtect.js` guards `index.html`; `api.php` has no auth and sends `Access-Control-Allow-Origin: *`.
- `signlab_drs` crop_fix service (render side, above).
- `signlab_zin/zinCrop/api.php` reads `../../videoFix/crop_fixes.json` from disk directly (still the old path).
