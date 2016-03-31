<?php
/**
 * This file is part of escpos-php: PHP receipt printer library for use with
 * ESC/POS-compatible thermal and impact printers.
 *
 * Copyright (c) 2014-16 Michael Billington < michael.billington@gmail.com >,
 * incorporating modifications by others. See CONTRIBUTORS.md for a full list.
 *
 * This software is distributed under the terms of the MIT license. See LICENSE.md
 * for details.
 */

namespace Mike42\Escpos;

use Exception;
use Imagick;
use Mike42\Escpos\EscposImage;

/**
 * Implementation of EscposImage using the Imagick PHP plugin.
 */
class ImagickEscposImage extends EscposImage
{
    
    /**
     * @param string $filename
     *  Path to load image from disk. Use 'null' to get an empty image.
     * @param string $allow_optimisations
     *  True to use library-specific speed optimisations.
     * @throws Exception
     *  Where image loading failed (eg. unsupported format, no such file, permission error).
     */
    public function __construct($filename = null, $allow_optimisations = true)
    {
        if ($filename === null) {
            return;
        }

        $im = new \Imagick();
        try {
            // Throws an ImagickException if the format is not supported or file is not found
            $im -> readImage($filename);
        } catch (ImagickException $e) {
            // Wrap in normal exception, so that classes which call this do not themselves require imagick as a dependency.
            throw new Exception($e);
        }
        /* Flatten by doing a composite over white, in case of transparency */
        $this -> readImageFromImagick($im);
        return;
        // TODO implement optimised version of these methods. Rendering is too slow.
    }
    
    /**
     * Load actual image pixels from Imagick object
     *
     * @param Imagick $im Image to load from
     */
    public function readImageFromImagick(\Imagick $im)
    {
        /* Strip transparency */
        $flat = new \Imagick();
        $flat -> newImage($im -> getimagewidth(), $im -> getimageheight(), "white");
        $flat -> compositeimage($im, \Imagick::COMPOSITE_OVER, 0, 0);
        $im = $flat;
        /* Threshold */
        $im -> setImageType(\Imagick::IMGTYPE_TRUECOLOR); // Remove transparency (good for PDF's)
        $max = $im->getQuantumRange();
        $max = $max["quantumRangeLong"];
        $im -> thresholdImage(0.5 * $max);
        /* Make a string of 1's and 0's */
        $geometry = $im -> getimagegeometry();
        $this -> imgHeight = $im -> getimageheight();
        $this -> imgWidth = $im -> getimagewidth();
        $this -> imgData = str_repeat("\0", $this -> imgHeight * $this -> imgWidth);
    
        for ($y = 0; $y < $this -> imgHeight; $y++) {
            for ($x = 0; $x < $this -> imgWidth; $x++) {
                /* Faster to average channels, blend alpha and negate the image here than via filters (tested!) */
                $cols = $im -> getImagePixelColor($x, $y);
                $cols = $cols -> getcolor();
                $greyness = (int)(($cols['r'] + $cols['g'] + $cols['b']) / 3) >> 7;  // 1 for white, 0 for black
                $this -> imgData[$y * $this -> imgWidth + $x] = (1 - $greyness); // 1 for black, 0 for white
            }
        }
    }
    
    /**
     * Load a PDF for use on the printer
     *
     * @param string $pdfFile The file to load
     * @param string $pageWidth The width, in pixels, of the printer's output. The first page of the PDF will be scaled to approximately fit in this area.
     * @throws Exception Where Imagick is not loaded, or where a missing file or invalid page number is requested.
     * @return multitype:EscposImage Array of images, retrieved from the PDF file.
     */
    public static function loadPdf($pdfFile, $pageWidth = 550)
    {
        if (!extension_loaded('imagick')) {
            throw new Exception(__FUNCTION__ . " requires imagick extension.");
        }
        /*
    	 * Load first page at very low density (resolution), to figure out what
    	 * density to use to achieve $pageWidth
    	 */
        try {
            $image = new \Imagick();
            $testRes = 2; // Test resolution
            $image -> setresolution($testRes, $testRes);
            /* Load document just to measure geometry */
            $image -> readimage($pdfFile);
            $geo = $image -> getimagegeometry();
            $image -> destroy();
            $width = $geo['width'];
            $newRes = $pageWidth / $width * $testRes;
            /* Load entire document in */
            $image -> setresolution($newRes, $newRes);
            $image -> readImage($pdfFile);
            $pages = $image -> getNumberImages();
            /* Convert images to Escpos objects */
            $ret = array();
            for ($i = 0; $i < $pages; $i++) {
                $image -> setIteratorIndex($i);
                $ep = new ImagickEscposImage();
                $ep -> readImageFromImagick($image);
                $ret[] = $ep;
            }
            return $ret;
        } catch (\ImagickException $e) {
            // Wrap in normal exception, so that classes which call this do not themselves require imagick as a dependency.
            throw new Exception($e);
        }
    }
}
