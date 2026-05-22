<?= "<?php\n" ?>

declare(strict_types=1);

namespace <?= $namespace ?>;

<?= $use_statements ?>

final readonly class <?= $class_name ?>

{
    public function __construct(
        // Sample field — replace with your own. Constraints like Assert\NotBlank,
        // Assert\Email, Assert\Range surface as -32602 Invalid params violations.
        #[Assert\NotBlank]
        public string $id,
    ) {
    }
}
