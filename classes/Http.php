<?php

declare(strict_types=1);

namespace App;

class Http
{
    public static function forbidden(): void
    {
        http_response_code(403);
        echo 'You\'re not allowed to access this page.';
        exit;
    }
}
