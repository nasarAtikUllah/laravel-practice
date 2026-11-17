<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;

abstract class Controller
{
    use ApiResponse;

    /** A LIKE pattern where %, _ and \ typed by the user are matched literally. */
    protected function likePattern(string $term): string
    {
        return '%'.addcslashes(trim($term), '%_\\').'%';
    }
}
