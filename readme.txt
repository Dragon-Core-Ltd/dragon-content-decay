=== Dragon Content Decay ===
Contributors: dragoncoreltd
Tags: analytics, content, seo, ga4, traffic
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Identify content losing traffic over time by connecting to Google Analytics 4. Prioritize which posts to refresh.

== Description ==

Dragon Content Decay helps you identify blog posts and pages that are losing traffic over time, so you can prioritize content refreshes and maintain your site's organic visibility.

**Key Features:**

* **Google Analytics 4 Integration** - Connect directly to GA4 to fetch real traffic data
* **Decay Detection** - Automatically identifies posts with declining traffic
* **Visual Dashboard** - See all your content performance at a glance
* **Post List Integration** - Decay scores appear right in your posts list
* **Email Digests** - Weekly or monthly reports of content needing attention
* **Customizable Thresholds** - Set your own decay threshold percentage

**How It Works:**

1. Connect your Google Analytics 4 property
2. The plugin compares your content's traffic over configurable time periods
3. Posts with significant traffic drops are flagged as "decaying"
4. Use the dashboard to prioritize which content to refresh first

**Why Content Decay Matters:**

Search engines favor fresh, updated content. Posts that haven't been refreshed may gradually lose rankings and traffic. By identifying decaying content early, you can:

* Maintain search rankings
* Keep content accurate and relevant
* Maximize ROI from existing content
* Prioritize your content calendar

== External services ==

This plugin connects to Google Analytics 4 to read the traffic figures it needs
to detect declining content. It is not usable without that connection, and you
supply your own Google Cloud OAuth credentials — the plugin does not proxy
anything through Dragon Core.

Two kinds of request are made to Google:

* **Signing in.** When you connect your account, the plugin performs a standard
  OAuth handshake with Google using your client ID and secret, and stores the
  resulting access and refresh tokens in your own database. Tokens are refreshed
  automatically when they expire.
* **Reading analytics.** When you sync — on the schedule you configure, or when
  you press Sync now — the plugin calls the Google Analytics Data API for the
  property you selected. Each request contains your GA4 property ID, a date
  range, and the names of the metrics and dimensions being requested. Google
  returns the page paths and traffic figures already recorded in your own
  Analytics property.

Beyond the credentials themselves — your client ID and secret, the resulting
tokens, and your site's admin URL as the OAuth redirect address — no post
content, site content or WordPress user data is sent to Google by this plugin,
and nothing is sent to Dragon Core.

Google terms of service: https://policies.google.com/terms
Google privacy policy: https://policies.google.com/privacy
Google APIs user data policy: https://developers.google.com/terms/api-services-user-data-policy

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/dragon-content-decay/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Content Decay → Settings to configure
4. Set up Google Cloud credentials and connect to GA4
5. View your content decay dashboard!

**Google Cloud Setup:**

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable the Google Analytics Data API
4. Create OAuth 2.0 credentials (Web application)
5. Add the redirect URI shown in plugin settings
6. Copy Client ID and Secret to plugin settings

== Frequently Asked Questions ==

= What Google Analytics version do I need? =

This plugin works with Google Analytics 4 (GA4) only. Universal Analytics is not supported.

= How is decay calculated? =

Decay score is the percentage change in pageviews between the current period and the previous period of equal length. For example, with a 30-day comparison period, we compare the last 30 days to the 30 days before that.

= What's a good decay threshold? =

The default is -20%, meaning posts that lost more than 20% of their traffic will be flagged. You can adjust this in settings based on your site's traffic patterns.

= Does this work with custom post types? =

Yes! You can select which post types to track in the settings.

= How often does data sync? =

Data syncs automatically once per day. You can also manually sync from the dashboard.

== Changelog ==

= 1.0.9 =
* Fixed: clicking "Sync Now" while a sync is already in progress (for example during the daily run) now shows a clear "already running" message instead of a misleading "Sync complete. Analyzed 0 posts."

= 1.0.8 =
* Improved: the analysis sync now runs within a time budget and works through your content across runs, so very large sites no longer risk the sync timing out mid-way.
* Improved: overlapping syncs (the daily job and a manual sync) can no longer run at the same time.
* Fixed: the "View Dashboard" and "Manage notification settings" links in the digest email now open the correct page.
* Housekeeping: removed an unused internal database table.

= 1.0.6 =
* Compatibility: tested up to WordPress 7.1.
* Fix: the first-run guidance panel now shows its intended styling.
* Housekeeping: corrected the contributor name in the plugin readme.

= 1.0.5 =
* Documentation: full external-services disclosure for the Google Analytics connection.
* New: "Delete data on uninstall" checkbox in settings.
* Fix: debug messages no longer written to the server error log unless WP_DEBUG is on.

= 1.0.4 =
* Fixed: the Google Analytics data client library was missing from the plugin package, so connecting and running reports could fail with a fatal error. It is now bundled correctly.
* Under the hood: the bundled Google libraries were slimmed from ~170MB to ~17MB (faster install and updates) and updated to close published security advisories in HTTP/JWT dependencies.
* New: a clearer get-started panel when Google Analytics is not yet connected.

= 1.0.3 =
* New look: the Dragon design system arrives — a consistent Dragon Core header, cleaner tables, and unified status colours. Purely visual; no behaviour changes.

= 1.0.2 =
* Fix: the GA4 Property ID and Google connection are now carried safely on update (they could be lost on a deactivate then reactivate cycle).

= 1.0.1 =
* Renamed all option, hook, function and constant prefixes to the unique `dragoncontentdecay_` / `DRAGONCONTENTDECAY_` prefix. Existing settings, the Google connection and sync/digest schedules are migrated automatically on update; cached analytics data is unaffected.

= 1.0.0 =
* Initial release
* Google Analytics 4 integration
* Decay detection algorithm
* Admin dashboard
* Email digest notifications
* Post list integration

== Privacy Policy ==

Dragon Content Decay connects to Google Analytics 4 to retrieve traffic data for your content. This requires OAuth authentication with your Google account.

**Data Accessed:**
* Page views, sessions, and traffic metrics for your site's content
* Analytics property information

**Data Storage:**
* OAuth tokens are stored locally in your WordPress database (encrypted)
* Analytics data is cached locally for performance
* No data is sent to Dragon Core or any third parties besides Google

**Third-Party Services:**
This plugin uses the [Google Analytics Data API](https://developers.google.com/analytics/devguides/reporting/data/v1) to retrieve analytics data. Your use of this API is subject to [Google's Privacy Policy](https://policies.google.com/privacy) and [Terms of Service](https://policies.google.com/terms).

For more information, visit [Dragon Core](https://dragoncore.ltd/).

== Upgrade Notice ==

= 1.0.0 =
Initial release. Set up your Google Analytics connection to start tracking content decay.
