# Dragon Content Decay

Connects to Google Analytics 4 and finds the posts losing traffic - so you refresh them before the rankings go.

## Getting started
1. **Tools → Content Decay → Settings → Connect Google Analytics.** The plugin requests read-only Analytics access.
2. Pick this site's GA4 property.
3. The first scan compares recent traffic against your baseline per post and classifies each as **Decaying**, **Stable**, or **Growing**.

## Reading the dashboard
Decaying posts are your refresh queue: each row shows the traffic trend and links straight to the editor. Prioritise the ones with the steepest decline and the most historical traffic - those are recoverable rankings.

The summary cards, the table and the posts-list **Decay** column all judge scores against your current threshold, so changing it in Settings takes effect immediately. The table lists the 100 lowest-scoring published posts; the cards count every tracked published post. **View Analytics** under a post in the posts list opens the dashboard with that post's figures at the top.

If a sync cannot fetch data from Google Analytics, no scores are changed and the dashboard shows the reason next to *Last synced*.

## Email digests
Choose **Weekly** (Monday mornings) or **Monthly** (every 30 days, starting on the 1st) in Settings. The digest goes to the site admin email through your site's normal mailer - the plugin does not set its own From address, so an SMTP plugin's sender settings apply. Each post links to its editor.

## Search Console (optional)
Alongside GA4 traffic, you can pull Google Search Console data to see search **clicks** and **impressions** decline - often the earliest sign a page is slipping:
1. In **Settings**, tick **Search Console** and save.
2. Enable the **Search Console API** in the same Google Cloud project your Client ID belongs to.
3. **Reconnect to Google** to grant the extra read-only Search Console permission. (GA4-only setups are never forced to reconnect - the extra permission is only requested once you opt in.)
4. Choose your verified property from the dropdown.

The decaying-content dashboard then gains a **Search Clicks** column showing current clicks and the change versus the previous period. GA4 pageviews remain the decay score; Search Console is an additional signal on the same posts.

Search Console rows are matched to your posts by path, so a page counts once whether Search Console reports it as `https://`, `http://`, `www.` or non-`www.`, with or without a trailing slash - clicks and impressions from all of those are added together, and the average position is weighted by impressions. Only rows for your site's own domain are counted (the domain of your WordPress home URL, with `www.` and non-`www.` treated as the same site); other subdomains in a Domain property are ignored. If your Search Console property lives on a different host from your WordPress home URL, add it with the `dragoncontentdecay_gsc_site_hosts` filter, which receives the array of accepted host names.

## Data & privacy
OAuth tokens are stored encrypted in your database; traffic data is fetched from your own GA4 property (and, if enabled, your own Search Console property) and cached locally. Nothing is shared with anyone. **Uninstall keeps data by default** (`wp option update dragoncontentdecay_delete_data_on_uninstall 1` to opt into deletion).

## Troubleshooting
- **A message after returning from Google** - a cancelled or refused sign-in, a sign-in that did not complete (check the Client ID and Client Secret) or one that expired or did not start from this site each leave the site unconnected and say which; connect again.
- **"Connect" fails after approving** - check the property picker: the Google account must have access to the GA4 property for this domain.
- **"Google no longer accepts this site's saved sign-in"** - the refresh token was revoked or expired (for example, access was removed in your Google account). Syncing stops until you connect again from Settings. A temporary failure to refresh is retried after 15 minutes.
- **Numbers look different from GA4's UI** - the plugin reads sessions per page path; GA4's UI often shows filtered/modelled views.
- **Search Console shows no data** - confirm the Search Console API is enabled in your Google Cloud project and that you reconnected after ticking the option; Search Console data also lags real time by 2–3 days.
