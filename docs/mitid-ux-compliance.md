# MitID UX compliance record

Last reviewed: 2026-08-03

## Authoritative sources

- [Signaturgruppen terms and current MitID material](https://www.signaturgruppen.dk/vilkaar-og-aftaler)
- [Signaturgruppen: Service provider concerns from the MitID UX Scheme](https://www.signaturgruppen.dk/download_file/view/8bfc2dc8-316c-4818-9a94-de81e17139a4/434)
- `UX_scheme_-_extracted.pdf`, supplied for implementation review on 2026-08-03
- `mitid-kommunikationsmaterialer-v01.zip`, supplied for implementation review
  on 2026-08-03
- [IBM Plex Sans](https://github.com/IBM/plex), distributed under the SIL Open
  Font License 1.1
- [W3C WAI-ARIA modal dialog pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/)
- [WCAG 2.2: Status messages](https://www.w3.org/WAI/WCAG22/Understanding/status-messages)
- [WCAG 2.2: Target size minimum](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html)

The public Signaturgruppen page states that contractual and technical broker
documentation can contain additional requirements. Those customer-specific
documents must therefore be checked before each production release; this file
does not replace them.

## Chosen storefront model

The control that starts the MitID client is the official MitID CTA. The
merchant-facing controls that merely open the explanatory modal remain neutral;
they do not start the MitID client and are not presented as MitID buttons.

The official CTA follows the reviewed material:

- English text is `Confirm with MitID`; Danish text is `Bekræft med MitID`.
  Both are listed options in the UX Scheme.
- The button uses the fixed MitID blue `#0060E6`, white text, the supplied white
  MitID logo, IBM Plex Sans SemiBold, and the preferred 4 px corner radius.
- The logo file is copied from the supplied communication package. Only its
  transparent outer canvas is cropped; the visible mark is not redrawn,
  recoloured, distorted, or otherwise changed.
- The full logo renders wider than the 15 mm minimum shown in the visual
  identity manual at the default CSS pixel density.
- The button's MitID colours and typography are intentionally not included in
  the merchant theme variables.
- Every written reference uses the exact `MitID` wordmark.
- Popup mode tells the customer that verification opens in a new window before
  the button starts the flow. Redirect mode omits that notice.
- While the request is starting, the permitted CTA text and logo remain visible;
  progress is announced in a separate live region.

Packaged brand files:

- `assets/mitid-logo-white.png`
- `assets/fonts/IBMPlexSans-SemiBold.woff2`
- `assets/fonts/OFL-1.1.txt`

Asset audit values:

- supplied white-logo SHA-256 before transparent-canvas crop:
  `2dc2a92c74a3c74178d2ade545548eb7beee5104ebc11f3aed440f07e24e2483`
- packaged white-logo SHA-256 after transparent-canvas crop:
  `d3db38a8b142062db7d3f612122ca298e4d6dac82999f3fc3086a0aa0d3aacc0`
- packaged IBM Plex Sans SemiBold SHA-256:
  `f78048030eab62e860efa39a0df79e2e5581bf122eb95b9bc42c0b8a4988d205`

## Responsibility boundary

The plugin owns:

- the neutral cart/checkout prompt and the official MitID CTA in the modal;
- the explanatory modal and its Danish/English copy;
- keyboard, focus, status-message, contrast, and responsive behaviour;
- starting the existing SDK popup or redirect flow.

Signaturgruppen/the broker owns the MitID client shown after the SDK starts.
The registered service-provider name, reference header/body, MitID client
branding, and any broker-specific mandatory text must be configured and
approved there, not reproduced in this plugin.

## Release checks

Before a production release:

1. Re-open both Signaturgruppen sources above and record the new review date.
2. Compare current customer-specific broker documentation with this decision.
3. Confirm that the packaged logo matches the approved source and has not been
   redrawn, recoloured, or distorted.
4. Confirm the fixed blue, white logo/text, IBM Plex Sans SemiBold, 4 px radius,
   permitted CTA texts, and minimum logo size.
5. Confirm that all visible references are spelled `MitID`.
6. Test popup and redirect modes in Danish and English.
7. Confirm the correct service-provider name and reference text in the real
   broker-rendered window.
8. Run the automated and manual accessibility checks documented in the project.

If a current source or customer-specific requirement conflicts with this file,
release is blocked until the implementation and this record are updated.
