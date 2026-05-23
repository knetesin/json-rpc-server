<?php

declare(strict_types=1);

namespace Knetesin\JsonRpcServerBundle\Maker;

use Knetesin\JsonRpcServerBundle\Attribute as Rpc;
use Knetesin\JsonRpcServerBundle\Context\Context;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Bundle\MakerBundle\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * `make:rpc-method` scaffolder. Creates a handler class wired with the bundle's
 * `#[Rpc\Method]` attribute and — by request — a typed request DTO and a
 * functional test skeleton.
 *
 * The defaults are intentionally minimal: a handler that takes Context plus
 * the optional DTO and returns an array. The user fills in the body and the
 * DTO properties; everything around them is wired up.
 *
 * Run interactively (`bin/console make:rpc-method`) for guided prompts, or
 * non-interactively with `--method` / `--with-dto` / `--with-test` flags
 * (useful from CI scaffolders).
 */
final class MakeRpcMethod extends AbstractMaker
{
    public static function getCommandName(): string
    {
        return 'make:rpc-method';
    }

    public static function getCommandDescription(): string
    {
        return 'Scaffold a JSON-RPC method handler (with optional DTO and test).';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->addArgument(
                'class',
                InputArgument::OPTIONAL,
                'PHP class name for the handler (e.g. <fg=yellow>UserGetByEmail</>). Stored under src/Rpc/.',
            )
            ->addOption(
                'method',
                null,
                InputOption::VALUE_REQUIRED,
                'JSON-RPC method name (e.g. <fg=yellow>user.getByEmail</>). Defaults to the class name converted to dot.case.',
            )
            ->addOption('with-dto', null, InputOption::VALUE_NONE, 'Also generate a request DTO class under src/Rpc/Dto/.')
            ->addOption('with-test', null, InputOption::VALUE_NONE, 'Also generate a functional test under tests/Rpc/.')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command generates a JSON-RPC handler:

                  <info>bin/console make:rpc-method UserGetByEmail</info>

                It will ask whether to also create a request DTO and a functional test.
                For a fully scripted invocation:

                  <info>bin/console make:rpc-method UserGetByEmail \
                      --method=user.getByEmail --with-dto --with-test</info>
                HELP);
    }

    public function interact(InputInterface $input, ConsoleStyle $io, Command $command): void
    {
        if (null === $input->getArgument('class')) {
            $argument = $command->getDefinition()->getArgument('class');
            $value = $io->ask(
                (string) $argument->getDescription(),
                null,
                Validator::notBlank(...),
            );
            $input->setArgument('class', $value);
        }

        if (null === $input->getOption('method')) {
            $default = self::dotCase((string) $input->getArgument('class'));
            $value = $io->ask(
                \sprintf('JSON-RPC method name (default: <fg=yellow>%s</>)', $default),
                $default,
                Validator::notBlank(...),
            );
            $input->setOption('method', $value);
        }

        if (!$input->getOption('with-dto')) {
            $input->setOption(
                'with-dto',
                $io->askQuestion(new ConfirmationQuestion('Generate a request DTO?', true)),
            );
        }

        if (!$input->getOption('with-test')) {
            $input->setOption(
                'with-test',
                $io->askQuestion(new ConfirmationQuestion('Generate a functional test?', true)),
            );
        }
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $rawClass = (string) $input->getArgument('class');
        $methodName = (string) $input->getOption('method');
        $withDto = (bool) $input->getOption('with-dto');
        $withTest = (bool) $input->getOption('with-test');

        $handlerDetails = $generator->createClassNameDetails(
            Str::asClassName($rawClass),
            'Rpc\\',
        );

        $dtoDetails = null;
        if ($withDto) {
            // Naming convention: <Handler>Request — same family as Symfony
            // form-type / DTO conventions; clear pairing in IDE completions.
            $dtoDetails = $generator->createClassNameDetails(
                $handlerDetails->getShortName().'Request',
                'Rpc\\Dto\\',
            );
        }

        // Manual use-block: we want a namespace alias (`use … as Rpc;`), which
        // UseStatementGenerator doesn't model. Lines are sorted for stable
        // output and to match php-cs-fixer's `ordered_imports` rule.
        $handlerUses = [
            'use Knetesin\JsonRpcServerBundle\\Attribute as Rpc;',
            'use '.Context::class.';',
        ];
        if (null !== $dtoDetails) {
            $handlerUses[] = 'use '.$dtoDetails->getFullName().';';
        }
        sort($handlerUses);

        $generator->generateClass(
            $handlerDetails->getFullName(),
            \dirname(__DIR__).'/Resources/skeleton/rpc/Handler.tpl.php',
            [
                'use_statements' => implode("\n", $handlerUses),
                'method_name' => $methodName,
                'dto_short_name' => $dtoDetails?->getShortName(),
            ],
        );

        if (null !== $dtoDetails) {
            // Keep one annotated property in the skeleton so newcomers see
            // the validator pattern in context; the user can prune it.
            $generator->generateClass(
                $dtoDetails->getFullName(),
                \dirname(__DIR__).'/Resources/skeleton/rpc/Dto.tpl.php',
                ['use_statements' => 'use Symfony\\Component\\Validator\\Constraints as Assert;'],
            );
        }

        if ($withTest) {
            $testDetails = $generator->createClassNameDetails(
                $handlerDetails->getShortName(),
                'Tests\\Rpc\\',
                'Test',
            );
            $generator->generateClass(
                $testDetails->getFullName(),
                \dirname(__DIR__).'/Resources/skeleton/rpc/Test.tpl.php',
                [
                    'method_name' => $methodName,
                ],
            );
        }

        $generator->writeChanges();

        $this->writeSuccessMessage($io);
        $io->text([
            \sprintf('Next: open <info>%s</info> and fill in the handler body.', $handlerDetails->getFullName()),
            'Then call it: <info>POST /rpc</info> with '
                .'<comment>{"jsonrpc":"2.0","method":"'.$methodName.'","params":{...},"id":1}</comment>.',
            'Inspect every registered method with <info>bin/console debug:rpc</info>.',
        ]);
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
        // No extra deps beyond the bundle itself — the attributes already live
        // in the runtime. If the host project lacks symfony/validator (rare),
        // the generated DTO's Assert\* annotations will still parse but stay
        // dormant; we don't enforce that here.
        $dependencies->addClassDependency(Rpc\Method::class, 'knetesin/json-rpc');
    }

    /**
     * Converts a PHP class name into a dot.case method name.
     *
     *   UserGetByEmail  →  user.get_by_email
     *   FooBarBaz       →  foo.bar_baz
     *
     * Heuristic: the first word becomes the namespace, the rest collapses
     * into a snake_case action. Good enough for a default; the user can
     * override via `--method` when conventions differ.
     */
    private static function dotCase(string $class): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)([A-Z])/', '_$1', $class));
        $parts = explode('_', $snake);
        if (\count($parts) < 2) {
            return $snake;
        }
        $namespace = array_shift($parts);

        return $namespace.'.'.implode('_', $parts);
    }
}
