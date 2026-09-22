# signlab_videoFix
Crop Fix Manager: a work queue of studio takes where the auto-crop cut off the sign. Each camera angle is tracked until it is rendered again.

## What it does
- `index.html` searches `matched_transcriptions` and shows an unresolved and a resolved queue. Each item records which edges the sign crosses (`oob`: top, left, right, bottom).
- The middle camera's `m_file` is the key of a fix. A fix covers `m_file`, `l_file` and `r_file`, each with its own status.
- `api.php?action=`: `search`, `add_fix`/`remove_fix`, `get_unresolved`/`get_resolved`, `get_fixes`, `update_status` (render callback), `update_oob` and `populate_from_labels`. The last one seeds the queue from `form_data.labels LIKE '%GEBAAR UIT DE BEELD%'`.
- The state lives in `crop_fixes.json`, tracked in git and written under `flock`. It is not in the database. `readCropFixes()` upgrades old entries in place.
- Render side, in [signlab_drs](https://github.com/Amsterdam-Humanities-Labs/signlab_drs): `services/crop_fix.py` reads `/videoFix/crop_fixes.json`, crops again and calls `api.php?action=update_status`. `tools/reprocess_all_fixes.py` renders every entry again and only reads the queue.

## Where it runs
- Production: core server, `/web/videoFix`, <https://signcollect.nl/videoFix/>. The path is fixed, because the SignCollect menu links to `/videoFix/`.
- Demo: dev2 `/web/videoFix`, dev-1 `/srv/signcollect/web/videoFix`.

## Status
Production.

## How to run / deploy
There is no build step. The stack deploys `main` (`repos.tsv` line `videoFix	signlab_videoFix	main`); see
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack).
Warning: the deploy's `reset --hard` puts back the committed `crop_fixes.json` and discards the host's state.

## Configuration
- `mysql_config.php` in this directory (`require_once 'mysql_config.php'`), not the shared `/web/mysql_config.php`. Copy `mysql_config.example.php`; the file is gitignored.
- The stack does not symlink its root shim here, so a fresh host returns 500 until the file exists. Open question: how production supplies it.
- `crop_fixes.json` must be writable by `www-data`.

## Dependencies
- MySQL `admin_gebarenoverleg`: reads `matched_transcriptions` and `form_data`, never writes.
- `/userProtect.js` guards `index.html`. `api.php` has no login check and sends `Access-Control-Allow-Origin: *`.
- The crop-fix service in signlab_drs (render side, above).
