# videoFix — Crop Fix Manager

A work queue for studio takes whose framing is wrong: record that a video needs
re-cropping, track which of its camera angles have been fixed, and mark them
resolved when the render is done.

## What it does

Studio takes are auto-cropped, and sometimes the sign leaves the frame. This
tool is the list of those takes and their state.

`index.html` is the entire UI: a search box over `matched_transcriptions`, an
**unresolved** queue and a **resolved** queue, and a per-item panel of which
edges the sign runs out of (`oob`: top / left / right / bottom). A fix is keyed
by the middle camera's `m_file` and covers all of that row's angles —
`m_file`, `l_file`, `r_file` — each with its own status, so a take can be
half-fixed.

`api.php` is the only endpoint; everything goes through `?action=`:

| Action | Does |
|---|---|
| `search` | `m_file LIKE %query%` over `matched_transcriptions` (50 rows), merged with the recorded fix state |
| `add_fix` / `remove_fix` | Record or withdraw a fix; `add_fix` looks the row's `l_file`/`r_file` up in the database |
| `get_unresolved` / `get_resolved` | The two queues. Resolved means *every* file in the fix is resolved |
| `get_fixes` | Everything recorded, unfiltered |
| `update_status` | Mark one file of one fix `resolved`/`unresolved`. This is the render side's callback |
| `update_oob` | Change which edges are flagged |
| `populate_from_labels` | Seed the queue from `form_data.labels LIKE '%GEBAAR UIT DE BEELD%'` joined through `matched_transcriptions.definitive_outcome` |

**The state lives in `crop_fixes.json`, not in the database** — a tracked file
in this repo, written in place under an exclusive `flock`. That has two
consequences worth knowing: the file in git is production data as of the last
commit, and a deploy that does `reset --hard` (as the demo deploy does)
overwrites whatever the host had accumulated. `readCropFixes()` silently
upgrades older entry shapes (pre-`files[]`, missing `oob`) and writes the
result back.

Four scripts are one-off maintenance, not part of the UI:
`migrate_files.php` and `migrate_web.php` backfill `l_file`/`r_file` into
existing entries (`migrate_web.php` is the same thing restricted to
`127.0.0.1`), `add_missing_files.php` does it for four named files, and
`reset_status.php` flips every resolved entry back to unresolved. All four run
without authentication of any kind — `reset_status.php` needs no database at
all — so they are reachable by URL on a deployed host.

## Where it runs

- **Production:** the signcollect core server (production VPS), served from
  `/web/videoFix` at <https://signcollect.nl/videoFix/>.
- **Demo hosts:** dev2 under `/web/videoFix`, dev-1 under
  `/srv/signcollect/web/videoFix`.

The menu in `signlab_signCollect-v2` links to `/videoFix/` by absolute path, so
the deployed directory name is fixed.

## Status

Production.

## Deploying it

No build step — PHP plus one static HTML page.

Deployment is driven by `interface_deploy/scripts/repos.tsv` in
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack):

```
videoFix	signlab_videoFix	main
```

The host clones this repo itself and the checkout *is* the docroot directory;
each deploy is `fetch → reset --hard → clean → rewrite-urls.sh`. Note that
`reset --hard` restores `crop_fixes.json` to the committed version — see above.

## Configuration

- **`mysql_config.php`, in THIS directory.** `api.php` and the three database
  migration scripts do `require_once 'mysql_config.php'` with no `__DIR__`, so
  it is resolved next to the script — `/web/videoFix/mysql_config.php`, *not*
  the `/web/mysql_config.php` that most of the estate shares. Copy
  `mysql_config.example.php` and fill in `$servername`, `$username`,
  `$password`, `$database`. The real file is gitignored and never deployed.
- TODO: confirm how production supplies that file. The stack deploy ships a
  `mysql_config.php` shim to the docroot **root** and symlinks it into
  `mocapStudio`, `animMIDI` and the annotation editors — but not into
  `videoFix`, so on a freshly deployed host `api.php` returns
  `Connection failed`/500 until someone places one here. A symlink to the
  root shim is the obvious fix; it has not been made.
- `crop_fixes.json` must be writable by the web server user (`www-data`).

## Dependencies

- **MySQL `admin_gebarenoverleg`** — reads `matched_transcriptions` (`m_file`,
  `l_file`, `r_file`, `date`, `rendered`, `post_processed`, `added`) and
  `form_data` (`labels`, `id`). It never writes to the database.
- **The portal session cookie** — `index.html` loads `/userProtect.js` from
  the docroot root. `api.php` itself performs no authentication and sends
  `Access-Control-Allow-Origin: *`.
- **A render side that actually re-crops the videos.** Nothing in this repo
  touches media; something external is expected to poll `get_unresolved` and
  call `update_status` when a file has been re-rendered.
  TODO: confirm what that is. The code comments call it "the render server";
  there is no repo for it in this estate, and `signlab_videoBackgroundFix` is
  a separate tool with its own queue, not this one's worker.
