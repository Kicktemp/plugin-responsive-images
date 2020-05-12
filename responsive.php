<?php
/**
 * @package     ttc-freebies.plugin-responsive-images
 *
 * @copyright   Copyright (C) 2017 Dimitrios Grammatikogiannis, Upshift LTD. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

jimport('joomla.filesystem.file');

/**
 * Content responsive images plugin
 */
class PlgContentResponsive extends JPlugin {
  /**
   * Plugin that adds srcset to all content images, also creates all the image sizes on the fly
   *
   * @param   string   $context  The context of the content being passed to the plugin.
   * @param   object   &$row     The article object.  Note $article->text is also available
   * @param   mixed    &$params  The article params
   * @param   integer  $page     The 'page' number
   *
   * @return  mixed  Always returns void or true
   *
   * @since   1.0
   */
  public function onContentPrepare($context, &$row, &$params, $page) {
    // We care only for articles and category
    if ($context !== 'com_content.article' || $context !== 'com_content.category') {
      return;
    }

    $dom = new domDocument;
    libxml_use_internal_errors(true);
    
    if ( $context === 'com_content.article' ) {
      $dom->loadHTML('<?xml encoding="utf-8" ?>' . $row->text);
    } else if ( $context === 'com_content.category' ) {
      $dom->loadHTML('<?xml encoding="utf-8" ?>' . $row->introtext);
    }
    libxml_clear_errors();

    $xpath      = new DOMXpath($dom);
    $body       = $xpath->query("//body");
    $images     = $xpath->query("//img");
    
    //Create new wrapper div
    $new_div = $dom->createElement('picture');
    $new_div->setAttribute('class','wrapper');
    $new_source = $dom->createElement('source');
    $new_source->setAttribute('type','image/webp');
    $validExt   = array('jpg', 'jpeg', 'png');
    $sizeSplit	= '_';
    $validSize  = array(320, 480, 768, 992, 1200, 1600, 1920);
    $quality    = (int) $this->params->get('quality', '85');
    $scaleUp    = (bool)($this->params->get('scaleUp', '0') == '1');

    switch (mb_strtolower($this->params->get('scaleMethod', 'inside'))) {
      case 'fill':
        $scaleMethod = JImage::SCALE_FILL;
        break;
      case 'outside':
        $scaleMethod = JImage::SCALE_OUTSIDE;
        break;
      case 'fit':
        $scaleMethod = JImage::SCALE_FIT;
        break;
      case 'inside':
      default:
        $scaleMethod = JImage::SCALE_INSIDE;
        break;
    }

    for ($i = 0, $l = $images->length; $i < $l; $i++) {
      //Clone our created picture
      $new_div_clone = $new_div->cloneNode();
      

      $new_source_clone = $new_source->cloneNode();
      $new_div_clone->appendChild($new_source_clone);

      //Replace image with this wrapper div - create 'source' before images
      $images->item($i)->parentNode->replaceChild($new_div_clone,$images->item($i));
      //Append this image to wrapper picture
      $new_div_clone->appendChild($images->item($i));

      // Get the original path
      $originalImagePath     = $images->item($i)->getAttribute('src');

      //define('COM_MEDIA_BASE', JPATH_ROOT . '/' . $params->get($path, 'images'));
      $app = JFactory::getApplication('site');
      $componentParams = $app->getParams('com_media');
      $mediaRoot = $componentParams->get('file_path', 'images');
      $baseDir = JPATH_ROOT . '/' . $mediaRoot;
      $path = realpath(JPATH_ROOT . (substr($originalImagePath, 0, 1) === '/' ? $originalImagePath : '/'. $originalImagePath));

      if (strpos($path, $baseDir) !== 0 || strpos($path, $baseDir) === false) {
        continue;
      }

      $originalImagePathInfo = pathinfo($originalImagePath);

      // Bail out if no images supported
      if (!in_array(mb_strtolower($originalImagePathInfo['extension']), $validExt) || !file_exists(JPATH_ROOT . '/' . $originalImagePath)) {
        continue;
      }

      if (!@mkdir(JPATH_ROOT . '/media/cached-resp-images/' . $originalImagePathInfo['dirname'], 0755, true) && !is_dir(JPATH_ROOT . '/media/cached-resp-images/' . $originalImagePathInfo['dirname']) ) {
        throw new RuntimeException('There was a file permissions problem in folder \'media\'');
      }

      // If responsive image doesn't exist we will create it
      if (!file_exists(JPATH_ROOT . '/media/cached-resp-images/' . $originalImagePathInfo['dirname'] . '/' .$originalImagePathInfo['filename'] . $sizeSplit . $validSize[0] . '.' . $originalImagePathInfo['extension'])) {
        self::createImages($validSize, $originalImagePathInfo['dirname'], $originalImagePathInfo['filename'], $originalImagePathInfo['extension'], $quality, $scaleUp, $scaleMethod, $sizeSplit);
      }

      // If responsive image exists use it
      if (file_exists(JPATH_ROOT . '/media/cached-resp-images/' . $originalImagePathInfo['dirname'] . '/' .$originalImagePathInfo['filename'] . $sizeSplit . $validSize[0] . '.' . $originalImagePathInfo['extension'])) {
        $images->item($i)->setAttribute('src', 'media/cached-resp-images/' . $originalImagePathInfo['dirname'] . '/' . $originalImagePathInfo['filename'] . $sizeSplit . $validSize[0] . '.' . $originalImagePathInfo['extension']);

        $srcset = self::buildSrcset($validSize, $originalImagePathInfo['dirname'], $originalImagePathInfo['filename'], $originalImagePathInfo['extension'], $sizeSplit);
        $srcset_webp = self::buildSrcset($validSize, $originalImagePathInfo['dirname'], $originalImagePathInfo['filename'], 'webp', $sizeSplit);
        $size = self::buildSize($validSize, $originalImagePathInfo['dirname'], $originalImagePathInfo['filename'], $originalImagePathInfo['extension'], $sizeSplit);

        $images->item($i)->setAttribute('srcset', $srcset);
        $images->item($i)->setAttribute('class', 'c-image-responsive');
        $images->item($i)->setAttribute('sizes', $size);
        $new_source_clone->setAttribute('srcset', $srcset_webp);
      }
    }

    $lTmpTxt = trim($dom->saveHTML($body[0]));		
    $row->text = substr($lTmpTxt,6,strlen($lTmpTxt)-13);
    
  }

