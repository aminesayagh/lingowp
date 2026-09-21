# WordPress scanner for Gettext

![Packagist Version](https://img.shields.io/packagist/v/10quality/gettext-wp-scanner)
![GitHub Workflow Status (with branch)](https://img.shields.io/github/actions/workflow/status/10quality/gettext-wp-scanner/php.yml)
![GitHub](https://img.shields.io/github/license/10quality/gettext-wp-scanner)

WordPress code scanner to use with [gettext/gettext](https://github.com/php-gettext/Gettext).

The scanner is a PHP based same as the **gettext** package.

## Installation

```bash
composer require 10quality/gettext-wp-scanner
```

## PHP usage example

```php
use Gettext\Translations;
use Gettext\Generator\PoGenerator;
use TenQuality\Gettext\Scanner\WPPhpScanner;

// Create a new scanner, adding the plugin / theme domain we want to get:
$phpScanner = new WPPhpScanner(
    Translations::create('my-domain')
);

// Scan PHP files
foreach (glob('*.php') as $file) {
    $phpScanner->scanFile($file);
}

//Save the translations in .po files
$generator = new PoGenerator();

foreach ($phpScanner->getTranslations() as $domain => $translations) {
    $generator->generateFile($translations, "locales/{$domain}.po");
}
```

## JavaScript usage example

```php
use Gettext\Translations;
use Gettext\Generator\PoGenerator;
use TenQuality\Gettext\Scanner\WPJsScanner;

// Create a new scanner, adding the plugin / theme domain we want to get:
$phpScanner = new WPJsScanner(
    Translations::create('my-domain')
);

// Scan PHP files
foreach (glob('*.js') as $file) {
    $phpScanner->scanFile($file);
}

//Save the translations in .po files
$generator = new PoGenerator();

foreach ($phpScanner->getTranslations() as $domain => $translations) {
    $generator->generateFile($translations, "locales/{$domain}.po");
}
```

## Coverage

**Languages**

- [x] PHP
- [X] JavaScript

**PHP functions**

- [x] `__()`
- [x] `_e()`
- [x] `_n()`
- [x] `_n_noop()`
- [x] `_x()`
- [x] `_nx()`
- [x] `_nx_noop()`
- [x] `esc_attr__()`
- [x] `esc_attr_e()`
- [x] `esc_attr_x()`
- [x] `esc_html__()`
- [x] `esc_html_e()`
- [x] `esc_html_x()`

**JavaScript functions**

- [x] `__()`
- [x] `_x()`
- [x] `_n()`
- [x] `_nx()`

## Requirements

* [gettext/gettext](https://github.com/php-gettext/Gettext)
* PHP >= 7.2.

## License

The MIT License (MIT). Please see `LICENSE` File for more information.