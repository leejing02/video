<?php

namespace App\Models;

/**
 * Thin alias so that Filament resources using App\Models\Category
 * still work — the real model lives in App\Domains\Video\Models\Category.
 */
class Category extends \App\Domains\Video\Models\Category
{
    // Keep legacy KIND_ constants pointing to the correct TYPE_ values.
    public const KIND_LONG  = self::TYPE_VIDEO;
    public const KIND_SHORT = self::TYPE_SHORT;
}
