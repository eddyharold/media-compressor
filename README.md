# harorudo/media-compressor

A Laravel utility for compressing images, PDFs, and files — powered by Intervention Image and Ghostscript.

---

## Requirements

- PHP ^8.2
- Laravel ^10.0 | ^11.0
- [Intervention Image](https://image.intervention.io) ^2.7
- [Ghostscript](https://www.ghostscript.com/releases/) (for PDF compression)

---

## Installation

```bash
composer require harorudo/media-compressor
```

Laravel auto-discovers the service provider. No manual registration needed.

### Verify your environment

Run the doctor command to confirm Ghostscript and the image backend are installed:

```bash
php artisan compression:doctor
```

### Install Ghostscript

**Ubuntu / Debian:**

```bash
sudo apt-get install ghostscript
```

**macOS:**

```bash
brew install ghostscript
```

**Windows:**
Download from [ghostscript.com/releases](https://www.ghostscript.com/releases/) and ensure `gswin64c.exe` is in your PATH.

---

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=compression-config
```

`config/compression.php`:

```php
return [
    'allowed_disks'  => ['public', 's3'],
    'max_file_size'  => 20 * 1024 * 1024, // 20 MB
];
```

---

## Usage

Inject `CompressionUtil` directly or use the `Compression` facade.

### Images

```php
use Harorudo\MediaCompressor\Facades\Compression;

// Compress an uploaded image
$result = Compression::compressImage(
    file: $request->file('photo'),
    quality: 75,
    maxWidth: 1280,
    format: 'webp'         // 'original' | 'webp' | 'jpeg' | 'png'
);

// Compress multiple images
$results = Compression::compressImages($request->file('photos'));

// Re-compress an already stored image
$result = Compression::recompressStoredImage('images/avatar.jpg', quality: 60);
```

### PDFs

```php
// Compress an uploaded PDF
$result = Compression::compressPdf(
    file: $request->file('document'),
    preset: 'ebook'        // 'screen' | 'ebook' | 'printer' | 'prepress'
);

// Re-compress a stored PDF
$result = Compression::recompressStoredPdf('pdfs/report.pdf', preset: 'ebook');

// Bulk re-compress stored PDFs
$results = Compression::recompressStoredPdfs(['pdfs/a.pdf', 'pdfs/b.pdf']);
```

#### PDF presets

| Preset     | Image DPI    | Best for                          |
| ---------- | ------------ | --------------------------------- |
| `screen`   | 72           | Email attachments, smallest size  |
| `ebook`    | 150          | General use — recommended default |
| `printer`  | 300          | High-quality downloads            |
| `prepress` | 300 + colour | Print-ready, near-lossless        |

### ZIP

```php
// Zip a single uploaded file
$result = Compression::zipUploadedFile($request->file('document'));

// Zip multiple stored files into one archive
$result = Compression::zipFiles(
    storagePaths: ['docs/report.pdf', 'docs/invoice.pdf'],
    archiveName: 'reports'
);
```

### Return value

Every method returns an array with savings stats:

```php
[
    'path'            => 'pdfs/report.pdf',
    'original_size'   => 2048000,
    'compressed_size' => 819200,
    'saved_bytes'     => 1228800,
    'saved_percent'   => 60.0,
]
```

---

## Security

This package enforces the following guards on every operation:

- **MIME type validation** — server-side detection, client header is never trusted
- **File size limit** — configurable via `compression.max_file_size`
- **Storage disk allowlist** — configurable via `compression.allowed_disks`
- **Directory sanitization** — strips invalid characters to prevent path traversal
- **Safe filenames** — client filenames never touch the filesystem; hashed names are always used
- **Temp file cleanup** — guaranteed via `finally` blocks, even on failure

---

## License

MIT © [Eddy Harold](mailto:harorudo.dev@gmail.com)
