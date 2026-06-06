<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2.0'                         => true,
        'array_syntax'                        => ['syntax' => 'short'],
        'ordered_imports'                     => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'                   => true,
        'single_quote'                        => true,
        'trailing_comma_in_multiline'         => true,
        'phpdoc_align'                        => ['align' => 'left'],
        'no_superfluous_phpdoc_tags'          => true,
        'binary_operator_spaces'              => ['default' => 'align_single_space_minimal'],
    ])
    ->setFinder($finder);