  /**
   * Build the srcset string
   *
   * @param  array  $breakpoints the different breakpoints
   * @param  string $dirname the directory name
   * @param  string $filename the file name
   * @param  string $extension the file extension 
   * @param  string $sizeSplitt the identifier for the create file
   *
   * @return string
   *
   * @since  1.0
   */
  private static function buildSrcset(array $breakpoints = array(320, 480, 768, 992, 1200, 1600, 1920), $dirname, $filename, $extension, $sizeSplitt) {
    $srcset = '';

    if (!empty($breakpoints)) {
      for ($i = 0, $l = count($breakpoints); $i < $l; $i++) {
        $filesrc = 'media/cached-resp-images/' . $dirname . '/' . $filename . $sizeSplitt . $breakpoints[$i] . '.' . $extension;
        if (file_exists(JPATH_ROOT . '/' . $filesrc)) {
          $srcset .= $filesrc . ' ' . $breakpoints[$i] . 'w, ';
        }
      }
    }

    return rtrim($srcset, ', ');
  }

  /**
   * Build the srcset string
   *
   * @param  array  $breakpoints the different breakpoints
   * @param  string $dirname the directory name
   * @param  string $filename the file name
   * @param  string $extension the file extension 
   * @param  string $sizeSplitt the identifier for the create file
   *
   * @return string
   *
   * @since  1.0
   */
  private static function buildSize(array $breakpoints = array(320, 480, 768, 992, 1200, 1600, 1920), $dirname, $filename, $extension, $sizeSplitt) {
    $size = '';

    if (!empty($breakpoints)) {
      for ($i = 0, $l = count($breakpoints); $i < $l; $i++) {
        $filesrc = 'media/cached-resp-images/' . $dirname . '/' . $filename . $sizeSplitt . $breakpoints[$i] . '.' . $extension;
        if (file_exists(JPATH_ROOT . '/' . $filesrc)) {
          $size .= ' ' . $breakpoints[$i] . 'w, ';
        }
      }
    }

    return rtrim($size, ', ');
  }

