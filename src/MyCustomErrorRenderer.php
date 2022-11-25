<?php

namespace Hexlet\Code;

use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

class MyCustomErrorRenderer implements ErrorRendererInterface
{
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        return 'Страница не найдена';
    }
}
