# WaldorfshopSeoTest

Isolated test plugin containing a copy of the canonical/head template from
[waldorfweb/pp-waldorfshop-07](https://github.com/waldorfweb/pp-waldorfshop-07),
branch `mobileChanges`, commit `5dc0deda2a6911f465ded4d5eec2d1f1302bc99b`.
Original plugin metadata identifies Universnatur GmbH as author and AGPL-3.0 as license.

## Scope

Only an unfiltered category canonical query equal to `?page=1` is removed.
Page 2+, filters and explicitly forced canonical URLs retain their original handling.
The existing Waldorfshop7 theme, assets, settings and widgets remain in use.
There are no new routes, database migrations, background jobs, scripts or global settings.

## Installation boundaries

- This is a separate plugin and repository, not an update to Waldorfshop7.
- Install and deploy only in the unlinked test set 426.
- Never change the repository connection for plugin 92.
- Never assign this plugin or test set to the live shop without separate approval.
- Start with priority 981, above the existing theme (980), below IO (999).
- Verify in preview: base category, page=1, page=2, filters, product, homepage.
- Verify exactly one canonical and unchanged layout/asset paths.
- Rollback: disable this test plugin and rebuild only the test set.

## Status

Prepared for preview validation. No live rollout or completed T08 audit is implied.
The full T08 work also includes product links/offers URLs and help-page sitemap URLs.
