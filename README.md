# r2-uploads

WordPress plugin for storing uploads in Cloudflare R2.

This fork is R2-first. It uses Cloudflare R2's S3-compatible API for object operations, but public delivery is designed around an R2 custom domain.

## Requirements

- PHP >= 8.3
- WordPress >= 5.3
- Plugin dependencies installed or bundled with the plugin
- Cloudflare R2 bucket
- Cloudflare R2 API token with Object Read & Write access scoped to the bucket
- A custom domain connected to the R2 bucket for production public media URLs

## Installation

1. Download the latest release ZIP from GitHub:

   ```text
   https://github.com/carnaval-studio/r2-uploads/releases/latest/download/r2-uploads.zip
   ```

2. Install it through **Plugins > Add New > Upload Plugin**.

Release builds bundle the `vendor/` directory, so the plugin works without a site-level Composer autoloader.

### Updates

Once installed, R2 Uploads checks GitHub releases automatically. New versions appear in **Dashboard > Updates**, just like plugins from the WordPress.org directory. No license key is required because the repository is public.

If you hit GitHub API rate limits on shared hosting, define a token in `wp-config.php`:

```php
define( 'R2_UPLOADS_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxx' );
```

Because the repository is public, the token only needs **read access to public repositories**:

- **Classic token**: create a token with **no scopes selected**. Public repository data is readable without any scopes.
- **Fine-grained token**: grant **Contents: Read-only** on `carnaval-studio/r2-uploads`.

The token is used only for the GitHub Releases API; it is never sent to the download URL of the release ZIP.

### From source

Install dependencies inside the plugin directory:

```bash
composer install --no-dev --optimize-autoloader
```

## Configuration

Add the required constants to `wp-config.php`:

```php
define( 'R2_UPLOADS_BUCKET', 'my-bucket' );
define( 'R2_UPLOADS_ACCOUNT_ID', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
define( 'R2_UPLOADS_KEY', 'access-key-id' );
define( 'R2_UPLOADS_SECRET', 'secret-access-key' );
define( 'R2_UPLOADS_PUBLIC_URL', 'https://media.example.com' );
```

`R2_UPLOADS_PUBLIC_URL` should be a custom domain connected to the R2 bucket in Cloudflare. Do not use `r2.dev` for production traffic.

Optional jurisdiction endpoint:

```php
define( 'R2_UPLOADS_JURISDICTION', 'eu' );
```

Optional region override. R2 uses `auto`:

```php
define( 'R2_UPLOADS_REGION', 'auto' );
```

## Bucket Path Options

These options control object keys in the bucket and therefore public URLs.

```php
define( 'R2_UPLOADS_BUCKET_PATH_PREFIX', 'uploads' );
define( 'R2_UPLOADS_ADD_YEAR_MONTH_TO_BUCKET_PATH', true );
define( 'R2_UPLOADS_ADD_OBJECT_VERSION_TO_BUCKET_PATH', true );
```

Example object key:

```text
uploads/2026/06/1718467200/image.jpg
```

Example public URL:

```text
https://media.example.com/uploads/2026/06/1718467200/image.jpg
```

If `R2_UPLOADS_ADD_YEAR_MONTH_TO_BUCKET_PATH` is not defined, WordPress' native upload subdirectory behavior is used. If WordPress month/year folders are disabled, no year/month path is added.

`R2_UPLOADS_ADD_OBJECT_VERSION_TO_BUCKET_PATH` stores new uploads under a timestamp folder. This helps avoid stale CDN/cache responses when media is replaced.

## Cache Headers

```php
define( 'R2_UPLOADS_HTTP_CACHE_CONTROL', 'public, max-age=31536000, immutable' );
define( 'R2_UPLOADS_HTTP_EXPIRES', gmdate( 'D, d M Y H:i:s', time() + YEAR_IN_SECONDS ) . ' GMT' );
```

## Admin Media Library CORS Workaround

WordPress adds `crossorigin="anonymous"` to images in the admin media library when they are served from a different origin than the admin. If the public domain does not send `Access-Control-Allow-Origin` headers, those images fail to load in the media grid.

R2 Uploads automatically strips the attribute in the admin when `R2_UPLOADS_PUBLIC_URL` points to a different host than the admin. This lets the images load without requiring CORS headers on the public domain.

To disable this behavior (for example, when CORS is already configured on the public domain):

```php
define( 'R2_UPLOADS_DISABLE_ADMIN_CROSSORIGIN', true );
```

## WP-CLI

```bash
wp plugin activate r2-uploads
wp r2-uploads verify
wp r2-uploads ls [<path>]
wp r2-uploads upload-directory <from> [<to>] [--concurrency=<concurrency>] [--verbose]
wp r2-uploads cp <from> <to>
wp r2-uploads rm <path> [--regex=<regex>]
wp r2-uploads enable
wp r2-uploads disable
```

## Private Media

Cloudflare R2 does not support S3 object ACLs. Public media should be served through an R2 public bucket custom domain.

For private media, use presigned URLs. Presigned URLs are generated against the R2 S3 API endpoint and cannot use custom domains.

## Notes

- R2 bucket object operations use the S3-compatible API endpoint: `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`.
- Custom public delivery should use an R2 custom domain such as `https://media.example.com`.
- R2 uses strong consistency for object reads, writes, deletes and listings.
- R2 does not support S3 ACL APIs such as `PutObjectAcl` or `x-amz-acl` on `PutObject`.
