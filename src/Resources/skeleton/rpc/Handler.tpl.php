<?= "<?php\n" ?>

declare(strict_types=1);

namespace <?= $namespace ?>;

<?= $use_statements ?>

#[Rpc\Method('<?= $method_name ?>')]
final class <?= $class_name ?>

{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(<?php if ($dto_short_name): ?><?= $dto_short_name ?> $request, <?php endif; ?>Context $ctx): array
    {
        // TODO: implement the method.
        return [
            'ok' => true,
        ];
    }
}
