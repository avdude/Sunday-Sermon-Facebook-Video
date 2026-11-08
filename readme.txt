=== Sunday Sermon Facebook Video ===
Contributors: avdude
Tags: church, sermon, facebook, video, live stream
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically shows your church's latest Facebook service videos on your website, with a playlist of past services.

== Description ==

Sunday Sermon Facebook Video checks your church's Facebook Page for service videos (videos, Reels, live streams and video posts) and shows the newest one on your website, with a playlist of past services beside it.

* Plays the video directly on your site, even when Facebook's player blocks it because of music.
* Picks out services by phrase (for example "Sunday Worship Service"), untitled Sunday videos, or Sundays only.
* Uses a date written in the title ("service from 4/6") instead of the upload date.
* Shows live services with a LIVE tag, checking every 5 minutes while live.
* Keeps showing the last good video if Facebook can't be reached.
* Optional sermon transcripts through AssemblyAI, with click-to-jump timestamps.
* Optional sermon summaries through AssemblyAI's LLM Gateway or Claude: finds the sermon in the full service, writes a summary, key points and scripture, and adds a "Start at the sermon" button.
* Admin dashboard with connection status, copy-ready shortcodes and a diagnostics table.

Full setup guide: https://github.com/avdude/Sunday-Sermon-Facebook-Video

== Installation ==

1. Upload the plugin zip from Plugins > Add New > Upload Plugin, then activate it.
2. Set Settings > General > Timezone to your church's city.
3. Create a Meta developer app and get a Page access token that never expires (see the GitHub README for step-by-step instructions).
4. Paste the token in Sunday Sermon > Settings and save.
5. Add `[sunday_sermon_archive show_title="yes" show_date="yes"]` to a page in a Shortcode block.

== Frequently Asked Questions ==

= Why does Facebook's player say "This video can't be embedded"? =

Facebook blocks its embed player when it detects copyrighted music. Set Player to "Play on this site" and the plugin plays the video file directly.

= A service video is missing. =

Open Sunday Sermon > Dashboard and look at "What Facebook returned". Videos posted on a personal profile instead of the Page can't be read by the plugin.

= My token stopped working. =

Tokens stop when the Facebook account behind them changes its password or loses full control of the Page. Create a new token and confirm it says "Expires: Never".

== External services ==

This plugin connects to the Facebook Graph API (graph.facebook.com), provided by Meta Platforms, Inc., to list the videos, Reels, live videos and posts of the Facebook Page you connect. It sends your Page ID and the access token you enter, from your server, on a schedule (every 5 to 1440 minutes, as you set) and when an administrator clicks "Check Facebook now". Video files, thumbnails and, if selected, Facebook's embedded player load from Facebook's servers in visitors' browsers.

Meta Terms of Service: https://www.facebook.com/terms.php
Meta Privacy Policy: https://www.facebook.com/privacy/policy/

If transcripts are turned on, the plugin also uses AssemblyAI (api.assemblyai.com), provided by AssemblyAI, Inc. It sends the address of each sermon's video file and your API key when a sermon is sent for transcription (automatically for new services, or when an administrator clicks Transcribe), then checks every 5 minutes until the transcript is ready. AssemblyAI downloads and processes the audio.

AssemblyAI Terms of Service: https://www.assemblyai.com/legal/terms-of-service
AssemblyAI Privacy Policy: https://www.assemblyai.com/legal/privacy-policy

If sermon summaries are turned on, the plugin sends the text of each finished transcript and your API key to the provider you choose: AssemblyAI's LLM Gateway (llm-gateway.assemblyai.com, provided by AssemblyAI, Inc.) or the Claude API (api.anthropic.com, provided by Anthropic, PBC). It sends it once per transcript (automatically, or when an administrator clicks Summarize), and stores the summary it returns.

Anthropic Commercial Terms: https://www.anthropic.com/legal/commercial-terms
Anthropic Privacy Policy: https://www.anthropic.com/legal/privacy

== Screenshots ==

1. The sermon archive: latest service with a playlist of past services.
2. The admin dashboard.
3. Settings.

== Changelog ==

= 2.4.0 =
* Sermon summaries can now use AssemblyAI's LLM Gateway (the default, using your existing AssemblyAI key) or Claude directly from Anthropic.

= 2.3.0 =
* Sermon summaries through Claude: finds where the sermon starts and ends, writes a summary, key points and scripture list, and adds a "Start at the sermon" button.
* Option to show only the sermon portion of the transcript.
* Summary box on the transcript edit screen for corrections; Summary column and Summarize button on the Dashboard.

= 2.2.0 =
* Sermon transcripts through AssemblyAI: automatic for new services, a Dashboard card with status and a Transcribe button, editable transcripts, and a collapsible transcript under the player with click-to-jump timestamps.
* New shortcode option: transcript="no".

= 2.1.0 =
* Renamed to Sunday Sermon Facebook Video. New plugin folder and text domain: sunday-sermon-facebook-video.
* First public release on GitHub.

= 2.0.0 =
* Own admin menu with a Dashboard and a card-based Settings page.

= 1.14.0 =
* Archive uses a playlist sidebar.

= 1.13.0 =
* Uses dates written in titles or descriptions.

= 1.12.0 =
* Look back a set number of months.

= 1.10.0 =
* Multiple match phrases, Sundays-only and untitled Sunday video options.

= 1.7.0 =
* Plays video files directly on the site.

= 1.6.0 =
* Archive shortcode.

= 1.4.0 =
* Live videos and Reels.

= 1.0.0 =
* First version.

== Upgrade Notice ==

= 2.4.0 =
Summaries now default to AssemblyAI's LLM Gateway. If you set up summaries with an Anthropic key, choose Claude as the provider in Settings.

= 2.3.0 =
Adds optional sermon summaries. Nothing changes until you turn them on in Settings.

= 2.2.0 =
Adds optional sermon transcripts. Nothing changes until you turn them on in Settings.

= 2.1.0 =
The plugin was renamed and installs in a new folder. Deactivate the old "Sunday Sermon Video" plugin before activating this one. Your settings carry over.
