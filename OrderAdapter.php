<?php

namespace Brosto\Loyalty\Integrations\OneC;

use Brosto\Loyalty\Order;

class OrderAdapter
{
    /**
     * Formats user's cart in order for the data to be processed by external API
     *
     * @param Order $order
     * @return array
     */
    public static function getFormattedData(Order $order): array
    {
        $accountId = empty($order->account) ? '' : $order->account->getId();
        $data = [
            "Магазин" => "106a44f4-fe94-446a-a29c-fb8b05a329f8",
            "Клиент" => $accountId,
            "Корзина" => static::getFormattedItems($order),
            "СпособДоставки" => $order->getDeliveryName(),
            "АдресДоставки" => $order->getDeliveryAddress(),
            "Скидка" => $order->getDiscount(),
			"ДРХПВЗ" => $order->getPropertyValueById(10),
			"Комментарий" =>  $order->getField("USER_DESCRIPTION"),
        ];

        return $data;
    }

    /**
     * getFormattedItems
     *
     * @param Order $order
     * @return array
     */
    public function getFormattedItems(Order $order): array
    {
        $items = $order->basket->getBasketItems();
        if (empty($items)) {
            throw new \Exception('Empty Basket');
        }

        $formatted = [];
        foreach ($items as $item) {
            $formatted[] = [
                'ИдентификаторТовара' => $item->getField('PRODUCT_XML_ID'),
                'Количество' => $item->getField('QUANTITY'),
                'Скидка' => $item->getField('DISCOUNT_PRICE'),
            ];
        }

        return $formatted;
    }
}
