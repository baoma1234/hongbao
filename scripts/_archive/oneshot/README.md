# One-shot scripts (already applied / diagnostics)

Moved here to stop `scripts/` looking like the live migration path.
Reusable ops patches (e.g. `patch_secret_amount_decimal18.php`) stay in `scripts/`.
Do not run these unless you know why; prefer SQL migrations / documented ops scripts.
