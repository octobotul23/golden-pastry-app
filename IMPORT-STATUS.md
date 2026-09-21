# Import status — Pastry Lab Order Display

Source: Claude Design project `997021d3-4ae9-4074-bdb3-e0d5788c60a7`
Target: `D:\Lab App v2`

## Complete — imported and verified (2026-08-28)

Imported from the project export `Pastry Lab Order Display.zip` rather than the
design API. **71/71 files, all byte-identical to the archive.**

`DesignSync` is still unauthorized in this environment (`/design-login` needs an
interactive terminal), but the export is a strictly better source: the API caps
`get_file` at 256 KiB, which silently truncated `logo-auriu.png` during the
earlier import into `D:\Golden Pastry Lab App`. Six files here exceed that cap —
including `products/trial-pack.png` (2.9 MB) and `assets/logo-auriu.png`
(1.0 MB) — and all arrived intact.

> **Note (2026-09-21, round 2.4.0).** The two rosettes have since swapped
> filenames: what this section calls `logo-auriu.png` — the thick-petalled,
> white-inked, larger file — is now `assets/logo-alb.png`. Their names used to
> say the opposite of their ink; they no longer do. Nothing above is wrong for
> the date it carries, but don't measure today's files against it.

## Verification performed

| Check | Result |
|---|---|
| Byte comparison vs archive (all 71 files) | 0 mismatches |
| Structural validation (`verify-import.mjs`) | 69/69 present and valid |
| Static reference check across all pages | 79 refs resolve, 0 broken |
| Runtime render of all 12 pages | 0 broken images, 0 unresolved templates |
| Console / server errors | none beyond `/favicon.ico` (no favicon in project) |

## Two findings worth keeping

**1. `screens/cat.png` is a JPEG, not a PNG.** Valid and complete (909x540,
intact SOI/EOI) — only the extension is wrong. The verifier dispatched on
extension and reported it as corrupt; it now sniffs magic bytes and reports an
`EXTENSION MISMATCH` warning instead of a false failure. The file is not
referenced anywhere in the project.

**2. `.image-slots.state.json` was missing from the 69-file manifest.** It is
fetched at runtime by `image-slot.js` and supplies three slots (`pf-hero`,
`pf-range-3`, `pf-logistics`) as inline base64 WebP. Without it those slots
render empty. It is present on disk; the manifest simply never listed it, so a
manifest-driven import would have dropped it. `.thumbnail` is likewise present
and unlisted.

## Notes on runtime behaviour (not defects)

Pages briefly request literal `{{ photoRange1 }}`-style URLs and log SVG
attribute warnings before `support.js` hydrates them; the real assets load
immediately after. `Conturi`, `Jurnal`, `Client` and `Administrare Comenzi`
render short because they are auth-gated — the gate itself renders correctly.

## Preview

```bash
node .claude/serve.mjs
```

`launch.json` now sets `autoPort: true` and `serve.mjs` honours `$PORT`, so the
preview no longer collides with another session holding 8099.

## Tooling

| File | Purpose |
|---|---|
| `.claude/expected-files.json` | The 69 manifest files |
| `.claude/verify-import.mjs` | Presence + magic-byte structural check |
| `.claude/serve.mjs` | Static preview server |
| `.claude/launch.json` | Preview launch config |
