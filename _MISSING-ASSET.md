# One asset still missing

Everything else in the Claude Design project **Pastry Lab Order Display**
(`997021d3-4ae9-4074-bdb3-e0d5788c60a7`) is imported and verified.

## What's needed

One image — the **gold logo, 1266 × 1266 PNG**. The design API caps `get_file`
at 256 KiB, and this file is larger, so it only ever came back truncated
(196,608 bytes of 1266×1266 RGBA). Truncated bytes were deliberately not written
rather than leaving a corrupt PNG on disk.

Drop the original into **both** of these paths (they are the same file):

```
assets/logo-auriu.png
uploads/Ratio 1x1 Golden PastryUntitled design (3).png
```

## Notes

- No page references `logo-auriu.png`, so all six `.dc.html` pages already render
  correctly without it. It is a project asset, not a layout dependency.
- The white logo (`logo-alb.png`, 1254 × 1254) had the same problem but was
  recovered from `C:\Users\pleso\Downloads\Untitled design (2).png`, confirmed as
  the original by an exact 196,608-byte prefix match against the truncated
  payload. It is already in place.
- The gold logo's original was not found on this machine — 162 local PNGs across
  Downloads, Desktop, Pictures, Documents and OneDrive were checked against the
  truncated prefix with no match.

To confirm once added:

```bash
node -e "const b=require('fs').readFileSync('assets/logo-auriu.png');console.log(b.length,b.readUInt32BE(16)+'x'+b.readUInt32BE(20),b.subarray(b.length-8,b.length-4).toString('latin1'))"
```

Expect `1266x1266` and a trailing `IEND`.

This file is just a reminder — safe to delete once the logo is in.
