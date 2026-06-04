---
filename: "_ai/backlog/reports/260309_1400__IMPLEMENTATION_REPORT__fix_pdf_overlap_and_margins.md"
title: "Report: Fix PDF Vertical Overlap and Align Horizontal Margins"
createdAt: 2026-03-09 14:00
updatedAt: 2026-03-09 14:00
planFile: "_ai/backlog/active/260309_1400__IMPLEMENTATION_PLAN__fix_pdf_overlap_and_margins.md"
project: "Topdata PDF Datasheet SW6"
status: completed
filesCreated: 0
filesModified: 6
filesDeleted: 0
tags: [pdf, gotenberg, storefront, templates, layout-fix, overlap-fix]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Fix PDF Vertical Overlap and Align Horizontal Margins

## Summary
Resolved the vertical overlap issue between page content and header/footer templates, while maintaining horizontal margin alignment across both themes.

## Files Changed
* **Modified**:
  * `src/Resources/views/storefront/datasheet/focus_shop.html.twig` - Restored top/bottom `@page` margins, applied horizontal margin through body padding.
  * `src/Resources/views/storefront/datasheet/minimal.html.twig` - Restored top/bottom `@page` margins, applied horizontal margin through body padding.
  * `src/Resources/views/storefront/datasheet/focus_shop_header.html.twig` - Maintained corrected scale-compensated padding.
  * `src/Resources/views/storefront/datasheet/focus_shop_footer.html.twig` - Maintained corrected scale-compensated padding.
  * `src/Resources/views/storefront/datasheet/minimal_header.html.twig` - Maintained corrected scale-compensated padding.
  * `src/Resources/views/storefront/datasheet/minimal_footer.html.twig` - Maintained corrected scale-compensated padding.

## Key Changes
* Applied `@page { margin-left: 0; margin-right: 0; }` alongside explicit vertical page margins (`margin-top` and `margin-bottom`).
* Shifted horizontal margin responsibilities to the body padding on both documents. The main template handles them at 100% scale, and the header/footer layouts compensate for Chromium's 75% render scale using `calc()`.
* Removed all debug borders after verification.
