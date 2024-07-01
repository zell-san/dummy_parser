<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
require($_SERVER['DOCUMENT_ROOT'] . '/dummy/parser.php');

use Dummy\Catalog\DummyCatalogManager;
//1. Используя классовый подход написать парсер по API https://dummyjson.com/docs/products
//1.1 Товары должны выгружать в инфоблок каталог
//1.2 Поля category использовать как название раздела, brand - подраздела
//1.3 Поле thumbnail - Детальная картинка и картинка анонса
//1.4 Поле discountPercentage - выгружать в отдельное свойство
//1.5 Название, код полей, метод поиска элементов/разделов - на Ваше усмотрение.
//2. Написать обработчик событий, который начисляет "бонусы" на лицевой счет пользователя
//1.1 Бонусы должны начисляться при оплате заказа (отмену оплаты можно не учитывать)
//1.2 Бонусы рассчитываются по принципу "Цена товара * количество товара * discountPercentage / 100)
//1.3 При начислении бонусов должно уходить письмо на почту
// ADMIN_EMAIL (почту брать из константы, в тестовом задании поставить любую) с информацией "Пользователю #LOGIN# начислено #BONUS_SUM# бонусов"


//Пример вызова парсера
$product = new DummyCatalogManager('catalog');
$result = $product->uploadProducts(['limit'=> 5]);



//обработчик, предполагается, что его нужно перенести к другим обработчикам в проекте
//ну или в init.php

\Bitrix\Main\EventManager::getInstance()->addEventHandler(
    'sale',
    'OnSaleOrderPaid',
    'OnSaleOrderPaidHandler'
);

function OnSaleOrderPaidHandler ($order_id, $status) {
    if (CModule::IncludeModule("sale") && $status == "Y"){
        $order = \Bitrix\Sale\Order::load($order_id);
        $basket = $order->getBasket();
        $userId = $order->getUserId();
        $bonuses = 0;
        foreach ($basket as $basketItem) {
            $arElements = \CIBlockElement::GetList(
                ["ID"=>"ASC"],
                [
                    'ACTIVE' => 'Y',
                    'ID' => $basketItem->getProductId(),
                ],
                false,
                false,
                [
                    'ID',
                    "NAME",
                    "PROPERTY_DISCOUNT_PERCENTAGE",
                ]
            )->Fetch();

            $discountPercentage = (float)$arElements['PROPERTY_DISCOUNT_PERCENTAGE_VALUE'] /100;
            $price =  $basketItem->getPrice();
            $quantity = $basketItem->getQuantity();
            $bonuses += round($price * $quantity * $discountPercentage, 0) ;

            echo $bonuses;
        }
        if ($ar = \CSaleUserAccount::GetByUserID($userId, 'RUB')) {
            $arFields = [
                "CURRENT_BUDGET" => $ar["CURRENT_BUDGET"] + $bonuses
            ];
            CSaleUserAccount::Update($ar["ID"], $arFields);
        }
        else {
            $arFields = [
                "USER_ID" =>$userId,
                "CURRENCY" => "RUB",
                "CURRENT_BUDGET" => $bonuses
            ];
            CSaleUserAccount::Add($arFields);
        }

    }
}





