<?php

// Alternative configuration for stricter formatting (use with --config=.php-cs-fixer.dist.php)

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude([
		'vendor',
		'resources/ckeditor',
		'resources/less'
    ])
    ->name('*.php')
    ->notPath('calls/call_envelopes.class.php')
    ->ignoreDotFiles(true);

$config = new PhpCsFixer\Config();
return $config
    ->setRules([
        '@PSR12' => true,
        '@PHP80Migration' => true,
        
        // Modern array syntax
        'array_syntax' => ['syntax' => 'short'],
        
        // Import organization
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha'
        ],
        'no_unused_imports' => true,
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        
        // String handling
        'single_quote' => true,
        'escape_implicit_backslashes' => true,
        
        // Code structure
        'trailing_comma_in_multiline' => true,
        'no_trailing_comma_in_singleline' => true,
        'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
        
        // Whitespace
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try', 'if', 'for', 'foreach', 'while', 'switch']
        ],
        'no_extra_blank_lines' => [
            'tokens' => ['extra', 'throw', 'use', 'use_trait']
        ],
        
        // Comments
        'single_line_comment_style' => true,
        'multiline_comment_opening_closing' => true,
        
        // Class organization
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'constant_public',
                'constant_protected', 
                'constant_private',
                'property_public_static',
                'property_protected_static',
                'property_private_static',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'method_public_static',
                'method_protected_static',
                'method_private_static',
                'method_public',
                'method_protected',
                'method_private',
            ]
        ],
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
                'property' => 'one',
            ]
        ],
        
        // Operators
        'binary_operator_spaces' => [
            'default' => 'single_space',
            'operators' => ['=>' => 'align_single_space_minimal']
        ],
        'concat_space' => ['spacing' => 'one'],
        
        // Control structures
        'yoda_style' => false,
        'is_null' => true,
        
        // Functions
        'function_declaration' => ['closure_function_spacing' => 'one'],
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
            'keep_multiple_spaces_after_comma' => false,
        ],
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setUsingCache(true);