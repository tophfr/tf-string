<?php

/**
 * @author toph <toph@toph.fr>
 *
 * TfLib is the legal property of its developers, whose names
 * may be too numerous to list here. Please refer to the AUTHORS file
 * distributed with this source distribution.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */
class TfTruncate
{

    protected $tagsIngoreContent = array('script', 'style', 'embed', 'object', 'noscript', 'video', 'audio', 'canvas');
    protected $tagsNotToStrip = array('span', 'i', 'b', 'u', 'strong', 'em', 'a');

    private $cplt = '...';
    private $encoding = null;

    protected $error = null;
    protected $errors = null;

    const ERROR_OPENING_XML = 1;
    const ERROR_PARSING_XML = 2;

    public function setCplt($cplt)
    {
        $this->cplt = $cplt;
    }

    public function getCplt()
    {
        return $this->cplt;
    }

    public function setEncoding($encoding)
    {
        $this->encoding = $encoding;
    }

    public function getEncoding()
    {
        return $this->encoding;
    }

    public function getError()
    {
        return $this->error;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function truncateText($text, $lmax, $unhtmlize = false)
    {

        $encoding = is_null($this->encoding) ? mb_detect_encoding($text) : $this->encoding;
        if (!$encoding) {
            $encoding = 'UTF-8';
        }

        if ($unhtmlize) {
            foreach ($this->tagsIngoreContent as $tag) {
                $qtag = preg_quote($tag, '#');
                $regex = sprintf('#<%s[^>]*>.*</%s>#i', $qtag, $qtag);
                $text = preg_replace($regex, '', $text);
            }
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES, $encoding);
            $text = preg_replace('#\s+#', ' ', $text);
        }
        $text = trim($text);
        $l = $lmax - mb_strlen($this->cplt, $encoding);
        if ($l >= 0 && mb_strlen($text, $encoding) > $lmax) {
            // do truncate + 1 to check what next char is
            $sub = mb_substr($text, 0, $l + 1, $encoding);
            if (preg_match('#^(.*\S)(\s*)$#ui', $sub, $match)) {
                if ($match[2]) {
                    // next char is a space => ok
                    $sub = $match[1];
                } else {
                    // next char is no space => drop the last word (if not the only word)
                    $sub = preg_replace('/\s+\S+$/ui', '', $sub);
                }
            }
            return $sub . $this->cplt;
        }
        return $text;
    }

    public function truncateHTML($html, $lmax, $stripTags = false)
    {
        $dontCount = 0;
        $count = 0;
        $pile = array();
        $stripContent = 0;

        $encoding = is_null($this->encoding) ? mb_detect_encoding($html) : $this->encoding;
        if (!$encoding) $encoding = 'UTF-8';

        $l = $lmax - mb_strlen($this->cplt, $encoding);
        $xml = sprintf('<root>%s</root>', str_replace('&', '&amp;', $html));
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $this->error = null;
        $this->errors = null;
        $reader = new XMLReader();
        if (!$reader->XML($xml, $encoding, LIBXML_NOERROR | LIBXML_NOWARNING)) {
            $this->error = self::ERROR_OPENING_XML;
            return $this->truncateText($html, $lmax, true);
        } else {
            $out = '';
            while (@$reader->read()) {
                switch ($reader->nodeType) {
                    case XMLReader::ELEMENT:
                        if ($reader->depth == 0) continue; // <root>
                        $stripTag = $stripTags && !in_array(strtolower($reader->name), $this->tagsNotToStrip);
                        // copy start tag if not out of bound
                        $elementName = $reader->name;
                        $isEmptyElement = $reader->isEmptyElement;
                        if (!$stripTag) {
                            $out .= sprintf('<%s', $reader->name);
                            if ($reader->hasAttributes) {
                                while ($reader->moveToNextAttribute()) {
                                    $out .= sprintf(' %s="%s"', $reader->name, $reader->value);
                                }
                            }
                        }
                        if ($isEmptyElement) {
                            if (!$stripTag) $out .= ' />';
                        } else {
                            if ($this->ignoreContentOfTag($elementName)) {
                                $dontCount++;
                                if ($stripTag) $stripContent++;
                            }
                            if (!$stripTag) {
                                $out .= '>';
                                array_push($pile, $elementName);
                            }
                        }
                        break;

                    case XMLReader::END_ELEMENT:
                        if ($reader->depth == 0) continue; // </root>
                        $stripTag = $stripTags && !in_array(strtolower($reader->name), $this->tagsNotToStrip);
                        // copy end tag if need to close or not out of bound
                        if ($this->ignoreContentOfTag($reader->name)) {
                            $dontCount--;
                            if ($stripTag) $stripContent--;
                        }
                        if (!$stripTag) {
                            $out .= sprintf('</%s>', $reader->name);
                            array_pop($pile);
                        }
                        break;

                    case XMLReader::TEXT:
                        // count()
                        if ($dontCount == 0) {
                            $text = str_replace('&amp;', '&', $reader->value);
                            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                            $text = trim($text);
                            $text = preg_replace('#\s+#', ' ', $text);
                            if ($count) $count++; // on compte un espace en plus sauf pour le premier mot
                            $count += $c = mb_strlen($text, 'UTF-8');
                            if ($count > $l) {
                                $text = mb_convert_encoding($text, $encoding, 'UTF-8');
                                return $out . $this->truncateText($text, $c + $lmax - $count) . (empty($pile) ? '' : '</' . implode('></', $pile) . '>');
                            }
                        }

                    default:
                        // just copy if not out of bound
                        if ($stripContent == 0) {
                            $out .= mb_convert_encoding($reader->value, $encoding, 'UTF-8');
                        }
                }
            }
            $this->errors = libxml_get_errors();
            if (!empty($this->errors)) {
                libxml_clear_errors();
                $this->error = self::ERROR_PARSING_XML;
                return $this->truncateText($html, $lmax, true);
            }
            // no error and limit not reached => no need to truncate
            return $html;
        }
    }

    public function ignoreContentOfTag($tagName)
    {
        return in_array(strtolower($tagName), $this->tagsIngoreContent);
    }
}
