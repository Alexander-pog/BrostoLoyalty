<?php

namespace Brosto\Loyalty\Integrations\OneC;

use Bitrix\Sale\Basket as BxBasket;

class Basket implements \ArrayAccess, \Countable
{
    protected $orderId;

    protected $items = [];

    public function __construct(string $orderId)
    {
        $this->orderId = (int) $orderId;
    }

    public function updateBxBasket(BxBasket $bxBasket): BxBasket
    {
        foreach ($bxBasket->getBasketItems() as $item) {
            // Здесь не нужно пропускать цикл, а нужно добавлять этот товар без скидки
            if (!$this->offsetExists($item->getField('PRODUCT_XML_ID'))) {
                // echo '<p>Key '.$item->getField('PRODUCT_XML_ID').' does not exist in the collection</p>';
                continue;
            }

            $priceResult = $item->setPrice($this[$item->getField('PRODUCT_XML_ID')]->getCalculatedPrice(), true);
            $item->setField('DISCOUNT_PRICE', $this[$item->getField('PRODUCT_XML_ID')]->getBonuses());
        }

        return $bxBasket;
    }

    public function add($key, $value)
    {
        $this->items[$key] = $value;
    }

    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetExists($offset)
    {
        return isset($this->items[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->items[$offset]);
    }

    public function offsetGet($offset)
    {
        return isset($this->items[$offset]) ? $this->items[$offset] : null;
    }

    public function count(): int
    {
        return count($this->items);
    }
}
