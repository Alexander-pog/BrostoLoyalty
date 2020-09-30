<?php

namespace Brosto\Loyalty\Integrations\OneC;

use Brosto\Loyalty\AbstractBasketItem;

class BasketItem extends AbstractBasketItem
{
    public function __construct(array $data)
    {
        foreach ($data as $item) {
            switch ($item['@attributes']['name']) {
                case 'ИдентификаторТовара':
                    $this->xmlId = $item['Value'];
                    break;
                case 'Номенклатура':
                    $this->name = $item['Value'];
                    break;
                case 'Количество':
                    $this->quantity = (int) $item['Value'];
                    break;
                case 'Цена':
                    $this->price = (float) $item['Value'];
                    break;
                case 'Сумма':
                    $this->totalSum = (float) $item['Value'];
                    break;
                case 'Бонусы':
                    $this->bonuses = (float) $item['Value'];
                    break;
            }
        }
    }

    public function getCalculatedPrice()
    {
        return $this->totalSum - $this->bonuses;
    }
}
