# Dragon Content Decay

Connects to Google Analytics 4 and finds the posts losing traffic — so you refresh them before the rankings go.

## Getting started
1. **Tools → Content Decay → Settings → Connect Google Analytics.** The plugin requests read-only Analytics access.
2. Pick this site's GA4 property.
3. The first scan compares recent traffic against your baseline per post and classifies each as **Decaying**, **Stable**, or **Growing**.

## Reading the dashboard
Decaying posts are your refresh queue: each row shows the traffic trend and links straight to the editor. Prioritise the ones with the steepest decline and the most historical traffic — those are recoverable rankings.

## Data & privacy
OAuth tokens are stored encrypted in your database; traffic data is fetched from your own GA4 property and cached locally. Nothing is shared with anyone. **Uninstall keeps data by default** (`wp option update dragoncontentdecay_delete_data_on_uninstall 1` to opt into deletion).

## Troubleshooting
- **"Connect" fails after approving** — check the property picker: the Google account must have access to the GA4 property for this domain.
- **Numbers look different from GA4's UI** — the plugin reads sessions per page path; GA4's UI often shows filtered/modelled views.
