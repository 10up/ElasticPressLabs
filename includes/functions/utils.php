<?php
/**
 * Plugin specific util functinos.
 *
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Utils;

/**
 * List of reserved words in JavaScript
 *
 * @see https://www.w3schools.com/js/js_reserved.asp
 * @return string
 */
function get_js_reserved_words() {
	return [
		'abstract',
		'arguments',
		'await',
		'boolean',
		'break',
		'byte',
		'case',
		'catch',
		'char',
		'class',
		'const',
		'continue',
		'debugger',
		'default',
		'delete',
		'do',
		'double',
		'else',
		'enum',
		'eval',
		'export',
		'extends',
		'false',
		'final',
		'finally',
		'float',
		'for',
		'function',
		'goto',
		'if',
		'implements',
		'import',
		'instanceof',
		'int',
		'interface',
		'let',
		'long',
		'native',
		'new',
		'null',
		'package',
		'private',
		'protected',
		'public',
		'return',
		'short',
		'static',
		'super',
		'switch',
		'synchronized',
		'throw',
		'throws',
		'transient',
		'true',
		'try',
		'typeof',
		'var',
		'void',
		'volatile',
		'while',
		'with',
		'yield',
		'abstract',
		'boolean',
		'byte',
		'char',
		'double',
		'final',
		'float',
		'goto',
		'int',
		'long',
		'native',
		'short',
		'synchronized',
		'throws',
		'transient',
		'volatile',
		'Array',
		'Date',
		'eval',
		'function',
		'hasOwnProperty',
		'Infinity',
		'isFinite',
		'isNaN',
		'isPrototypeOf',
		'length',
		'Math',
		'NaN',
		'name',
		'Number',
		'Object',
		'prototype',
		'String',
		'toString',
		'undefined',
		'valueOf',
		'getClass',
		'java',
		'JavaArray',
		'javaClass',
		'JavaObject',
		'JavaPackage',
		'alert',
		'all',
		'anchor',
		'anchors',
		'area',
		'assign',
		'blur',
		'button',
		'checkbox',
		'clearInterval',
		'clearTimeout',
		'clientInformation',
		'close',
		'closed',
		'confirm',
		'constructor',
		'crypto',
		'decodeURI',
		'decodeURIComponent',
		'defaultStatus',
		'document',
		'element',
		'elements',
		'embed',
		'embeds',
		'encodeURI',
		'encodeURIComponent',
		'escape',
		'event',
		'fileUpload',
		'focus',
		'form',
		'forms',
		'frame',
		'innerHeight',
		'innerWidth',
		'layer',
		'layers',
		'link',
		'location',
		'mimeTypes',
		'navigate',
		'navigator',
		'frames',
		'frameRate',
		'hidden',
		'history',
		'image',
		'images',
		'offscreenBuffering',
		'open',
		'opener',
		'option',
		'outerHeight',
		'outerWidth',
		'packages',
		'pageXOffset',
		'pageYOffset',
		'parent',
		'parseFloat',
		'parseInt',
		'password',
		'pkcs11',
		'plugin',
		'prompt',
		'propertyIsEnum',
		'radio',
		'reset',
		'screenX',
		'screenY',
		'scroll',
		'secure',
		'select',
		'self',
		'setInterval',
		'setTimeout',
		'status',
		'submit',
		'taint',
		'text',
		'textarea',
		'top',
		'unescape',
		'untaint',
		'window',
		'onblur',
		'onclick',
		'onerror',
		'onfocus',
		'onkeydown',
		'onkeypress',
		'onkeyup',
		'onmouseover',
		'onload',
		'onmouseup',
		'onmousedown',
		'onsubmit',
		'jQuery',
	];
}

/**
 * Get asset info from extracted asset files
 *
 * @param string $slug Asset slug as defined in build/webpack configuration
 * @param string $attribute Optional attribute to get. Can be version or dependencies
 * @return string|array
 */
function get_asset_info( $slug, $attribute = null ) {
	if ( file_exists( ELASTICPRESS_LABS_PATH . 'dist/js/' . $slug . '.asset.php' ) ) {
		$asset = require ELASTICPRESS_LABS_PATH . 'dist/js/' . $slug . '.asset.php';
	} elseif ( file_exists( ELASTICPRESS_LABS_PATH . 'dist/css/' . $slug . '.asset.php' ) ) {
		$asset = require ELASTICPRESS_LABS_PATH . 'dist/css/' . $slug . '.asset.php';
	} elseif ( file_exists( ELASTICPRESS_LABS_PATH . 'dist/blocks/' . $slug . '.asset.php' ) ) {
		$asset = require ELASTICPRESS_LABS_PATH . 'dist/blocks/' . $slug . '.asset.php';
	} else {
		return null;
	}

	if ( ! empty( $attribute ) && isset( $asset[ $attribute ] ) ) {
		return $asset[ $attribute ];
	}

	return $asset;
}

/**
 * `preg_replace()` that returns the subject unchanged when the pattern fails, for example
 * on the backtrack limit or on invalid UTF-8 with the `u` modifier, instead of `null`.
 *
 * @since 2.6.0
 * @param string          $pattern     Pattern.
 * @param string|string[] $replacement Replacement.
 * @param string          $subject     Subject.
 * @return string
 */
function preg_replace_safe( string $pattern, $replacement, string $subject ): string {
	$result = preg_replace( $pattern, $replacement, $subject );

	return null === $result ? $subject : $result;
}

/**
 * `preg_replace_callback()` that returns the subject unchanged when the pattern fails,
 * instead of `null`.
 *
 * @since 2.6.0
 * @param string   $pattern  Pattern.
 * @param callable $callback Callback.
 * @param string   $subject  Subject.
 * @return string
 */
function preg_replace_callback_safe( string $pattern, callable $callback, string $subject ): string {
	$result = preg_replace_callback( $pattern, $callback, $subject );

	return null === $result ? $subject : $result;
}

/**
 * `preg_split()` that returns the whole subject as the only piece when the pattern fails,
 * instead of `false`.
 *
 * @since 2.6.0
 * @param string $pattern Pattern.
 * @param string $subject Subject.
 * @param int    $limit   Limit.
 * @param int    $flags   Flags.
 * @return string[]
 */
function preg_split_safe( string $pattern, string $subject, int $limit = -1, int $flags = 0 ): array {
	$result = preg_split( $pattern, $subject, $limit, $flags );

	if ( false !== $result ) {
		return $result;
	}

	return ( $flags & PREG_SPLIT_NO_EMPTY ) && '' === $subject ? [] : [ $subject ];
}
