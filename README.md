# PDF Sealer

A reference implementation for REDCap PDF sealing.

The `watermark` operation adds a visible `FINALIZER TEST` mark to each page using Ghostscript and returns the modified PDF. Ghostscript (`gs`) must be available to the PHP process; if rendering fails, the operation reports a controlled failure and the Framework retains the previous PDF. The `seal` operation remains a placeholder and returns unchanged.

This watermark is for acceptance testing only. Ghostscript rewrites the PDF and may not preserve all advanced PDF features; do not treat this module as a production document sealer.
