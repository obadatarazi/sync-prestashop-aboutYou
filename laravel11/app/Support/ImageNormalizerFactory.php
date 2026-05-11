<?php

declare(strict_types=1);

namespace App\Support;

final class ImageNormalizerFactory
{
    /**
     * Whether image normalization can run (env + public base URL resolvable).
     */
    public static function available(): bool
    {
        if (!filter_var($_ENV['IMAGE_NORMALIZE_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $baseUrl = rtrim((string) (
            $_ENV['IMAGE_NORMALIZE_PUBLIC_BASE_URL']
            ?? $_ENV['IMAGE_PUBLIC_BASE_URL']
            ?? ''
        ), '/');
        if ($baseUrl !== '') {
            return true;
        }

        return rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') !== '';
    }

    public static function create(HttpClient $http): ?ImageNormalizer
    {
        if (!self::available()) {
            return null;
        }

        $baseUrl = rtrim((string) (
            $_ENV['IMAGE_NORMALIZE_PUBLIC_BASE_URL']
            ?? $_ENV['IMAGE_PUBLIC_BASE_URL']
            ?? ''
        ), '/');
        if ($baseUrl === '') {
            $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
            if ($appUrl !== '') {
                $baseUrl = $appUrl . '/ay-normalized';
            }
        }

        if ($baseUrl === '') {
            return null;
        }

        return new ImageNormalizer(
            $http,
            base_path('public/ay-normalized'),
            $baseUrl,
            1125,
            1500,
            (int) ($_ENV['IMAGE_JPEG_QUALITY'] ?? 92)
        );
    }
}
