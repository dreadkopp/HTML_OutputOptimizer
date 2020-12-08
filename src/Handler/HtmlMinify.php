<?php

namespace dreadkopp\HTML_OutputOptimizer\Handler;

class HtmlMinify
{
	const  SEARCH = [
		'/\>[^\S ]+/s',      // strip whitespaces after tags, except space
		'/[^\S ]+\</s',      // strip whitespaces before tags, except space
		'/(\s)+/s',          // shorten multiple whitespace sequences
		'/<!DOCTYPE html>/', //initial DOCTYPE which would be minified as well... no worries, we add it later
		'/no_optimization_script/',
	
	];
	const  REPLACE = [
		'>',
		'<',
		'\\1',
		'',
		'script',
	
	];
	
	public static function minify(string $html): string
	{
		return '<!DOCTYPE html>'.PHP_EOL.preg_replace(self::SEARCH, self::REPLACE, $html);
	}
}
