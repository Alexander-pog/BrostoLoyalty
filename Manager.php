<?php

namespace Brosto\Loyalty\Integrations\OneC;

use Brosto\Loyalty\Integrations\ManagerInterface;
use Bitrix\Main\Config\Option;
use GuzzleHttp\Exception\RequestException;
use Brosto\Loyalty\Exceptions\{OneCRequestException, BasketException};
use Brosto\Loyalty\Order;
use Bitrix\Sale;
use Bitrix\Main\Context; 
use Bitrix\Main\Server;

class Manager implements ManagerInterface
{
    /**
     * $address
     *
     * @var string
     */
    protected $address = 'http://87.25.178.115:16116/main/hs/ExchangeAPI/1.0';

    /**
     * $login
     *
     * @var undefined
     */
    protected $login;

    /**
     * $password
     *
     * @var undefined
     */
    protected $password;

    public function __construct()
    {
        $this->login = Option::get('main', 'one_c_api_login', false);
        $this->password = Option::get('main', 'one_c_api_password', false);

        if ($this->login === false || $this->password === false) {
            throw new \InvalidArgumentException('API login or password is not set');
        }
    }

    /**
     * requestBalance
     *
     * @param mixed $id
     * @return float
     */
    public function requestBalance($id): float
    {
        $client = new \GuzzleHttp\Client();
        try {
            $response = $client->request(
                'POST', 
                $this->address.'/КоличествоНакопленныхБаллов',
                ['auth' => [$this->login, $this->password], 'body' => $id]
            );

            $parser = new \SimpleXMLElement($response->getBody()->getContents());

            $balance = (float) $parser[0];
            if ($balance < 0) {
                $balance = 0;
            }
        } catch (RequestException $e) {
            $balance = 0;
        } catch (\Exception $e) {
            $balance = 0;
        }

        return $balance;
    }

    /**
     * requestId
     *
     * @param mixed $id
     * @return string
     */
    public function requestId(string $cardNumber, string $phone): string
    {
        $client = new \GuzzleHttp\Client();
        try {
            $body = json_encode(['Карта' => $cardNumber, 'Телефон' => $phone]);

            $response = $client->request(
                'POST', 
                $this->address.'/Авторизация',
                ['auth' => [$this->login, $this->password], 'body' => $body]
            );

            $parser = new \SimpleXMLElement($response->getBody()->getContents());

            $id = (string) $parser[0];
            if (empty($id)) {
                $id = '';
            }
        } catch (RequestException $e) {
            $id = '';
        } catch (\Exception $e) {
            $id = '';
        }

        return $id;
    }

    /**
     * requestId
     *
     * @param mixed $id
     * @return string
     */
    public function getIdbyData(string $firstName, string $lastName, string $phone, string $email): string
    {
        $logger = \Brosto\Logger\LoggerFactory::getLogger();
        $logger->log('Отправляем в 1с такие параметры: '.json_encode(['Наименование' => $firstName.' '.$lastName, 'Телефон' => $phone, 'Почта' => $email]));

        $client = new \GuzzleHttp\Client();
        try {
            $body = json_encode(['Наименование' => $firstName.' '.$lastName, 'Телефон' => $phone, 'Почта' => $email]);
            $response = $client->request(
                'POST', 
                $this->address.'/Авторизация',
                ['auth' => [$this->login, $this->password], 'body' => $body]
            );

            $id = strip_tags($response->getBody()->getContents());
            if (empty($id)) {
                $id = '';
            }
        } catch (RequestException $e) {
            $logger = \Brosto\Logger\LoggerFactory::getLogger();
            $logger->log($e->getMessage());
            $id = '';
        } catch (\Exception $e) {
            $logger = \Brosto\Logger\LoggerFactory::getLogger();
            $logger->log($e->getMessage());
            $id = '';
        }

        return $id;
    }

    /**
     * registerOrder.
     *
     * @access	public
     * @param	order	$order	
     * @return	array
     */
    public function registerOrder(Order $order): array
    {
        $client = new \GuzzleHttp\Client();
        try {
            $body = json_encode(OrderAdapter::getFormattedData($order));

            \Brosto\Logger\LoggerFactory::getLogger()->log($body);

            $response = $client->request(
                'POST', 
                $this->address.'/СформироватьЗаказ',
                ['auth' => [$this->login, $this->password], 'body' => $body]
            );

            $xml = simplexml_load_string($response->getBody()->getContents(), 'SimpleXMLElement', LIBXML_NOCDATA);
            $fields = json_decode(json_encode($xml), TRUE);
            $data = [
                'orderNum' => $fields['Property'][1]['Value'],
                'orderId' => $fields['Property'][2]['Value'],
            ];
        } catch (RequestException $e) {
            $exception = new OneCRequestException('Unable to calculate data');
            $exception->setResponse($e->getResponse()->getBody()->getContents());
            throw $exception;
        }

        return $data;
    }

    /**
     * markOrderPaid.
     *
     * @access	public
     * @param	\Bitrix\Sale\Order	$bxOrder	
     * @return	void
     */
    public function markOrderPaid(\Bitrix\Sale\Order $bxOrder)
    {
        $client = new \GuzzleHttp\Client();
        $oneCOrderId = $bxOrder->getPropertyCollection()->getItemByOrderPropertyId(7)->getValue();
        if (empty($oneCOrderId)) {
            throw new BasketException('У заказа отсутствует номер из 1С');
        }

        $price = (float) $bxOrder->getPrice() - (float) $bxOrder->getField('DISCOUNT_VALUE');
        if ($price <= 0) {
            throw new BasketException('Некорректно указана цена');
        }

        $body = json_encode([
            "ИдентификаторЗаказа" => $oneCOrderId,
            "Бонусы" => $bxOrder->getField('DISCOUNT_VALUE'),
            "Карта" => $price,
        ]);

        try {
            $response = $client->request(
                'POST', 
                $this->address.'/ОплатитьЗаказ',
                ['auth' => [$this->login, $this->password], 'body' => $body]
            );
        } catch (RequestException $e) {
            $exception = new OneCRequestException('Не удалось отправить данные по оплате заказа '.$oneCOrderId);
            $exception->setResponse($e->getResponse()->getBody()->getContents());
            throw $exception;
        }
    }



