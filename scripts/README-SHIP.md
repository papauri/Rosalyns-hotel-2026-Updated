# ship.php - ship one change to Rosalyn's and Liwonde

CLI only (this folder is deny-all over HTTP). Run from the Rosalyn's project root.

    php scripts/ship.php --files="a.php b.js" [--all-changed] [--tests=LIST] [--message-file=path] [--port] [--commit] [--push] [--verbose]

- `--files` space/comma separated, paths relative to the repo root. `--all-changed` adds everything `git status` shows.
- `--tests` any of `booking,finance,pos,settings,hotel,timezone`, or `all` (default) or `none`. Same scripts as `run_tests.php`.
- Default is a dry run: lint, tests, and an in-memory port plan with verification. Nothing is copied or committed.
- `--port` copies/patches the files into the Liwonde repo (`../Liwonde_Sun_Hotel_2026`, or `--liwonde=path`), verifies, lints and tests there.
- `--commit` implies `--port`, needs `--message-file`, stages exactly the listed files and commits both repos with the same message.
- `--push` (requires `--commit`) runs `git push origin main` in both repos.

Per file: COPY (byte copy, or already equal), PATCH (Rosalyn's HEAD-to-working diff applied to Liwonde), DRIFT-OK (patched, and Liwonde's pre-existing difference is unchanged), FAIL (stops the run).
Refuses `.env`, `backups/`, `cache/`, `logs/`, `storage/`, `invoices/*.pdf`, `config/*local*`, and any repo not on `main`.
Uses GNU diff/patch from Git for Windows when found; otherwise falls back to `git merge-file`. One-line-per-step output; `--verbose` lists every file.
