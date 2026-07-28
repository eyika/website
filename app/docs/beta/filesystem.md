# Filesystem In Atom

## Introduction

Atom provides a unified filesystem abstraction built on top of [league/flysystem](https://flysystem.thephpleague.com/). Whether your files live on the local disk, an S3 bucket, FTP/SFTP, Azure Blob Storage, or Google Cloud Storage, you interact with them through the same `Storage` facade — write once, switch backends with a config change.

The system is organised around **disks**. A disk is a named backend (a driver plus its settings) defined in `config/filesystems.php`. You read and write against a disk; the driver behind it handles the actual storage.

---

## Configuration

Filesystem configuration lives in `config/filesystems.php`. It defines the default disk and a `disks` map, plus the symbolic `links` used by `storage:link`.

### Example Configuration File
```php
return [

    // The default disk, overridable with the FILESYSTEM_DISK env variable.
    'default' => env('FILESYSTEM_DISK', 'public'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app/local'),
            'throw'  => false,
        ],

        'public' => [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => '/storage',
            'visibility' => 'public',
            'throw'      => false,
        ],

        's3' => [
            'driver'                  => 's3',
            'key'                     => env('AWS_ACCESS_KEY_ID'),
            'secret'                  => env('AWS_SECRET_ACCESS_KEY'),
            'region'                  => env('AWS_DEFAULT_REGION'),
            'bucket'                  => env('AWS_BUCKET'),
            'endpoint'                => env('AWS_ENDPOINT'),
            'visibility'              => 'public',
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw'                   => false,
        ],
    ],

    // Symlinks created by the storage:link command (link => target).
    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
```

- **`default`**: the disk used when you don't name one explicitly.
- **`disks`**: each entry names a `driver` and its settings.
- **`links`**: link-path => target-path pairs materialised by `storage:link`.

---

## Disks & Drivers

Each disk's `driver` selects a Flysystem adapter. Atom wires up the following drivers:

| Driver          | Backend | Notes |
|-----------------|---------|-------|
| `local`         | Local filesystem | Reads/writes under the disk's `root`. Creates the root directory if it is missing. |
| `ftp` / `sftp`  | FTP / SFTP server | Connection options are taken from the disk config. |
| `s3`            | Amazon S3 (or S3-compatible) | Uses the AWS SDK; requires `key`, `secret`, `region`, `bucket`. |
| `azure`         | Azure Blob Storage | Requires the Azure Flysystem adapter and a `dsn` / `container-name`. |
| `google`        | Google Cloud Storage | Requires `project_id`, `key_file_path`, and `bucket`. |

> The starter config ships `local`, `public`, and `s3` examples, and its comment lists `local`, `ftp`, `sftp`, and `s3` as the common set. The `azure` and `google` adapters are implemented as well but require their respective Flysystem packages to be installed.

### The `local` and `public` disks

Two local disks are configured by default:

- **`local`** — private application storage under `storage/app/local`, not web-accessible.
- **`public`** — files that should be served over HTTP, stored under `storage/app/public` with `visibility` set to `public` and a `url` of `/storage`. To make these files reachable from the web root, run `storage:link` (see below).

---

## The Storage Facade

Interact with disks through the `Storage` facade:

```php
use Eyika\Atom\Framework\Support\Facade\Storage;
```

Unless you select a disk with `Storage::disk(...)`, calls target `config('filesystems.default')`.

### Reading & Writing Files

```php
// Write a file (returns the number of bytes written).
Storage::put('reports/summary.txt', $contents);

// Read a file's contents.
$contents = Storage::get('reports/summary.txt');

// Check existence.
if (Storage::exists('reports/summary.txt')) {
    // ...
}

// Delete a file.
Storage::delete('reports/summary.txt');
```

> `get()` results are cached (keyed by disk + path) so repeated reads of the same path within a request avoid re-hitting the backend; `put()` and `delete()` keep that cache in sync.

### Prepending & Appending

```php
Storage::prepend('logs/app.log', "First line\n");
Storage::append('logs/app.log', "Another line\n");
```

### Copying & Moving

```php
Storage::copy('old/report.pdf', 'archive/report.pdf');
Storage::move('inbox/report.pdf', 'processed/report.pdf');
```

### File Metadata

```php
$bytes    = Storage::size('reports/summary.txt');       // int
$modified = Storage::lastModified('reports/summary.txt'); // unix timestamp
```

### Visibility

```php
$visibility = Storage::getVisibility('avatars/1.png'); // 'public' | 'private'
Storage::setVisibility('avatars/1.png', 'private');
```

### Storing Uploaded Files

`putFile()` and `putFileAs()` persist a `File` instance's contents to a disk:

```php
use Eyika\Atom\Framework\Support\Storage\File;

Storage::putFile('uploads/', $file);
Storage::putFileAs('uploads/', $file, 'invoice.pdf');
```

### Directories

```php
$files       = Storage::files('reports');            // files in a directory
$allFiles    = Storage::allFiles('reports');         // recursively
$dirs        = Storage::directories('reports');      // sub-directories
$allDirs     = Storage::allDirectories('reports');   // recursively

Storage::makeDirectory('reports/2026');
Storage::deleteDirectory('reports/2025');
Storage::cleanDirectory('tmp');                      // remove contents, keep the dir
```

`Storage::files($directory, $recursive)` also accepts a second boolean to recurse in one call.

### File URLs

For disks that expose files publicly (the `public` local disk, S3, etc.), generate a URL:

```php
$url = Storage::url('avatars/1.png');
```

Some adapters support signed, expiring URLs:

```php
$url = Storage::temporaryUrl('private/report.pdf', new \DateTime('+10 minutes'));
```

> `url()` and `temporaryUrl()` are backed by the underlying Flysystem adapter's public-URL / temporary-URL generators. They are available for adapters that provide them (for example, the local public disk and S3); adapters without native support will not produce a URL.

### Choosing a Disk

Target a non-default disk for any operation:

```php
Storage::disk('s3')->put('backups/db.sql', $dump);
$exists = Storage::disk('s3')->exists('backups/db.sql');
```

---

### Storage Facade Method Reference

| Method | Description |
|--------|-------------|
| `disk(string $disk)` | Switch to a named disk. |
| `drive(string $driver)` | Switch the active adapter by driver name. |
| `get(string $path)` | Read a file's contents. |
| `put(string $path, string $contents, $options = [])` | Write contents; returns bytes written. |
| `putFile(string $path, File $file, $options = [])` | Store a `File`'s contents. |
| `putFileAs(string $path, File $file, string $name, $options = [])` | Store a `File` under a given name. |
| `prepend(string $path, string $data)` / `append(...)` | Prepend/append to a file. |
| `exists($path)` | Whether the path exists. |
| `delete($path)` | Delete a file. |
| `copy($from, $to)` / `move($from, $to)` | Copy/move a file. |
| `size($path)` / `lastModified($path)` | File metadata. |
| `getVisibility($path)` / `setVisibility($path, $visibility)` | Read/set visibility. |
| `url($path)` / `temporaryUrl($path, $expiration, $options = [])` | Public / signed URL. |
| `files(...)` / `allFiles(...)` / `directories(...)` / `allDirectories(...)` | List contents. |
| `makeDirectory(...)` / `deleteDirectory(...)` / `cleanDirectory(...)` | Directory management. |
| `cache(CacheInterface $cache)` | Swap the read cache implementation. |
| `extend(string $name, CustomStorageAdapterCallback $callback)` | Register a custom Flysystem adapter. |

---

## The Public Disk & `storage:link`

Files on the `public` disk live under `storage/app/public`, which is outside the web-accessible directory. To serve them, create a symbolic link from `public/storage` to that folder using the `storage:link` command:

```bash
php artisan storage:link
```

This reads the `links` map in `config/filesystems.php` and, for each `link => target` pair, creates the symlink (creating the target directory first if it does not exist). With the default config it links `public/storage` → `storage/app/public`, so a file stored at `avatars/1.png` on the `public` disk becomes reachable at `/storage/avatars/1.png`.

To remove the symlinks again:

```bash
php artisan storage:unlink
```

`storage:unlink` walks the same `links` map and removes each linked path.

> Both commands operate purely from the `links` configuration — add or change entries there to link additional directories.

---

## Custom Adapters

Beyond the built-in drivers, you can register any Flysystem adapter at runtime with `extend()`:

```php
use Eyika\Atom\Framework\Support\Facade\Storage;

Storage::extend('my-driver', function ($app, $disk) {
    // Return a League\Flysystem\FilesystemAdapter instance.
    return new SomeCustomAdapter(/* ... */);
});
```

The callback must return a `League\Flysystem\FilesystemAdapter`; registering the same driver name twice throws an `InvalidStorageAdapterException`.

---

## Best Practices

1. **Prefer relative paths.** Pass paths relative to a disk's `root` — let the disk decide where they physically live, so backends stay swappable.
2. **Use the `public` disk for web assets.** Store user uploads meant to be served on the `public` disk and run `storage:link` once during deployment.
3. **Keep secrets private.** Anything not meant to be public belongs on the `local` disk (or an appropriately-scoped cloud bucket), never `public`.
4. **Switch backends via config.** Move from local to S3 by changing `FILESYSTEM_DISK` / disk config, not your application code.
5. **Use temporary URLs for private files.** When you need to hand out a link to a private object, prefer `temporaryUrl()` over making the file public.

---

## Conclusion

Atom's filesystem layer gives you one consistent API — `put`, `get`, `delete`, `url`, and friends — across local and cloud storage, powered by Flysystem. Configure disks in `config/filesystems.php`, reach for the `Storage` facade in your code, and link the public disk with `storage:link` to serve files over HTTP.
