=== Dragon Content Decay ===
Contributors: dragoncore
Tags: analytics, content, seo, ga4, traffic
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.4
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

== Screenshots ==

1. Main dashboard showing content performance overview
2. Settings page with Google Analytics configuration
3. Posts list with decay column

== Changelog ==

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
