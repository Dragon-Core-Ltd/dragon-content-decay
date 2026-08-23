# Dragon Content Decay

Connects to Google Analytics 4 and finds the posts losing traffic — so you refresh them before the rankings go.

## Getting started
1. **Tools → Content Decay → Settings → Connect Google Analytics.** The plugin requests read-only Analytics access.
2. Pick this site's GA4 property.
3. The first scan compares recent traffic against your baseline per post and classifies each as **Decaying**, **Stable**, or **Growing**.

## Reading the dashboard
Decaying posts are your refresh queue: each row shows the traffic trend and links straight to the editor. Prioritise the ones with the steepest decline and the most historical traffic — those are recoverable rankings.

## Search Console (optional)
Alongside GA4 traffic, you can pull Google Search Console data to see search **clicks** and **impressions** decline — often the earliest sign a page is slipping:
1. In **Settings**, tick **Search Console** and save.
2. Enable the **Search Console API** in the same Google Cloud project your Client ID belongs to.
3. **Reconnect to Google** to grant the extra read-only Search Console permission. (GA4-only setups are never forced to reconnect — the extra permission is only requested once you opt in.)
4. Choose your verified property from the dropdown.

The decaying-content dashboard then gains a **Search Clicks** column showing current clicks and the change versus the previous period. GA4 pageviews remain the decay score; Search Console is an additional signal on the same posts.

## Data & privacy
OAuth tokens are stored encrypted in your database; traffic data is fetched from your own GA4 property (and, if enabled, your own Search Console property) and cached locally. Nothing is shared with anyone. **Uninstall keeps data by default** (`wp option update dragoncontentdecay_delete_data_on_uninstall 1` to opt into deletion).

## Troubleshooting
- **"Connect" fails after approving** — check the property picker: the Google account must have access to the GA4 property for this domain.
- **Numbers look different from GA4's UI** — the plugin reads sessions per page path; GA4's UI often shows filtered/modelled views.
- **Search Console shows no data** — confirm the Search Console API is enabled in your Google Cloud project and that you reconnected after ticking the option; Search Console data also lags real time by 2–3 days.
