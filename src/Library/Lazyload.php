<?php

namespace dreadkopp\HTML_OutputOptimizer\Library;

class Lazyload
{
	const LAZYLOADJS = ' //Lazy loading, refine to check / show per element
    function throttle(fn, threshhold, scope) {
      threshhold || (threshhold = 250);
      var last,
          deferTimer;
      return function () {
        var context = scope || this;
    
        var now = +new Date,
            args = arguments;
        if (last && now < last + threshhold) {
          // hold on to it
          clearTimeout(deferTimer);
          deferTimer = setTimeout(function () {
            last = now;
            fn.apply(context, args);
          }, threshhold);
        } else {
          last = now;
          fn.apply(context, args);
        }
      };
    }
    
    
    $(window).on("resize scroll load", throttle(function () {
        checkForLazyLoad();
    },100));


    function checkForLazyLoad(){
        var images = $("img[data-src]");
        if (images) {
            images.each(function (el, img) {
                if ($(this).optimisticIsInViewport()) {
                    img.setAttribute("src", img.getAttribute("data-src"));
                    img.onload = function () {
                        img.removeAttribute("data-src");
                    };
                }
            });
        }
        var images_back = $("img[data-background]");
        if (images_back) {
            images_back.each(function (el, img) {
                if ($(this).optimisticIsInViewport()) {
                    $(img).css("background-image", \'url(\' + img.getAttribute("data-background") + \')\');
                    img.onload = function () {
                        img.removeAttribute("data-background");
                    };
                }
            });
        }
        
        var iframes = $("iframe[data-src]");
        if (iframes) {
            iframes.each(function (el, iframe) {
                if ($(iframe).optimisticIsInViewport()) {
                    iframe.setAttribute("src", iframe.getAttribute("data-src"));
                    iframe.onload = function () {
                        iframe.removeAttribute("data-src");
                    };
                }
            });
        }
     };

    $.fn.isInViewport = function () {
        if (typeof $(this).offset() !== "undefined") {
            var elementTop = $(this).offset().top;
            var elementBottom = elementTop + $(this).outerHeight();

            var viewportTop = $(window).scrollTop();
            var viewportBottom = viewportTop + window.innerHeight;

            return elementBottom > viewportTop && elementTop < viewportBottom;
        }
        return false;
    };

    $.fn.optimisticIsInViewport = function () {
        if (typeof $(this).offset() !== "undefined") {
            var elementTop = $(this).offset().top;
            var elementBottom = elementTop + $(this).outerHeight();

            var viewportTop = $(window).scrollTop();
            var viewportBottom = viewportTop + 2 * window.innerHeight;

            return elementBottom >= viewportTop && elementTop < viewportBottom;
        }
        return false;
    };';
	
}