  /**
   * Create the thumbs
   *
   * @param  array  $breakpoints the different breakpoints
   * @param  string $dirname the directory name
   * @param  string $filename the file name
   * @param  string $extension the file extension 
   * @param  string $sizeSplitt the identifier for the create file
   *
   * @return void
   *
   * @since  1.0
   */
  private static function createImages(array $breakpoints = array(320, 480, 768, 992, 1200, 1600, 1920), $dirname, $filename, $extension, $quality, $scaleUp, $scaleMethod, $sizeSplitt) {
    if (!empty($breakpoints)) {
      // Create the images with width = breakpoint
      $image = new JImage;

      // Load the file
      $image->loadFile(JPATH_ROOT . '/' . $dirname . '/' .$filename . '.' . $extension);

      // Get the properties
      $properties = $image->getImageFileProperties(JPATH_ROOT . '/' . $dirname . '/' . $filename . '.' . $extension);

      // Skip if the width is less or equal to the required
      if ($properties->width <= (int) $breakpoints[0]) {
        return;
      }

      // Get the image type
      $type = str_replace('image/','', mb_strtolower($properties->mime));

      switch ($type) {
        case 'jpeg':
        case 'jpg':
          $imageType = 'IMAGETYPE_JPEG';
          $webp_resource = imagecreatefromjpeg(JPATH_ROOT . '/' . $dirname . '/' . $filename . '.' . $extension);
          break;
        case 'png':
          $imageType = 'IMAGETYPE_PNG';
          $webp_resource = imagecreatefrompng(JPATH_ROOT . '/' . $dirname . '/' . $filename . '.' . $extension);
          break;
        default:
          $imageType = '';
          break;
      }

      if (!in_array($imageType, array('IMAGETYPE_JPEG', 'IMAGETYPE_PNG'))) {
        return;
      }

      $aspectRatio = $properties->width / $properties->height;

      for ($i = 0, $l = count($breakpoints); $i < $l; $i++) {
        if ($scaleUp or ($properties->width >= (int) $breakpoints[$i])) {
          // Resize the image
          $newImg = $image->resize((int) $breakpoints[$i]/*width*/, (int) $breakpoints[$i] / $aspectRatio /*height*/, true/*createNew*/, $scaleMethod/*scaleMethod*/);

          $newImg->toFile(
            JPATH_ROOT . '/media/cached-resp-images/' . $dirname . '/' . $filename . $sizeSplitt . (int) $breakpoints[$i] . '.' . $extension,
            $imageType,
            array('quality' => (int) $quality)
          );

          if ( $imageType === 'IMAGETYPE_JPEG') {
            $webp_resource = imagecreatefromjpeg(JPATH_ROOT . '/media/cached-resp-images/' . $dirname . '/' . $filename . $sizeSplitt . (int) $breakpoints[$i] . '.' . $extension);
          } elseif ($imageType === 'IMAGETYPE_PNG') {
              $webp_resource = imagecreatefrompng(JPATH_ROOT . '/media/cached-resp-images/' . $dirname . '/' . $filename . $sizeSplitt . (int) $breakpoints[$i] . '.' . $extension);
          }

					if (function_exists('imagewebp') && isset($webp_resource) && $webp_resource !== false) {
            imagewebp($webp_resource, JPATH_ROOT . '/media/cached-resp-images/' . $dirname . '/' . $filename . $sizeSplitt . (int) $breakpoints[$i] . '.' . 'webp', (int) $quality);
          }
      }
    }
  }
}
