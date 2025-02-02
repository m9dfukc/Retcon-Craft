<?php
/**
 * Retcon plugin for Craft CMS 3.x
 *
 * A collection of powerful Twig filters for modifying HTML
 *
 * @link      https://vaersaagod.no
 * @copyright Copyright (c) 2017 Mats Mikkel Rummelhoff
 */

namespace mmikkel\retcon\services;

use craft\base\Element;
use mmikkel\retcon\Retcon;
use mmikkel\retcon\library\RetconDom;
use mmikkel\retcon\library\RetconHelper;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\Template as TemplateHelper;

use Symfony\Component\DomCrawler\Crawler;
use Twig\Template;
use yii\base\Exception;

/**
 * Retcon Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Mats Mikkel Rummelhoff
 * @package   Retcon
 * @since     1.0.0
 */
class RetconService extends Component
{

    /**
     * @param $html
     * @param mixed ...$args
     * @return mixed|string|null
     * @throws Exception
     */
    public function retcon($html, ...$args)
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        if (empty($args)) {
            throw new Exception('No filter method or callbacks defined');
        }

        $ops = \is_array($args[0]) ? $args[0] : [$args];

        foreach ($ops as $op) {

            $args = \is_array($op) ? $op : [$op];
            $filter = \array_shift($args);

            if (!\method_exists($this, $filter)) {
                throw new Exception('Undefined filter method {$filter}');
            }

            $html = \call_user_func_array([$this, $filter], \array_merge([$html], $args));

        }

