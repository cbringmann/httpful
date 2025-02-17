<?php

declare(strict_types = 1);

/**
 * Mime Type: application/xml
 *
 * @author Zack Douglas <zack@zackerydouglas.info>
 * @author Nathan Good <me@nategood.com>
 */

namespace Httpful\Handlers;

use DOMDocument;
use Exception;
use ReflectionObject;
use XMLWriter;

class XmlHandler extends MimeHandlerAdapter
{
    /** @var string $namespace xml namespace to use with simple_load_string */
    private string $namespace;

    /** @var int $libxml_opts see http://www.php.net/manual/en/libxml.constants.php */
    private int $libxml_opts;

    /** @param array $conf sets configuration options */
    public function __construct(array $conf = array())
    {
        $this->namespace = $conf['namespace'] ?? '';
        $this->libxml_opts = $conf['libxml_opts'] ?? 0;
    }

    /** @throws \Exception if unable to parse */
    public function parse(string $body): mixed
    {
        $body = $this->stripBom($body);

        if (empty($body)) {
            return null;
        }

        $parsed = simplexml_load_string($body, null, $this->libxml_opts, $this->namespace);

        if ($parsed === false) {
            throw new Exception("Unable to parse response as XML");
        }

        return $parsed;
    }

    /** @throws \Exception if unable to serialize */
    public function serialize(mixed $payload): string
    {
        [$_, $dom] = $this->_future_serializeAsXml($payload);

        return $dom->saveXml();
    }

    /** @author Ted Zellers */
    public function serialize_clean(mixed $payload): string
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'ISO-8859-1');
        $this->serialize_node($xml, $payload);

        return $xml->outputMemory(true);
    }

    /**
     * @param mixed $node to serialize
     * @author Ted Zellers
     */
    public function serialize_node(XMLWriter &$xmlw, mixed $node): void
    {
        if (!is_array($node)) {
            $xmlw->text($node);
        } else {
            foreach ($node as $k => $v) {
                $xmlw->startElement($k);
                    $this->serialize_node($xmlw, $v);
                $xmlw->endElement();
            }
        }
    }

    /** @author Zack Douglas <zack@zackerydouglas.info> */
    private function _future_serializeAsXml($value, $node = null, $dom = null)
    {
        if (!$dom) {
            $dom = new DOMDocument();
        }

        if (!$node) {
            if (!is_object($value)) {
                $node = $dom->createElement('response');
                $dom->appendChild($node);
            } else {
                $node = $dom;
            }
        }

        if (is_object($value)) {
            $objNode = $dom->createElement(get_class($value));
            $node->appendChild($objNode);
            $this->_future_serializeObjectAsXml($value, $objNode, $dom);
        } elseif (is_array($value)) {
            $arrNode = $dom->createElement('array');
            $node->appendChild($arrNode);
            $this->_future_serializeArrayAsXml($value, $arrNode, $dom);
        } elseif (is_bool($value)) {
            $node->appendChild($dom->createTextNode($value ? 'TRUE' : 'FALSE'));
        } else {
            $node->appendChild($dom->createTextNode($value));
        }

        return array($node, $dom);
    }

    /** @author Zack Douglas <zack@zackerydouglas.info> */
    private function _future_serializeArrayAsXml($value, &$parent, &$dom)
    {
        foreach ($value as $k => &$v) {
            $n = $k;

            if (is_numeric($k)) {
                $n = "child-{$n}";
            }

            $el = $dom->createElement($n);
            $parent->appendChild($el);
            $this->_future_serializeAsXml($v, $el, $dom);
        }

        return array($parent, $dom);
    }

    /** @author Zack Douglas <zack@zackerydouglas.info> */
    private function _future_serializeObjectAsXml($value, &$parent, &$dom)
    {
        $refl = new ReflectionObject($value);

        foreach ($refl->getProperties() as $pr) {
            if (!$pr->isPrivate()) {
                $el = $dom->createElement($pr->getName());
                $parent->appendChild($el);
                $this->_future_serializeAsXml($pr->getValue($value), $el, $dom);
            }
        }

        return array($parent, $dom);
    }
}
