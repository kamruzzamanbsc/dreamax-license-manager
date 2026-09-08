# Standalone customer license dashboard design QA

- Source visual truth: the user-provided broken WooCommerce My Account screenshot and the previously accepted Dreamax Affiliates standalone portal screenshots in this conversation. The source screenshots were conversation attachments and did not have local filesystem paths.
- Implementation fixture: `build/ui-qa/dashboard-fixture.html`, using the production `assets/css/dashboard.css` and `assets/js/account.js`.
- Primary implementation screenshot: `build/ui-qa/dashboard-overview-desktop.png`.
- Additional implementation screenshots: `build/ui-qa/dashboard-licenses-desktop.png`, `build/ui-qa/dashboard-claim-tablet.png`, and `build/ui-qa/dashboard-overview-mobile.png`.
- Viewports: 1280 x 900 desktop, 820 x 1100 tablet, and 500 x 1100 compact mobile; CSS pixels and image pixels match at device scale factor 1.
- State: authenticated customer with one assigned Dreamax Affiliates Pro license, a one-site activation limit, and a one-year expiry.

## Full-view comparison evidence

The broken source placed license content in the narrow WooCommerce navigation track and the account menu in the wide content track. The standalone implementation removes that dependency. Desktop uses a stable 236px portal sidebar with a fluid workspace. Tablet converts the sidebar into a compact horizontal navigation area. Compact mobile keeps the portal within the viewport, makes the navigation horizontally scrollable, and uses a two-column metric grid.

The new portal follows the accepted Dreamax affiliate portal hierarchy: dark identity/navigation surface, light workspace, compact numbered navigation, white content cards, clear state badges, and a restrained blue action color. It does not copy WooCommerce My Account layout or inherit its column rules.

## Focused region comparison evidence

The overview metric cards, license detail card, key/action row, claim forms, and compact navigation were inspected separately.

- The desktop license card keeps product, status, order, activation limit, issue date, expiry, masked key, and reveal action aligned without wrapping.
- The tablet claim state keeps both ordered forms equal in height with labels, controls, help text, and actions aligned.
- The compact mobile overview preserves readable headings, 44px navigation/actions, two-column metrics, a full-width recent-license row, and horizontal navigation access.
- No image assets appear in the reference or implementation, so no raster fidelity comparison was required.

## Findings and comparison history

### Initial P1 findings

- WooCommerce and theme selectors reversed the intended navigation/content proportions.
- License keys, dates, and reveal actions wrapped into an unusable narrow column.
- The customer experience had no summary, license health information, product context, activation limit, or clear account state.
- Guest-order claiming lacked a dashboard-level sequence and reliable responsive composition.

### Fixes made

- Added the login-protected `[dreamax_license_dashboard]` standalone shortcode.
- Added endpoint-independent assets detected before the document head and a scoped full-width portal body class.
- Added overview totals for all licenses, ready licenses, 30-day expiries, and licenses that need attention.
- Added product-aware license cards with order, activation limit, issue date, expiry, masked key, and secure reveal/copy.
- Added progressive tab navigation: all content remains available without JavaScript and becomes a focused four-panel portal when JavaScript runs.
- Added a secure login state, private no-store response headers, same-account license queries, nonce-protected guest claiming, and validated same-site form returns.
- Added accessible labels, semantic headings, visible focus styles, 42–44px controls, reduced-motion handling, empty states, status states, and clipboard fallback.

### Post-fix evidence

- Desktop overview: `build/ui-qa/dashboard-overview-desktop.png` has no overflow, wrapping, collision, or clipped persistent controls.
- Desktop license library: `build/ui-qa/dashboard-licenses-desktop.png` keeps all license fields and the reveal action readable in one card.
- Tablet claim flow: `build/ui-qa/dashboard-claim-tablet.png` keeps the two-step workflow aligned and usable.
- Compact mobile overview: `build/ui-qa/dashboard-overview-mobile.png` maintains hierarchy and touch targets without content overflow.
- Production screenshots confirmed the four dashboard states render with the intended stable sidebar and fluid workspace. A follow-up removes the duplicate theme page title through a main-loop-scoped title filter and replaces the fixed desktop height with a viewport-aware clamp; the compact layout has no forced minimum height.
- Production tab-navigation screenshots exposed native panel scrolling beneath the sticky WordPress and site headers. Tab activation now preserves the current viewport while moving keyboard focus without scrolling, and direct hash navigation receives a 170px scroll margin.
- A production compact-mobile screenshot exposed clipped horizontal navigation. At 540px and below, the four portal destinations now use a complete two-column grid with 48px touch targets, visible labels, and no horizontal scrolling.
- Mobile tab changes now reserve the current dashboard height and restore the pre-click viewport position after panel replacement. Focus remains on the activated navigation control, preventing heading focus and shorter panels from pulling the screen upward; the reserved height resets on viewport resize.
- Two physical-phone screenshots showed that the theme's narrow content column was still winning at the compact breakpoint. The portal now breaks out to the viewport with 6px outer padding, 10px workspace rhythm, 14-18px card padding, a compact count badge, stacked product status, and two-column license facts down to 340px.

## Required fidelity surfaces

- Fonts and typography: inherits a stable system/Inter-compatible stack with explicit hierarchy, optical weights, line heights, letter spacing, and wrapping rules.
- Spacing and layout rhythm: 8–38px spacing scale, aligned tracks, 12–22px radii, restrained shadows, and consistent card padding.
- Colors and visual tokens: uses the established Dreamax navy, blue, slate, white, semantic green, and semantic red family with readable contrast.
- Image quality and asset fidelity: no image assets are required by this interface; no placeholder or simulated illustration was introduced.
- Copy and content: labels explain license ownership, expiry, activation limits, secure key handling, guest claiming, and signed-in account state.

## Interaction checks

- Chrome rendered the overview, license, claim, and responsive navigation states from the production CSS and JavaScript.
- Hash-based panel selection and progressive panel hiding ran successfully in the captured license and claim states.
- Reveal/copy retains the ownership-checked AJAX endpoint and now includes a secure-context clipboard path plus a compatible fallback.
- Form return URLs are validated and passed to `wp_safe_redirect`; issue and verify actions retain their action-specific nonces.
- A signed-in production check remains required after the updated plugin and shortcode page are installed because the production theme and caching layer are external to this workspace.

## Follow-up polish

- Corrected the Customer Portal setup heading to remain white over its navy-to-indigo header and advanced the scoped administration asset version so the fix is not hidden by a previously cached stylesheet.
- No P3 visual issue remains in the captured desktop, tablet, or compact mobile states.

final result: passed
