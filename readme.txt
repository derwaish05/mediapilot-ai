=== MediaPilot AI ===
Contributors: brainstudioz
Tags: media management, digital asset manager, media library, AI media, image optimization
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Organize, optimize, search, and manage your WordPress media library with AI tagging, OCR, duplicate detection, folders, CDN tools, and WooCommerce.

== Description ==

**MediaPilot AI** turns the WordPress Media Library into a powerful Digital Asset Management (DAM) platform. Organize files with folders, automatically generate AI tags and metadata, extract text using OCR, detect duplicates, replace media safely, optimize performance, and integrate with WooCommerce.

It is built for agencies, eCommerce stores, marketers, and content teams that need to organize, search, optimize, and automate their media workflows.

= Key Features =

* **Folder System** — hierarchical folders with drag-and-drop, per-user or global modes, and bulk assignment
* **Version Control** — keep a full history of replaced files and roll back with one click
* **Usage Tracker** — see exactly which posts, pages, products, and page-builder widgets use each media file, and flag unused files for cleanup
* **Analytics** — storage totals, storage by file type and by folder, upload activity over time, and insert/download counts per attachment, with CSV export
* **AI Tagging** — auto-tag images on upload via Google Vision or AWS Rekognition (opt-in)
* **OCR** — extract text from images via Google Vision or AWS Textract (opt-in)
* **Duplicate Detection** — find exact (MD5) and visually-similar (perceptual hash) duplicates, with a cancellable background scan
* **Smart Search** — full-text search across the media library
* **Document Library** — public-facing searchable file library via shortcode
* **Client Portal** — password-protected share links for external clients
* **CDN URL Rewriting** — optionally serve media from your own CDN (opt-in)
* **Gallery Blocks** — a native block-editor block plus widgets and modules for the major page builders
* **CSV Import/Export** — migrate folder structures and file assignments
* **WP-CLI Support** — manage folders, export data, and run optimization from the command line
* **Developer Hooks** — extensive `do_action` and `apply_filters` hooks for customization

= Page Builder Support =

* Native WordPress block editor
* Compatible with the major third-party page builders

= Integrations =

* eCommerce product gallery sync
* Custom-field folder picker
* One-click import from other media-folder organizer plugins
* Multilingual-ready — compatible with major translation plugins

== External services ==

By default MediaPilot AI does **not** contact any external service and no data leaves your site. The integrations below are optional and only run after you enable them and supply your own credentials in **Media › MediaPilot AI Settings**.

= Google Cloud Vision API =
Used for AI image tagging and/or OCR text extraction. When you set the AI provider to **Google Vision**, the image bytes of the processed attachment and your API key are sent to `https://vision.googleapis.com/v1/images:annotate` at upload time (for tagging) and during OCR processing, to generate tags and extract text. Provided by Google LLC.
Terms of Service: https://cloud.google.com/terms — Privacy Policy: https://policies.google.com/privacy

= AWS Rekognition =
Used for AI image tagging. When you set the AI provider to **AWS Rekognition**, the image bytes of the uploaded attachment and a request signed with your AWS credentials are sent to the Amazon Rekognition API (`rekognition.<region>.amazonaws.com`) in your configured region at upload time, to generate tags. Provided by Amazon Web Services, Inc.
Terms of Service: https://aws.amazon.com/service-terms/ — Privacy Policy: https://aws.amazon.com/privacy/

= AWS Textract =
Used for OCR text extraction. When you set the OCR provider to **AWS**, the image bytes of the processed attachment and a request signed with your AWS credentials are sent to the Amazon Textract API (`textract.<region>.amazonaws.com`) in your configured region, to detect and return text. Provided by Amazon Web Services, Inc.
Terms of Service: https://aws.amazon.com/service-terms/ — Privacy Policy: https://aws.amazon.com/privacy/

= CDN URL rewriting (your own CDN) =
This feature is off by default. When you enter a CDN base URL in the optimization settings, MediaPilot AI rewrites the URLs of your media so visitors' browsers load those assets from the CDN you configured. The plugin itself sends no data to the CDN; it only changes the asset URLs. Use the terms and privacy policy of whichever CDN provider you choose.

= Client Portal download logging =
When the Client Portal feature is used, the plugin logs downloads (file, timestamp, and visitor IP address) in your own site's database for audit purposes. This data is never sent to any external service.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins > Installed Plugins**.
3. Navigate to **Media > MediaPilot AI Settings** to configure the plugin.

== Frequently Asked Questions ==

= Does this plugin replace the default media library? =

No. It enhances the native WordPress media library with folders, version control, analytics, and more. All standard WordPress media functionality continues to work.

= Does the plugin send my media anywhere? =

Only if you opt in. AI tagging and OCR are disabled by default. They run only after you choose a provider (Google Vision, AWS Rekognition, or AWS Textract) and enter your own credentials. See **External Services** above for exactly what is sent and where.

= Is there a limit on the number of folders? =

No. You can create as many folders as your site requires.

= Can I import my existing folders from another media-folder plugin? =

Yes. Go to **Media > MediaPilot AI Settings > Migration** and choose your source plugin to import all folders and file assignments.

= Why does the "Unused Media" view show everything right after install? =

Usage data is only recorded as content is saved while the plugin is active, so a fresh install starts with an empty usage index. Go to **Media > Analytics** and click **Rebuild Usage Index** once — it scans all posts, pages, and products in batches and populates the index. After it finishes, "Unused Media" shows only files that are genuinely not referenced anywhere.

= Why is Total Storage 0 right after install? =

Storage figures are read from a per-file size index that is written on upload. For media added before the plugin was active, open **Media > Analytics** — the page backfills the missing sizes automatically in batches and refreshes when done.

= How do duplicate scans work, and can I stop one? =

Go to **Media > Duplicates** and click **Scan for Duplicates**. The scan runs in the background in small batches and shows live progress; click **Cancel scan** at any time to stop it. Exact duplicates are matched by file hash; visually-similar images are matched by perceptual hash.

= Does this work with multisite? =

The plugin supports standard WordPress installations. Multisite compatibility has not been fully tested.

== Screenshots ==

1. Folder sidebar in the media library
2. File detail panel with version history
3. Usage tracker showing where a file is used
4. Analytics dashboard
5. Settings page

== Changelog ==

= 1.0.0 =
* Initial release.
* Hierarchical folder system for the media library with drag-and-drop and bulk assignment.
* Usage tracker across posts, pages, products, page builders, and widgets, with a one-click "Rebuild Usage Index" and an "Unused Media" view.
* Analytics: storage totals, storage by file type and folder, upload activity, insert/download counts, and CSV export. Storage sizes are backfilled automatically for existing media.
* Duplicate detection (exact + visually similar) with a cancellable, batched background scan.
* AI tagging (Google Vision / AWS Rekognition) and OCR (Google Vision / AWS Textract) — all opt-in and disclosed under External Services.
* Document Library shortcode and password-protected Client Portal share links.
* Optional CDN URL rewriting (opt-in) and gallery blocks/widgets for the native block editor and the major page builders.
* Chart.js is bundled locally; no third-party CDNs are used for plugin assets.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
