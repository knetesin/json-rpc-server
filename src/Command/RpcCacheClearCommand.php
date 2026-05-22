<?php

declare(strict_types=1);

namespace JsonRpcServer\Command;

use JsonRpcServer\Cache\RpcCacheInvalidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'rpc:cache:clear',
    description: 'Purges cached RPC method results — by method, by tag, or wholesale.',
)]
final class RpcCacheClearCommand extends Command
{
    public function __construct(private readonly RpcCacheInvalidator $invalidator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'method',
                InputArgument::OPTIONAL,
                'Method name to purge (e.g. "user.get"). Requires a tag-aware pool. Omit with --all or --tag for bulk modes.',
            )
            ->addOption(
                'tag',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Purge every entry tagged with one of these tags (repeatable).',
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Wipes the entire cache pool (everything, not just RPC entries).',
            )
            ->addOption(
                'pool',
                'p',
                InputOption::VALUE_REQUIRED,
                'Named pool from json_rpc_server.cache.pools to operate on (default pool when omitted).',
            )
            ->setHelp(<<<'HELP'
                Examples:

                  <info>%command.full_name% user.get</info>
                    Drop every cached result of `user.get` (needs a tag-aware pool).

                  <info>%command.full_name% --tag=user:42 --tag=tenant:acme</info>
                    Drop every entry stamped with any of the listed tags.

                  <info>%command.full_name% --all --pool=long_lived</info>
                    Clear the entire `long_lived` pool.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $method = $input->getArgument('method');
        /** @var list<string> $tags */
        $tags = $input->getOption('tag');
        $all = (bool) $input->getOption('all');
        $pool = $input->getOption('pool');

        $modes = (int) (null !== $method) + (int) ([] !== $tags) + (int) $all;
        if (1 !== $modes) {
            $io->error('Choose exactly one mode: a method name, --tag, or --all.');

            return Command::INVALID;
        }

        if (null !== $method) {
            $ok = $this->invalidator->purgeMethod($method);
            if (!$ok) {
                $io->warning(\sprintf('Nothing purged for "%s" — either the method is unknown, has no #[Rpc\\Cache], or the pool is not tag-aware.', $method));

                return Command::FAILURE;
            }
            $io->success(\sprintf('Cleared cache entries for method "%s".', $method));

            return Command::SUCCESS;
        }

        if ([] !== $tags) {
            $ok = $this->invalidator->purgeTags($tags, $pool);
            if (!$ok) {
                $io->warning('Nothing purged — pool is not tag-aware or no entries matched.');

                return Command::FAILURE;
            }
            $io->success(\sprintf('Cleared cache entries tagged: %s.', implode(', ', $tags)));

            return Command::SUCCESS;
        }

        $ok = $this->invalidator->purgeAll($pool);
        if (!$ok) {
            $io->error('Pool reported clear() failure.');

            return Command::FAILURE;
        }
        $io->success(\sprintf('Cleared %s cache pool.', null !== $pool ? '"'.$pool.'"' : 'default'));

        return Command::SUCCESS;
    }
}
