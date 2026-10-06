<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
    'tom-select' => ['version' => '2.6.2'],
    '@orchidjs/sifter' => ['version' => '1.1.0'],
    '@orchidjs/unicode-variants' => ['version' => '1.1.2'],
    'tom-select/dist/css/tom-select.default.min.css' => ['version' => '2.6.2', 'type' => 'css'],
    'tom-select/dist/css/tom-select.default.css' => ['version' => '2.6.2', 'type' => 'css'],
    'tom-select/dist/css/tom-select.bootstrap4.css' => ['version' => '2.6.2', 'type' => 'css'],
    'tom-select/dist/css/tom-select.bootstrap5.css' => ['version' => '2.6.2', 'type' => 'css'],
    '@symfony/ux-live-component' => ['path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js'],
    '@37signals/lexxy' => ['version' => '1.0.0'],
    'prismjs' => ['version' => '1.30.0'],
    'prismjs/components/prism-clike' => ['version' => '1.30.0'],
    'prismjs/components/prism-diff' => ['version' => '1.30.0'],
    'prismjs/components/prism-javascript' => ['version' => '1.30.0'],
    'prismjs/components/prism-markup' => ['version' => '1.30.0'],
    'prismjs/components/prism-markdown' => ['version' => '1.30.0'],
    'prismjs/components/prism-c' => ['version' => '1.30.0'],
    'prismjs/components/prism-css' => ['version' => '1.30.0'],
    'prismjs/components/prism-objectivec' => ['version' => '1.30.0'],
    'prismjs/components/prism-sql' => ['version' => '1.30.0'],
    'prismjs/components/prism-powershell' => ['version' => '1.30.0'],
    'prismjs/components/prism-python' => ['version' => '1.30.0'],
    'prismjs/components/prism-rust' => ['version' => '1.30.0'],
    'prismjs/components/prism-swift' => ['version' => '1.30.0'],
    'prismjs/components/prism-typescript' => ['version' => '1.30.0'],
    'prismjs/components/prism-java' => ['version' => '1.30.0'],
    'prismjs/components/prism-cpp' => ['version' => '1.30.0'],
    'prismjs/components/prism-markup-templating' => ['version' => '1.30.0'],
    'prismjs/components/prism-ruby' => ['version' => '1.30.0'],
    'prismjs/components/prism-php' => ['version' => '1.30.0'],
    'prismjs/components/prism-go' => ['version' => '1.30.0'],
    'prismjs/components/prism-bash' => ['version' => '1.30.0'],
    'prismjs/components/prism-json' => ['version' => '1.30.0'],
    'prismjs/components/prism-kotlin' => ['version' => '1.30.0'],
    'dompurify' => ['version' => '3.4.16'],
    '@lexical/selection' => ['version' => '0.44.0'],
    'lexical' => ['version' => '0.44.0'],
    '@lexical/link' => ['version' => '0.44.0'],
    '@lexical/extension' => ['version' => '0.44.0'],
    '@lexical/list' => ['version' => '0.44.0'],
    '@lexical/utils' => ['version' => '0.44.0'],
    '@lexical/plain-text' => ['version' => '0.44.0'],
    '@lexical/rich-text' => ['version' => '0.44.0'],
    '@lexical/html' => ['version' => '0.44.0'],
    '@lexical/history' => ['version' => '0.44.0'],
    '@lexical/code' => ['version' => '0.44.0'],
    '@lexical/markdown' => ['version' => '0.44.0'],
    '@lexical/table' => ['version' => '0.44.0'],
    'marked' => ['version' => '16.4.2'],
    '@lexical/clipboard' => ['version' => '0.44.0'],
    '@rails/activestorage' => ['version' => '8.1.400'],
    '@lexical/dragon' => ['version' => '0.44.0'],
    '@lexical/code-prism' => ['version' => '0.44.0'],
    '@lexical/code-core' => ['version' => '0.44.0'],
    'prismjs/themes/prism.min.css' => ['version' => '1.30.0', 'type' => 'css'],
    'prismjs/components/prism-clike.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-javascript.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-markup.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-markdown.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-c.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-css.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-objectivec.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-sql.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-powershell.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-python.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-rust.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-swift.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-typescript.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-java.js' => ['version' => '1.30.0'],
    'prismjs/components/prism-cpp.js' => ['version' => '1.30.0'],
    '@37signals/lexxy/dist/stylesheets/lexxy.css' => ['version' => '1.0.0', 'type' => 'css'],
    '@37signals/lexxy/dist/stylesheets/lexxy-content.css' => ['version' => '1.0.0', 'type' => 'css'],
];
