# Local Avatars

Cache Gravatar images locally in your uploads folder and serve them from your own domain — faster avatars, no third-party requests on every page load, and friendlier for privacy regulations (no visitor data sent to Gravatar on every view).

## Features

- **Local serving** — avatars are downloaded once from Gravatar and served from `wp-content/uploads/local-avatars/`.
- **Automatic refresh** — cached copies are re-downloaded after the refresh window (default 7 days); a weekly WP-Cron job drops stale files so no visitor ever waits on a Gravatar download.
- **Stale-copy fallback** — if Gravatar is unreachable, the last cached copy keeps serving instead of breaking the layout.
- **Image-only cache** — only responses with an `image/*` content type are stored, so an error page can never be cached as an avatar.
- **Atomic writes** — downloads go to a temp file first, then `rename()`, so concurrent requests never see a half-written image.
- **Works everywhere** — hooks `pre_get_avatar_data`, so it covers `get_avatar()`, comments, author boxes and anything else using the avatar API.
- **Settings** — enable/disable, maximum size (32–512px), refresh window (1–90 days), Gravatar default style, one-click cache purge under **Settings → Local Avatars**.

## Installation

1. Upload the `wp-local-avatars` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins** in WordPress admin.
3. Avatars are cached automatically from the next page load.

## Uninstall

Deleting the plugin removes the `wla_settings` option, clears the weekly cron event and deletes the cached avatar files (they are regenerable copies of public Gravatars).

## License

MIT — see [LICENSE](LICENSE).
