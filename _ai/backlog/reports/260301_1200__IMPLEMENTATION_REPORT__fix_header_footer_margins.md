---
filename: "_ai/backlog/reports/260301_1200__IMPLEMENTATION_REPORT__fix_header_footer_margins.md"
title: "Report: Fix Header and Footer Left/Right Margins to Align with Plugin Config"
createdAt: 2026-03-01 12:00
updatedAt: 2026-03-01 12:00
planFile: "_ai/backlog/active/260301_1200__IMPLEMENTATION_PLAN__fix_header_footer_margins.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 1
filesModified: 6
filesDeleted: 0
tags: [shopware, gotenberg, pdf-datasheet, margins]
documentType: IMPLEMENTATION_REPORT
---

# Summary
We resolved the alignment issue where the PDF headers and footers were rendered full-width and did not match the configured page margins. By passing the margin values to the Twig context and adding dynamic padding in the HTML templates, the headers and footers now align with the rest of the document.

# Files Changed
- **Modified**: `src/Controller/PdfDatasheetController.php` (Pass margins array to Twig templates)
- **Modified**: `src/Resources/views/storefront/datasheet/focus_shop_header.html.twig` (Added horizontal padding in inches)
- **Modified**: `src/Resources/views/storefront/datasheet/focus_shop_footer.html.twig` (Added horizontal padding in inches)
- **Modified**: `src/Resources/views/storefront/datasheet/minimal_header.html.twig` (Added horizontal padding in inches)
- **Modified**: `src/Resources/views/storefront/datasheet/minimal_footer.html.twig` (Added horizontal padding in inches)
- **Modified**: `README.md` (Updated instructions on creating custom themes)

# Key Changes
- Injected the `$margins` configuration array into the header and footer rendering processes inside `PdfDatasheetController`.
- Modified default stylesheets in all header/footer HTML documents to apply `padding-left` and `padding-right` dynamically using `margins.marginLeft` and `margins.marginRight`.
- Provided fallback default values (`0.75in`) in Twig templates for safety if margin keys are missing.
- Documented the design pattern inside `README.md` for subsequent custom themes.

# Testing Notes
- Request a PDF generation with various horizontal margin configurations (e.g. `0.5 in`, `1.0 in`) via the Storefront interface.
- Inspect the alignment of header borders/logos and footer text to confirm that they sit flush with the left and right edges of the main body content area.
