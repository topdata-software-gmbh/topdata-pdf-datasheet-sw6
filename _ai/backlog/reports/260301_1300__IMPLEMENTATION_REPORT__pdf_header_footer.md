---
filename: "_ai/backlog/reports/260301_1300__IMPLEMENTATION_REPORT__pdf_header_footer.md"
title: "Report: Implement Header and Footer on Every Page of Generated PDF Datasheets"
createdAt: 2026-03-01 13:00
updatedAt: 2026-03-01 13:00
planFile: "_ai/backlog/active/260301_1200__IMPLEMENTATION_PLAN__pdf_header_footer.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 4
filesModified: 4
filesDeleted: 0
tags: [pdf, gotenberg, header, footer]
documentType: IMPLEMENTATION_REPORT
---

# Summary
We successfully updated the PDF generation engine to render headers and footers on every page of the generated PDF document using Gotenberg's Chromium file-injection capabilities. Redundant inline templates inside the main Twig layouts were removed to maintain styling consistency.

# Files Changed
### New Files
- `src/Resources/views/storefront/datasheet/focus_shop_header.html.twig` - Modular header template for the Focus Shop theme.
- `src/Resources/views/storefront/datasheet/focus_shop_footer.html.twig` - Modular footer template for the Focus Shop theme.
- `src/Resources/views/storefront/datasheet/minimal_header.html.twig` - Modular header template for the Minimal theme.
- `src/Resources/views/storefront/datasheet/minimal_footer.html.twig` - Modular footer template for the Minimal theme.

### Modified Files
- `src/Service/GotenbergClient.php` - Added parameters to compile and transmit dynamic `header.html` and `footer.html` multipart payloads.
- `src/Controller/PdfDatasheetController.php` - Configured controller to render distinct header and footer templates per active theme and pass them downstream.
- `src/Resources/views/storefront/datasheet/focus_shop.html.twig` - Refactored main layout to strip inline static header and footer tags.
- `src/Resources/views/storefront/datasheet/minimal.html.twig` - Refactored main layout to strip inline static headers.

# Key Changes
- Integrated global page variables (`pageNumber`, `totalPages`) via native Chromium browser behaviors.
- Set matching CSS margins and padding structures to prevent body copy overlap.
- Enabled multi-page repeating headers/footers for dynamic print environments.

# Technical Decisions
Using separate HTML uploads via the Gotenberg multi-file system is highly recommended over CSS printing workarounds (`position: fixed`). Chromium guarantees that `header.html` and `footer.html` sit in designated margin layers safely separate from document bodies.
