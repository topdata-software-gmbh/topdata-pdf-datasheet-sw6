---
filename: "_ai/backlog/reports/250601_1200__IMPLEMENTATION_REPORT__fix_header_footer_overlapping.md"
title: "Report: Fix Header and Footer Overlapping in Generated PDF Datasheets"
createdAt: 2025-06-01 12:15
updatedAt: 2025-06-01 12:15
planFile: "_ai/backlog/active/250601_1200__IMPLEMENTATION_PLAN__fix_header_footer_overlapping.md"
project: "Topdata PDF Datasheet SW6"
status: completed
filesCreated: 0
filesModified: 8
filesDeleted: 0
tags: [pdf, gotenberg, layout, sw6.7]
documentType: IMPLEMENTATION_REPORT
---

## Summary
The overlapping layout issue between page headers/footers and the main body content has been resolved. By removing the hardcoded `@page { margin: 0; }` css declarations and adjusting the page margin system settings, Chromium is now able to utilize proper margin dimensions to keep headers, footers, and body text within separate boundaries.

## Files Changed
- `src/Resources/config/config.xml` (Optimized default top/bottom margins)
- `src/Controller/PdfDatasheetController.php` (Updated fallback default margin settings)
- `src/Resources/views/storefront/datasheet/focus_shop.html.twig` (Removed hardcoded margins, updated padding)
- `src/Resources/views/storefront/datasheet/minimal.html.twig` (Removed body padding, structured page config)
- `src/Resources/views/storefront/datasheet/focus_shop_header.html.twig` (Removed horizontal padding, aligned and shifted content via vertical padding)
- `src/Resources/views/storefront/datasheet/focus_shop_footer.html.twig` (Removed horizontal padding, aligned and shifted content via vertical padding)
- `src/Resources/views/storefront/datasheet/minimal_header.html.twig` (Cleaned layout padding)
- `src/Resources/views/storefront/datasheet/minimal_footer.html.twig` (Cleaned layout padding)

## Key Changes
- Removed CSS `@page { margin: 0; }` configuration inside the product templates which overrode the PDF rendering margins.
- Expanded default page margins in config files from `0.75` inches to `1.2` (Top) and `1.0` (Bottom) to ensure header/footer templates do not overflow the main layout space.
- Adjusted horizontal paddings in the header, footer, and body files to zero, enforcing exact lateral alignment guided natively by the document's left and right margins (`0.75` inches).
- Styled the template bodies with minor vertical spacing offsets to position headers and footers neatly inside their margin zones without touching the physical borders of the paper sheet.

## Technical Decisions
Utilizing CSS settings to define size dimensions while managing print boundaries directly with Gotenberg's API parameters provides the most robust layout separation. This division prevents manual styling variables inside separate Twig files from causing engine-level margin collapse.
