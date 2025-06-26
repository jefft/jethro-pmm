<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__)
    ->exclude([
        'vendor',
        'resources/ckeditor',
		'resources/less'
    ])
    ->name('*.php')
    ->notPath('calls/call_envelopes.class.php')
    ->ignoreDotFiles(true);

return (new Config())
    ->setRiskyAllowed(true)
	->setIndent("\t")
    ->setLineEnding("\n")
    ->setRules([
        '@PSR12' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP82Migration' => true,
        '@PHP83Migration' => true,

		'visibility_required' => false,
        // Clean code rules
        'strict_param' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_import_per_statement' => true,
        'combine_consecutive_unsets' => true,
		// annoying for little speed gain
        // 'native_function_invocation' => ['include' => ['@internal']],
        'no_superfluous_phpdoc_tags' => true,
        'phpdoc_to_comment' => false, // keep actual useful phpdocs
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'yoda_style' => false,
        'multiline_comment_opening_closing' => true,
        'modernize_types_casting' => true,
        'modernize_strpos' => true,
        'modernize_strpos' => true,
        'nullable_type_declaration_for_default_null_value' => true,

        // Optional but nice
        'blank_line_after_opening_tag' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments']],
        'no_blank_lines_after_class_opening' => true,
        'no_empty_phpdoc' => true,
        'simplified_if_return' => true,
    ])
    ->setFinder($finder);