<?php

declare(strict_types=1);

namespace SyncBridge\Support;

final class ImageNormalizer
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $targetDir,
        private readonly string $publicBaseUrl,
        private readonly int $targetWidth = 1125,
        private readonly int $targetHeight = 1500,
        private readonly int $jpegQuality = 92,
    ) {
    }

    public function normalizeSingleUrl(string $url): ?array
    {
        $response = $this->http->request('image', 'GET', $url, [], null, [
            'expect_json' => false,
            'timeout' => 60,
            'min_interval_ms' => 50,
        ]);

        $body = $response['body'] ?? '';
        if ($body === '') {
            return null;
        }

        $src = @imagecreatefromstring($body);
        if (!$src) {
            return null;
        }

        $srcWidth = imagesx($src);
        $srcHeight = imagesy($src);
        if ($srcWidth <= 0 || $srcHeight <= 0) {
            imagedestroy($src);
            return null;
        }

        if (!is_dir($this->targetDir) && !@mkdir($concurrentDirectory = $this->targetDir, 0775, true) && !is_dir($concurrentDirectory)) {
            imagedestroy($src);
            throw new \RuntimeException('Could not create image target directory: ' . $this->targetDir);
        }

        $canvas = imagecreatetruecolor($this->targetWidth, $this->targetHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        $scale = min($this->targetWidth / $srcWidth, $this->targetHeight / $srcHeight);
        $scaledWidth = max(1, (int) round($srcWidth * $scale));
        $scaledHeight = max(1, (int) round($srcHeight * $scale));
        $dstX = (int) floor(($this->targetWidth - $scaledWidth) / 2);
        $dstY = (int) floor(($this->targetHeight - $scaledHeight) / 2);

        imagecopyresampled(
            $canvas,
            $src,
            $dstX,
            $dstY,
            0,
            0,
            $scaledWidth,
            $scaledHeight,
            $srcWidth,
            $srcHeight
        );

        $hash = sha1($url . '|' . $body);
        $filename = $hash . '.jpg';
        $absolutePath = rtrim($this->targetDir, '/') . '/' . $filename;
        imagejpeg($canvas, $absolutePath, max(60, min(100, $this->jpegQuality)));

        imagedestroy($canvas);
        imagedestroy($src);

        if (!file_exists($absolutePath)) {
            return null;
        }

        return [
            'ay-normalized/' . $filename,
            rtrim($this->publicBaseUrl, '/') . '/' . $filename,
            $this->targetWidth,
            $this->targetHeight,
            filesize($absolutePath) ?: 0,
        ];
    }
}
