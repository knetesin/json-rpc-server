<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Batch;

/**
 * Why a given batch ran in the mode it did. Emitted on the dispatch event
 * so OTel / Profiler / logs see the same labels.
 */
enum FanoutDecision: string
{
    /** Ran in parallel via loopback HTTP fan-out. */
    case Parallel = 'parallel';

    /** Ran sequentially in-process — feature is disabled in config. */
    case SequentialDisabled = 'sequential_disabled';

    /** Ran sequentially — batch too small (below min_batch_size). */
    case SequentialTooSmall = 'sequential_too_small';

    /** Ran sequentially — this is itself a fan-out sub-call (recursion guard). */
    case SequentialDepthLimit = 'sequential_depth_limit';

    /** Ran sequentially — global budget exhausted, falling back to protect the pool. */
    case SequentialBudgetExhausted = 'sequential_budget_exhausted';

    /** Ran sequentially — APCu / budget store unavailable so we don't risk it. */
    case SequentialNoBudgetStore = 'sequential_no_budget_store';
}
