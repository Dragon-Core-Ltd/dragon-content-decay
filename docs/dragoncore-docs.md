# Dragon Content Decay

Connects to Google Analytics 4 and finds the posts losing traffic - so you refresh them before the rankings go.

## Getting started
Allow about 10-15 minutes the first time: the plugin reads your analytics with your own Google Cloud credentials, so you create those once in Google Cloud Console. The **Setup Guide** at the bottom of **Settings** repeats these steps with links.

### Google Cloud setup
1. In [Google Cloud Console](https://console.cloud.google.com/), create a project (or pick an existing one) and enable the **Google Analytics Data API** (and the **Google Search Console API** if you want the optional Search Console signal).
2. Configure the **OAuth consent screen** (Google Auth Platform): choose **External** as the user type (or **Internal** if you use Google Workspace and connect with an account in that organisation), and fill in the app name and your email address.
3. A new External app starts in **Testing**: only the test users you list can sign in, and Google expires their sign-in after 7 days. Either **publish the app** (**Audience → Publish app**) so the connection lasts, or add the Google account you will connect with under **Test users** and reconnect when it expires.
4. Create an **OAuth client ID** of type **Web application**, add the **Authorized redirect URI** shown in the Setup Guide, and paste the **Client ID** and **Client Secret** into Settings. Save.

Because the app is your own and unverified, Google may show *"Google hasn't verified this app"* when you connect. Choose **Advanced**, then continue to your app.

### Connect and scan
1. **Tools → Content Decay → Settings → Connect Google Analytics.** The plugin requests read-only Analytics access.
2. Enter this site's **GA4 Property ID** (the number shown under **Admin → Property Settings** in Google Analytics) and save.
3. The first scan compares each post's page views over the chosen period (full days, ending yesterday in your site's timezone) with the period of equal length before it, and classifies each post as **Decaying**, **Stable**, or **Growing**. Only the post types chosen under **Post Types to Track** are scored. On a very large property Google Analytics returns only the busiest pages for a period; when some of a post's addresses were left out and could have changed its score, the post is still scored and its row shows **Partial data**.

A post's page views are the sum over every address Google Analytics reports for it, such as a different letter case or an old category path. Feed, embed, AMP, comment-page and paged addresses of a post are left out. On a site installed in a subfolder (for example `example.com/blog`), the subfolder is matched automatically.

## Reading the dashboard
Decaying posts are your refresh queue: each row shows the traffic trend and links straight to the editor. Prioritise the ones with the steepest decline and the most historical traffic - those are recoverable rankings.

The summary cards, the table and the posts-list **Decay** column all judge scores against your current threshold, so changing it in Settings takes effect immediately. The table lists the 100 lowest-scoring published posts; the cards count every tracked published post. **View Analytics** under a post in the posts list opens the dashboard with that post's figures at the top.

If a sync cannot fetch data from Google Analytics, no scores are changed and the dashboard shows the reason next to *Last synced* in plain language (for example: Google could not be reached, the sign-in was not accepted, the API is not enabled, the property was not found or refused access, or the usage limit was reached). **Technical details** under the message shows exactly what Google returned.

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
- **"Access blocked" or "has not completed the Google verification process" when connecting** - the OAuth app is in Testing and the Google account you connected with is not a test user. Add it under **Test users** on the OAuth consent screen, or publish the app, then connect again.
- **The connection stops working every week** - an External app in Testing gets sign-ins that Google expires after 7 days. Publish the app on the OAuth consent screen and connect again.
- **"The Google Analytics Data API is not enabled"** - enable it in the same Google Cloud project your Client ID belongs to, then sync again.
- **A message after returning from Google** - a cancelled or refused sign-in, a sign-in that did not complete (check the Client ID and Client Secret) or one that expired or did not start from this site each leave the site unconnected and say which; connect again.
- **"Connect" fails after approving** - check the **GA4 Property ID** in Settings: it must be this site's property, and the Google account must have access to it.
- **"Google no longer accepts this site's saved sign-in"** - the refresh token was revoked or expired (for example, access was removed in your Google account). Syncing stops until you connect again from Settings. A temporary failure to refresh is retried after 15 minutes.
- **Numbers look different from GA4's UI** - the plugin reads page views (`screenPageViews`) per page path over full days ending yesterday, adds up a post's addresses and leaves out its feed, embed, AMP, comment-page and paged addresses; GA4's UI often shows filtered or modelled views and includes today.
- **Search Console shows no data** - confirm the Search Console API is enabled in your Google Cloud project and that you reconnected after ticking the option; Search Console data also lags real time by 2–3 days.
