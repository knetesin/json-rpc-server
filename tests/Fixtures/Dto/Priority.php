<?php

declare(strict_types=1);

namespace JsonRpcServer\Tests\Fixtures\Dto;

enum Priority: int
{
    case Low = 1;
    case Normal = 5;
    case High = 10;
}
