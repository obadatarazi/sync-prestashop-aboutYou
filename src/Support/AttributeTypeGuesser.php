<?php

declare(strict_types=1);

namespace SyncBridge\Support;

final class AttributeTypeGuesser
{
    public static function detect(string $label): string
    {
        $normalized = self::normalize($label);
        if ($normalized === '') {
            return 'attribute';
        }
        if (self::matches($normalized, [
            'color', 'colour', 'couleur', 'farbe', 'farb', 'kleur', 'kleur', 'colore', 'cor', 'kolor', 'coulor'
        ])) {
            return 'color';
        }
        if (self::matches($normalized, [
            'size', 'taille', 'talla', 'maat', 'grosse', 'groesse', 'grsse', 'dimensione', 'tamano', 'rozmiar'
        ])) {
            return 'size';
        }
        if (self::matches($normalized, [
            'length', 'inseam', 'inside leg', 'insideleg', 'cup', 'width', 'w x l', 'w/l'
        ])) {
            return 'second_size';
        }
        return 'attribute';
    }

    public static function isColor(string $label): bool
    {
        return self::detect($label) === 'color';
    }

    public static function isSize(string $label): bool
    {
        return self::detect($label) === 'size';
    }

    public static function isSecondSize(string $label): bool
    {
        return self::detect($label) === 'second_size';
    }

    private static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $trans = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($trans !== false) {
            $value = strtolower($trans);
        }
        return preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    }

    private static function matches(string $normalized, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }
        return false;
    }
}