    /**
     * orderСancellation - передача в 1с данных об отмене заказа.
     *
     * @access  public
     * @param   \Bitrix\Sale\Order  $orderID    
     * @return  string
     */
    public function orderСancellation($orderID)
    {
        $client = new \GuzzleHttp\Client();
        $bxOrder = Sale\Order::load($orderID);
        $oneCOrderId = $bxOrder->getPropertyCollection()->getItemByOrderPropertyId(7)->getValue();
        if (empty($oneCOrderId)) {
            throw new BasketException('У заказа отсутствует номер из 1С');
        }
        $body = json_encode([
            "ИдентификаторЗаказа" => $oneCOrderId,
        ]);

        try {
            $response = $client->request(
                'POST', 
                $this->address.'/ОтменитьЗаказ',
                ['auth' => [$this->login, $this->password], 'body' => $body]
            );

           $data = $response->getStatusCode();

        } catch (RequestException $e) {
           $exception = new OneCRequestException('Не удалось отправить данные об отмене заказа '.$oneCOrderId);
           $exception->setResponse($e->getResponse()->getBody()->getContents());
           throw $exception;
        }
      return $data;
    }




    /**
     * getPersonalRecommendations.
     *
     *  В теле запроса может содержать следущие ключи:
     *  ИдентификаторТовара - строка - идентификатор товара, если клиент открыл карточку
     *  ИдентификаторГруппы - строка - идентификатор категории, если клиент открыл категорию
     *  Корзина - массив - массив id-шников товаров, находящихся в корзине пользователя
     *  Количество - число - количество ожидаемых предложений. Это нужно для верстки, если ты хочешь получить конкретное количество товаров. Можно не указывать, тогда вернется столько, сколько решит рекомендательная система
     *  
     *  эти ключи нужны, чтобы определить пользователя:
     *  cookies - куки
     *  user_xml_id - логин клиента
     *  ip - ip
     *  
     *  Все ключи необязательные​​​​​​​
     *  
     *  В ответ будет приходить словарь
     *  {
     *      'result' - true/false. Признак того, что выданы рекомендации. Если значение=false, то остальных ключей не будет ​​​​​​​
     *      'id_pp' - id шник выданного персонального предложения
     *      'data' - массив id-шников товаров
     *  }​​​​​​​
     *
     * @access	public
     * @param	$request	
     * @return	PersonalRecomendation
     */
    public function getPersonalRecommendations($request, $params = []): PersonalRecomendation
    {
        $client = new \GuzzleHttp\Client();

        $cookies = $params["COOKIES"] ?? $request->getCookieList()->getValues();

        if (! empty($params["IP_ADDRESS"])) {
            $ip = $params["IP_ADDRESS"];
        } else {
            $server = Context::getCurrent()->getServer();
            $ip = $server->get("HTTP_X_FORWARDED_FOR");
        }
        
        if (! empty($params["USER"])) {
            $user = $params["USER"];
            $basket = Sale\Basket::loadItemsForFUser(Sale\Fuser::getId(), Context::getCurrent()->getSite());
        } else {
            $rsUser = \CUser::GetByLogin($cookies["LOGIN"]);
            $user = $rsUser->GetNext();
            $basket = Sale\Basket::loadItemsForFUser($cookies["SALE_UID"], Context::getCurrent()->getSite());
        }

        $basketItems = $basket->getBasketItems();
        $goods = [];

        foreach ($basketItems as $item) {
            $goods[] = ["ИдентификаторТовара" => $item->getField('PRODUCT_XML_ID')];
        }

        $oneCId = $user["UF_ONEC_ID"];

        $data = [
			"Количество" => $params['QUANTITY'] ?? 8,
            "cookies" => $cookies,
            "user_xml_id" => $oneCId,
            "ip" => $ip,
        ];

		if (! empty($goods)) {
			$data["Корзина"] = $goods;
		}

        if (isset($params["PAGE"]) && isset($params["PAGE_ID"])) { // PAGE_ID - идентификатор категории или товара
            if($params["PAGE"] == 'section') {
                $data["ИдентификаторГруппы"] = $params["PAGE_ID"];
            } else if($params["PAGE"] == 'element') {
                $data["ИдентификаторТовара"] = $params["PAGE_ID"];
            }
        }

        $body = json_encode($data);

        try {
			$response = $client->request(
                'POST', 
                $this->address.'/ПредложениеКлиенту',
                ['auth' => [$this->login, $this->password], 'body' => $body]
            );

            $xml = simplexml_load_string($response->getBody()->getContents(), 'SimpleXMLElement', LIBXML_NOCDATA);
            $fields = json_decode(json_encode($xml), TRUE);

            $recomendationId = (string) $fields['Property'][0]['Value'] ?? '';
            $productIds = $fields['Property'][1]['Value']['Value'] ?? [];

            return new PersonalRecomendation($recomendationId, $productIds);

        } catch (RequestException $e) {
            $logger = \Brosto\Logger\LoggerFactory::getLogger();
            $logger->log(
                'Ошибка при попытке запросить персональные рекомендации. Отправлены были такие параметры: ' .
                $body ."\n Response was: " . 
                json_encode($fields ?? '-') .
                "Exception message: " . $e->getMessage()
            );

            return new PersonalRecomendation('', []);
        }
    }
}