        return $html;

    }

    /**
     * Applies an image transform to all images (or all nodes matching the passed selector(s))
     *
     * @param $html
     * @param $transform
     * @param string $selector
     * @param array $imagerTransformDefaults
     * @param array $imagerConfigOverrides
     * @return string|\Twig\Markup|\Twig_Markup|null
     * @throws Exception
     * @throws \aelvan\imager\exceptions\ImagerException
     * @throws \craft\errors\AssetTransformException
     * @throws \craft\errors\ImageException
     * @throws \craft\errors\SiteNotFoundException
     */
    public function transform($html, $transform, $selector = 'img', array $imagerTransformDefaults = [], array $imagerConfigOverrides = [])
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = $dom->filter($selector);

        if (empty($nodes)) {
            return TemplateHelper::raw($html);
        }

        $inlineWidth = isset($transform['inlineWidth']) && $transform['inlineWidth'] == true;
        $inlineHeight = isset($transform['inlineHeight']) && $transform['inlineHeight'] == true;

        $transform = RetconHelper::getImageTransform($transform);

        if (!$transform) {
            return $html;
        }

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {

            if (!$src = $node->getAttribute('src')) {
                continue;
            }

            if (!$transformedImage = RetconHelper::getTransformedImage($src, $transform, $imagerTransformDefaults, $imagerConfigOverrides)) {
                continue;
            }

            $node->setAttribute('src', $transformedImage->url);

            if ($node->getAttribute('width') || $inlineWidth) {
                $node->setAttribute('width', $transformedImage->width);
            }

            if ($node->getAttribute('height') || $inlineHeight) {
                $node->setAttribute('height', $transformedImage->height);
            }

        }

        return $dom->getHtml();

    }

    /**
     * Creates a srcset attribute for all images (or all nodes matching the selector(s) passed) with the proper transforms
     *
     * @param $html
     * @param $transforms
     * @param string $selector
     * @param string|array $sizes
     * @param bool $base64src
     * @param array $transformDefaults
     * @param array $configOverrides
     * @return string|\Twig\Markup|\Twig_Markup|null
     * @throws Exception
     * @throws \craft\errors\SiteNotFoundException
     */
    public function srcset($html, $transforms, $selector = 'img', $sizes = '100w', $base64src = false, $transformDefaults = [], $configOverrides = [])
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = $dom->filter($selector);

        if (empty($nodes)) {
            return TemplateHelper::raw($html);
        }

        // Get transforms
        if (!\is_array($transforms)) {
            $transforms = [$transforms];
        }

        $transforms = \array_reduce($transforms, function ($carry, $transform) {
            $attr = isset($transform['attr']) ? $transform['attr'] : 'w';
            $transform = RetconHelper::getImageTransform($transform);
            if ($transform) {
                $carry[] = array(
                    'transform' => $transform, 
                    'attr' => $attr
                );
            }
            return $carry;
        }, []);
        
        if (empty($transforms)) {
            return $html;
        }

        // Get sizes attribute
        if ($sizes && \is_array($sizes)) {
            $sizes = \implode(', ', $sizes);
        }

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {

            if (!$src = $node->getAttribute('src')) {
                continue;
            }
            // Get transformed images
            $transformedImages = \array_reduce($transforms, function ($carry, $transform) use ($src, $transformDefaults, $configOverrides) {
                if ($transformedImage = RetconHelper::getTransformedImage($src, $transform['transform'], $transformDefaults, $configOverrides)) {
                    $carry[] = array('image'=>$transformedImage, 'attr'=>$transform['attr']);
                }
                return $carry;
            }, []);

            if (empty($transformedImages)) {
                continue;
            }

            // Add srcset attribute
            $node->setAttribute('srcset', RetconHelper::getSrcsetAttribute($transformedImages));

            // Add sizes attribute
            if ($sizes) {
                $node->setAttribute('sizes', $sizes);
            }

            $node->setAttribute('src', RetconHelper::parseRef($src));

            // Swap out the src for a base64 encoded SVG?
            if (!$base64src) {
                continue;
            }

            $dimensions = RetconHelper::getImageDimensions($node) ?? [];
            $width = $dimensions['width'] ?? null;
            $height = $dimensions['height'] ?? null;
            $node->setAttribute('src', RetconHelper::getBase64Pixel($width, $height));
        }

        return $dom->getHtml();

    }

    /**
     * Prepares all images (or all nodes matching the selector(s) passed) by swapping out the `src` attribute with a base64 encoded, transparent SVG. The original source will be retained in a data attribute
     *
     * @param $html
     * @param string $selector
     * @param string $className
     * @param string $attributeName
     * @return string|\Twig\Markup|\Twig_Markup|null
     * @throws Exception
     * @throws \craft\errors\SiteNotFoundException
     */
    public function lazy($html, $selector = 'img', $className = 'lazyload', $attributeName = 'src')
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = $dom->filter($selector);

        if (empty($nodes)) {
            return TemplateHelper::raw($html);
        }

        $attributeName = "data-{$attributeName}";

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {

            $imageClasses = \explode(' ', $node->getAttribute('class'));
            $imageClasses[] = $className;

            $node->setAttribute('class', \trim(\implode(' ', $imageClasses)));
            $node->setAttribute($attributeName, RetconHelper::parseRef($node->getAttribute('src')));

            $dimensions = RetconHelper::getImageDimensions($node) ?? [];
            $width = $dimensions['width'] ?? null;
            $height = $dimensions['height'] ?? null;

            $node->setAttribute('src', RetconHelper::getBase64Pixel($width, $height));

            if ($width) {
                $node->setAttribute('width', $width);
            }

            if ($height) {
                $node->setAttribute('height', $height);
            }
        }

        return $dom->getHtml();

    }

    /**
     * Attempts to auto-generate alternative text for all images (or all elements matching the $selector attribute).
     *
     * @param $html
     * @param string $selector
     * @param string $field
     * @param bool $overwrite
     * @return \Twig\Markup|\Twig_Markup
     * @throws \craft\errors\SiteNotFoundException
     */
    public function autoAlt($html, $selector = 'img', $field = 'title', $overwrite = false)
    {

        if (!RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = $dom->filter($selector);

        if (empty($nodes)) {
            return TemplateHelper::raw($html);
        }

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {
            if (!$src = $node->getAttribute('src')) {
                continue;
            }
            if ($overwrite || !$node->getAttribute('alt')) {
                $elementId = RetconHelper::getElementIdFromRef($src);
                /** @var Element $element */
                $element = $elementId ? Craft::$app->getElements()->getElementById($elementId) : null;
                $alt = null;
                if ($element) {
                    $alt = $element->$field ?: $element->title ?: null;
                }
                if (!$alt) {
                    $imageSourcePathinfo = \pathinfo($src);
                    $alt = $imageSourcePathinfo['filename'] ?? '';
                }
                $node->setAttribute('alt', $alt);
            }
        }

        return $dom->getHtml();

    }

    /**
     * Adds (to) or replaces one or many attributes for one or many selectors
     *
     * @param $html
     * @param $selector
     * @param array $attributes
     * @param bool $overwrite
     * @return string|\Twig\Markup|\Twig_Markup|null
     * @throws \craft\errors\SiteNotFoundException
     */
    public function attr($html, $selector, array $attributes, $overwrite = true)
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = $dom->filter($selector);

        if (empty($nodes)) {
            return TemplateHelper::raw($html);
        }

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {
            foreach ($attributes as $key => $value) {
                if (!$value) {
                    // Falsey value, remove attribute
                    $node->removeAttribute($key);
                } else if ($value === true) {
                    // For true, just add an empty attribute
                    $node->setAttribute($key, '');
                } else {
                    // Add attribute, either overwriting/replacing the old attribute values or just appending to it
                    if ($overwrite !== true && $key !== 'id') {
                        $attributeValues = \explode(' ', $node->getAttribute($key));
                        if ($overwrite === 'prepend') {
                            $attributeValues = \array_merge([$value], $attributeValues);
                        } else {
                            $attributeValues[] = $value;
                        }
                    } else {
                        $attributeValues = [$value];
                    }
                    $node->setAttribute($key, \trim(\implode(' ', \array_unique(\array_filter($attributeValues)))));
                }
            }
        }

        return $dom->getHtml();

    }

    /**
     * Renames attributes for matching selector(s)
     *
     * @param $html
     * @param $selector
     * @param array $attributes
     * @return string|\Twig\Markup|\Twig_Markup|null
     * @throws \craft\errors\SiteNotFoundException
     */
    public function renameAttr($html, $selector, array $attributes)
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = $dom->filter($selector);

        if (empty($nodes)) {
            return TemplateHelper::raw($html);
        }

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {
            foreach ($attributes as $existingAttributeName => $desiredAttributeName) {
                if (!$desiredAttributeName || $existingAttributeName === $desiredAttributeName || !$node->hasAttribute($existingAttributeName)) {
                    continue;
                }
                $attributeValue = $node->getAttribute($existingAttributeName);
                $node->removeAttribute($existingAttributeName);
                $node->setAttribute($desiredAttributeName, $attributeValue);
            }
        }

        return $dom->getHtml();

    }

    /**
     * Remove all elements matching given selector(s)
     *
     * @param $html
     * @param $selector
     * @return string|\Twig\Markup|\Twig_Markup|null
     * @throws \craft\errors\SiteNotFoundException
     */
    public function remove($html, $selector)
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = $dom->filter($selector);

        if (empty($nodes)) {
            return TemplateHelper::raw($html);
        }

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {
            $node->parentNode->removeChild($node);
        }

        return $dom->getHtml();

    }

    /**
     * Remove everything except nodes matching given selector(s)
     *
     * @param $html
     * @param $selector
     * @return string|\Twig\Markup|\Twig_Markup|null
     * @throws \craft\errors\SiteNotFoundException
     */
    public function only($html, $selector)
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = $dom->filter($selector);

        if (empty($nodes)) {
            return TemplateHelper::raw('');
        }

        $doc = $dom->getDoc();
        $fragment = $doc->createDocumentFragment();

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {
            $fragment->appendChild($node);
        }

        $doc->firstChild->parentNode->replaceChild($fragment, $doc->firstChild);

        return $dom->getHtml();

    }

    /**
     * Changes tag type/name for given selector(s). Can also remove tags (whilst retaining their contents) by passing `false` for the $toTag parameter
     *
     * @param $html
     * @param $selector
     * @param $toTag
     * @return string|\Twig\Markup|\Twig_Markup|null
     * @throws \craft\errors\SiteNotFoundException
     */
    public function change($html, $selector, $toTag)
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = $dom->filter($selector);

        if (empty($nodes)) {
            return TemplateHelper::raw($html);
        }

        $doc = $dom->getDoc();

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {

            // Deep copy the (inner) element
            $fragment = $doc->createDocumentFragment();
            while ($node->childNodes->length > 0) {
                $fragment->appendChild($node->childNodes->item(0));
            }
            // Remove or change the tag?
            if (!$toTag) {
                // Remove it chief
                $node->parentNode->replaceChild($fragment, $node);
            } else {
                // Ch-ch-changes
                $newElement = $node->ownerDocument->createElement($toTag);
                foreach ($node->attributes as $attribute) {
                    $newElement->setAttribute($attribute->nodeName, $attribute->nodeValue);
                }
                $newElement->appendChild($fragment);
                $node->parentNode->replaceChild($newElement, $node);
            }
        }

        return $dom->getHtml();

    }

    /**
     * Wraps all nodes matching the given selector(s) in a container
     *
     * @param $html
     * @param $selector
     * @param $container
     * @return string|\Twig\Markup|\Twig_Markup|null
     * @throws \craft\errors\SiteNotFoundException
     */
    public function wrap($html, $selector, $container)
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = $dom->filter($selector);

        if (empty($nodes)) {
            return TemplateHelper::raw($html);
        }

        $doc = $dom->getDoc();

        // Get wrapper
        $container = RetconHelper::getSelectorObject($container);
        $container->tag = $container->tag === '*' ? 'div' : $container->tag;
        $containerNode = $doc->createElement($container->tag);

        if ($container->attribute) {
            $containerNode->setAttribute($container->attribute, $container->attributeValue);
        }

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {
            $containerClone = $containerNode->cloneNode(true);
            $node->parentNode->replaceChild($containerClone, $node);
            $containerClone->appendChild($node);
        }

        return $dom->getHtml();

    }

    /**
     * Removes the parent of all nodes matching given selector(s), retaining all child nodes
     *
     * @param $html
     * @param $selector
     * @return string|\Twig\Markup|\Twig_Markup|null
     * @throws \craft\errors\SiteNotFoundException
     */
    public function unwrap($html, $selector)
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = $dom->filter($selector);

        if (empty($nodes)) {
            return TemplateHelper::raw($html);
        }

        $doc = $dom->getDoc();

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {
            $parentNode = $node->parentNode;
            $fragment = $doc->createDocumentFragment();

            while ($parentNode->childNodes->length > 0) {
                $fragment->appendChild($parentNode->childNodes->item(0));
            }

            $parentNode->parentNode->replaceChild($fragment, $parentNode);
        }

        return $dom->getHtml();

    }

    /**
     * Injects string value (could be HTML!) into all nodes matching given selector(s)
     *
     * @param $html
     * @param $selector
     * @param $toInject
     * @param bool $overwrite
     * @return string|\Twig\Markup|\Twig_Markup|null
     * @throws \craft\errors\SiteNotFoundException
     */
    public function inject($html, $selector, $toInject, $overwrite = false)
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = $dom->filter($selector);

        if (empty($nodes)) {
            return TemplateHelper::raw($html);
        }

        $doc = $dom->getDoc();

        // What are we trying to inject, exactly?
        if (\preg_match("/<[^<]+>/", $toInject, $matches) != 0) {
            // Injected content is HTML
            $fragment = $doc->createDocumentFragment();
            $fragment->appendXML($toInject);
            $injectNode = $fragment->childNodes->item(0);
        } else {
            $textNode = $doc->createTextNode("{$toInject}");
        }

        /** @var \DOMElement $node */
        foreach ($nodes as $node) {

            if (!$overwrite) {

                if (isset($injectNode)) {
                    $node->appendChild($doc->importNode($injectNode->cloneNode(true), true));
                } else if (isset($textNode)) {
                    $node->appendChild($textNode->cloneNode());
                }

            } else {

                if (isset($injectNode)) {
                    $node->nodeValue = "";
                    $node->appendChild($doc->importNode($injectNode->cloneNode(true), true));
                } else if ($toInject) {
                    $node->nodeValue = $toInject;
                }

            }

        }

        return $dom->getHtml();

    }

    /**
     * Removes empty nodes matching given selector(s), or all empty nodes if no selector
     *
     * @param $html
     * @param $selector
     * @param bool $removeBr
     * @return string|\Twig\Markup|\Twig_Markup|null
     * @throws \craft\errors\SiteNotFoundException
     */
    public function removeEmpty($html, $selector = null, $removeBr = false)
    {

        if (!$html = RetconHelper::getHtmlFromParam($html)) {
            return $html;
        }

        $dom = new RetconDom($html);
        $nodes = null;

        if ($selector) {
            $nodes = $dom->filter($selector, false);
            if (empty($nodes)) {
                return TemplateHelper::raw($html);
            }
        }

        /** @var Crawler $crawler */
        $crawler = $nodes ?? $dom->getCrawler();

        if ($removeBr) {
            $xpathQuery = '//*[not(normalize-space())]';
        } else {
            $xpathQuery = '//*[not(self::br)][not(normalize-space())]';
        }

        $crawler->filterXPath($xpathQuery)->each(function (Crawler $crawler) {
            if (!$node = $crawler->getNode(0)) {
                return;
            }
            $node->parentNode->removeChild($node);
        });

        return $dom->getHtml();
    }

}
