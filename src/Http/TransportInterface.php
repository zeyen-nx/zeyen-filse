<?php


declare(strict_types=1);

namespace Zeyen\Filse\Http;

interface TransportInterface
{
    public function send(HttpRequest $request): HttpResponse;
}
