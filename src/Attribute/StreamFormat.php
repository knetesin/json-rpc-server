<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Attribute;

enum StreamFormat: string
{
    case Ndjson = 'ndjson';
    case Sse = 'sse';
    case JsonArray = 'json_array';
}
