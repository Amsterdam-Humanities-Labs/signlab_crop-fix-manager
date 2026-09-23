# signlab_videoFix
Crop Fix Manager: a work queue of studio takes where the auto-crop cut off the sign. Each camera angle is tracked until it is rendered again.

## What it does
- `index.html` searches `matched_transcriptions` and shows an unresolved and a resolved queue. Each item records which edges the sign crosses (`oob`: top, left, right, bottom).
- The middle camera's `m_file` is the key of a fix. A fix covers `m_file`, `l_file` and `r_file`, each with its own status.
- `api.php?action=`: `search`, `add_fix`/`remove_fix`, `get_unresolved`/`get_resolved`, `get_fixes`, `update_status` (render callback), `update_oob` and `populate_from_labels`. The last one seeds the queue from `form_data.labels LIKE '%GEBAAR UIT DE BEELD%'`.
- The queue lives in `<webroot>/videofix_data/crop_fixes.json`, outside the checkout and not in the database. `sc_paths.php` finds the path; writes happen under `flock`. A missing file is created from `seed/crop_fixes.json`. `readCropFixes()` upgrades old entries in place.
- Render side, in [signlab_drs](https://github.com/Amsterdam-Humanities-Labs/signlab_drs): `services/crop_fix.py` reads `/videoFix/crop_fixes.json`, crops again and calls `api.php?action=update_status`. `.htaccess` rewrites that URL to `api.php?action=crop_fixes_json`. `tools/reprocess_all_fixes.py` renders every entry again and only reads the queue.

## Where it runs
- Production: core server, `/web/videoFix`, <https://signcollect.nl/videoFix/>. The path is fixed, because the SignCollect menu links to `/videoFix/`.
- Demo: dev2 `/web/videoFix`, dev-1 `/srv/signcollect/web/videoFix`.

## Status
Production.

## How to run / deploy
There is no build step. The stack deploys `main` (`repos.tsv` line `videoFix	signlab_videoFix	main`); see
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack).
The deploy must create `<webroot>/videofix_data/` (owner `www-data`, mode 2775). Before its `reset --hard`, it must move a live `videoFix/crop_fixes.json` there.

## Configuration
- `mysql_config.php` in this directory (`require_once 'mysql_config.php'`), not the shared `/web/mysql_config.php`. Copy `mysql_config.example.php`; the file is gitignored.
- The stack does not symlink its root shim here, so a fresh host returns 500 until the file exists. Open question: how production supplies it.
- `<webroot>/videofix_data/` must be writable by `www-data`.
- The `crop_fixes.json` URL needs `mod_rewrite` and `AllowOverride` for `.htaccess`.

## Dependencies
- MySQL `admin_gebarenoverleg`: reads `matched_transcriptions` and `form_data`, never writes.
- `/userProtect.js` guards `index.html`. `api.php` has no login check and sends `Access-Control-Allow-Origin: *`.
- The crop-fix service in signlab_drs (render side, above).
- [signlab_zin](https://github.com/Amsterdam-Humanities-Labs/signlab_zin): `zinCrop/api.php` reads the queue file from disk. It falls back to the old `videoFix/crop_fixes.json` on a host that is not migrated yet.
