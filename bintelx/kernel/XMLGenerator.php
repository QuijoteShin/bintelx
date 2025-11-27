<?php

namespace bX;


/**
 *
 * Podría describir la clase XMLGenerator como una clase que utiliza la API DOM de PHP para generar documentos XML a
 * partir de un arreglo de datos. La clase utiliza una función recursiva para construir la estructura del XML a partir
 * de los datos de entrada, lo que le permite manejar múltiples niveles de nodos y atributos sin perder la referencia
 * de los nodos. Además, la clase utiliza el nombre de los nodos para generar el XML en lugar de utilizar nombres
 * estáticos, lo que la hace más flexible y escalable. En resumen, la clase XMLGenerator es una herramienta útil
 * para generar documentos XML a partir de datos estructurados en PHP.
 *
 * USAGE
 *
 * $data = array(
 * 'personas' => array(
 * '@attributes' => array(
 * 'version' => '1.0',
 * 'encoding' => 'UTF-8'
 * ),
 * 'persona' => array(
 * array(
 * '@attributes' => array(
 * 'id' => 1,
 * 'tipo' => 'natural'
 * ),
 * 'nombre' => 'Juan Pérez',
 * 'edad' => 35
 * ),
 * array(
 * '@attributes' => array(
 * 'id' => 2,
 * 'tipo' => 'juridica'
 * ),
 * 'nombre' => 'Empresa S.A.',
 * 'direccion' => 'Av. Principal 123',
 * 'telefono' => '555-1234'
 * )
 * )
 * )
 * );
 *
 * $p = new XMLGenerator('1.0', 'utf-8');
 * $p->arrayToXml($data);
 * echo $p->saveXML();
 */
class XMLGenerator extends \DOMDocument
{

  public function arrayToXml($data, ?\DOMElement $domElement = null)
  {

    $domElement = is_null($domElement) ? $this : $domElement;

    if (is_array($data)) {
      foreach ($data as $index => $dataElement) {

        if (is_int($index)) {
          if ($index == 0) {
            $node = $domElement;
          } else {
            $node = $this->createElement($domElement->tagName);
            $domElement->parentNode->appendChild($node);
          }
        } else {
          if ($index == '@attributes') {
            foreach ($data['@attributes'] as $key => $value) {
              $attr = $this->createAttribute($key);
              $attr->value = $value;
              $domElement->appendChild($attr);
            }
          } else {
            $node = $this->createElement($index);
            $domElement->appendChild($node);
          }
        }
        if (isset($node)) {
          $this->arrayToXml($dataElement, $node);
        }
      }
    } else {
      $domElement->appendChild($this->createTextNode($data));
    }
  }
}