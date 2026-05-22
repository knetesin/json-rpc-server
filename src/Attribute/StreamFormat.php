<?php

declare(strict_types=1);

namespace JsonRpcServer\Attribute;

enum StreamFormat: string
{
    case Ndjson = 'ndjson';
    case Sse = 'sse';
    case JsonArray = 'json_array';
}
