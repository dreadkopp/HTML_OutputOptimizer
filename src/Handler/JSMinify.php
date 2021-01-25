<?php


namespace dreadkopp\HTML_OutputOptimizer\Handler;


use DOMDocument;
use dreadkopp\HTML_OutputOptimizer\Library\Lazyload;
use dreadkopp\HTML_OutputOptimizer\OutputOptimizer;

class JSMinify
{
	public static function minify($buffer, $root_dir, $cache_dir, $inline_js, $public_cache_dir, $js_version, $local_js)
	{
		
		$combined_js = '';
		//1. check if we already got a cached version
		
		$cachepath = $root_dir . $cache_dir;
		if (!file_exists($cachepath)) {
			mkdir($cachepath, 0770, true);
		}
		
		$filename = hash('md5', $_SERVER['REQUEST_URI']);
		$cachedAndOptimizedName = $filename . '.js';
		$path = $cachepath . $cachedAndOptimizedName;
		
		//if we have a saved version, use that one
		if (file_exists($path) && (time() - filemtime($path) < OutputOptimizer::CACHETIME - 10)) {
			
			//gather inline js
			$dom = new DOMDocument();
			@$dom->loadHTML($buffer);
			$script = $dom->getElementsByTagName('script');
			foreach ($script as $js) {
				$js = $js->nodeValue;
				$js = preg_replace('/<!--(.*)-->/Uis', '$1', $js);
				$inline_js .= $js;
			}
			
			//remove old script apperances
			$buffer = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $buffer);
			
			//put all the JS on bottom
			$relative_path = $public_cache_dir . $cachedAndOptimizedName . '?v=' . $js_version;
			$buffer .= '<script src="' . $relative_path . '"></script>';
			$inline_js = preg_replace('/\/\*[\s\S]*?\*\/|([^\\:]|^)\/\/.*$/m','$1',$inline_js);
			$buffer .= '<script>' . $inline_js . '</script>';
			
			return $buffer;
			
		}
		if (file_exists($path)) {
			unlink($path);
		}
		
		
		//1. add local js
		if (count($local_js)) {
			foreach ($local_js as $local_js_path) {
				$minified_js = file_get_contents($local_js_path);
				$combined_js = $combined_js . $minified_js;
			}
		}
		
		//2. find js sources and collect
		$dom = new DOMDocument();
		@$dom->loadHTML($buffer);
		$script = $dom->getElementsByTagName('script');
		foreach ($script as $js) {
			$src = $js->getAttribute('src');
			if (!$src) {
				$js = $js->nodeValue;
				$js = preg_replace('/<!--(.*)-->/Uis', '$1', $js);
				$inline_js .= $js;
			} else {
				$ch = curl_init($src);
				curl_setopt($ch, CURLOPT_HEADER, 0);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
				$raw = curl_exec($ch);
				curl_close($ch);
				$combined_js .= $raw . ';';
			}
			
		}
		
		//3. add lazyload js .... needs jquery being imported in externals or locals before ...
		//TODO: add logic to check if jquery is present, else import
		$combined_js .= Lazyload::LAZYLOADJS;
		
		//remove old script apperances
		$buffer = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $buffer);
		
		if (file_exists($path)) {
			unlink($path);
		}
		$fp = fopen($path, 'x');
		if (fwrite($fp, $combined_js)) {
			fclose($fp);
		}
		
		
		//put all the JS on bottom
		$relative_path = $public_cache_dir . $cachedAndOptimizedName . '?v=' . $js_version;
		$buffer .= '<script src="' . $relative_path . '"></script>';
		$buffer .= '<script>' . $inline_js . '</script>';
		
		return $buffer;
	}
}
