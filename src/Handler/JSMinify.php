<?php


namespace dreadkopp\HTML_OutputOptimizer\Handler;


use DOMDocument;
use dreadkopp\HTML_OutputOptimizer\Library\Lazyload;
use dreadkopp\HTML_OutputOptimizer\OutputOptimizer;

class JSMinify
{
    private static $basename = 'base.js';

    public static function minify($buffer, $root_dir, $cache_dir, $inline_js, $public_cache_dir, $js_version, $local_js)
    {

        $combined_js = '';
        $base_js = '';

        //1. check if we already got a cached version

        $cachepath = $root_dir . $cache_dir . 'js/';
        if (!file_exists($cachepath)) {
            mkdir($cachepath, 0770, true);
        }

        self::prepareLocalBaseJs($cachepath, $local_js);

        $filename = hash('md5', $_SERVER['REQUEST_URI']);
        $cachedAndOptimizedName = $filename . '.js';
        $path = $cachepath . $cachedAndOptimizedName;

        //if we have a saved version, use that one
        if (file_exists($path) && (time() - filemtime($path) < OutputOptimizer::CACHETIME * 24 - 10)) {

            //gather inline js
            $dom = new DOMDocument();
            @$dom->loadHTML($buffer);
            $script = $dom->getElementsByTagName('script');
            foreach ($script as $js) {
                $js = $js->nodeValue;
                $js = preg_replace('/<!--(.*)-->/Uis', '$1', $js);
                $inline_js .= $js;
            }

            return self::finalize($buffer, $inline_js, $public_cache_dir, $cachedAndOptimizedName, $js_version);

        }
    
    
        if (file_exists($path)) {
            unlink($path);
        }
        // find js sources and collect
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

        
        try {
            $fp = fopen($path, 'x');
            if (fwrite($fp, $combined_js)) {
                fclose($fp);
            }
        } catch (\Exception $e) {
            //prevent timing issues where file already exists due to multiple users accessing the page
        }


        return self::finalize($buffer, $inline_js, $public_cache_dir, $cachedAndOptimizedName, $js_version);
    }

    private static function prepareLocalBaseJs(string $cachepath, array $local_js): void
    {
        $path = $cachepath . self::$basename;
        //keep it a week
        if (file_exists($path) && (time() - filemtime($path) < OutputOptimizer::CACHETIME * 24 * 7 - 10)) {
            return;
        }

        $combined_js = '';

        if (count($local_js)) {
            foreach ($local_js as $local_js_path) {
		        $combined_js .= '//'.PHP_EOL;
		        $combined_js .= '//'.PHP_EOL;
		        $combined_js .= '//'.$local_js_path .PHP_EOL;
		        $combined_js .= '//'.PHP_EOL;
		        $combined_js .= '//'.PHP_EOL;
                $combined_js .= file_get_contents($local_js_path);
            }
        }

        $combined_js .= Lazyload::LAZYLOADJS;

        if (file_exists($path)) {
            unlink($path);
        }
        $fp = fopen($path, 'x');
        if (fwrite($fp, $combined_js)) {
            fclose($fp);
        }
        return;

    }

    private static function finalize($buffer, $inline_js, $public_cache_dir, $cachedAndOptimizedName, $js_version = 1)
    {

        //remove old script apperances
        $buffer = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $buffer);

        //put all the JS on bottom

        $base_relative_path = $public_cache_dir . 'js/' . self::$basename . '?v=' . $js_version;

        $relative_path = $public_cache_dir . 'js/' . $cachedAndOptimizedName . '?v=' . $js_version;

        $buffer .= '<script src="' . $base_relative_path . '"></script>';

        $buffer .= '<script src="' . $relative_path . '"></script>';

        $inline_js = preg_replace('/\/\*[\s\S]*?\*\/|([^\\:]|^)\/\/.*$/m', '$1', $inline_js);

        $buffer .= '<script>' . $inline_js . '</script>';

        return $buffer;
    }
}
