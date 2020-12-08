<?php


namespace dreadkopp\HTML_OutputOptimizer\Handler;


class CssMinify
{
	public static function minify($buffer) {
		
		$inline_style = '';
		$dom = new \DOMDocument();
		@$dom->loadHTML($buffer);
		$style = $dom->getElementsByTagName('style');
		foreach ($style as $s){
			$s = $s->nodeValue;
			$s = preg_replace('/<!--(.*)-->/Uis', '$1', $s);
			$inline_style .= $s ;
		}
		
		
		//remove old style apperances
		$buffer = preg_replace( '#<style(.*?)>(.*?)</style>#is','',$buffer);
		
		
		
		//insert inline css in head
		$buffer_exloded = explode('<head>',$buffer,2);
		$buffer_exloded[1] = $buffer_exloded[1]??'';
		$buffer = $buffer_exloded[0] . '<head><style>' .$inline_style .'</style>' . $buffer_exloded[1];
		
		return $buffer;
	}
	
}
