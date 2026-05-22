<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Dto;

enum Color: string
{
    case Red = 'red';
    case Green = 'green';
    case Blue = 'blue';
}
