<?php

namespace Dummy\Catalog;

use \Bitrix\Main\Loader;
use \Bitrix\Main\Type\DateTime;
use \Bitrix\Main\Data\Cache;


class Provider
{
    private $client = null;
    private string $url = "";

    private array $params = [];

    public function __construct($url = "", $params = "")
    {
        $this->setUrl($url);
        $this->setQuery($params);
        $this->client = new \Bitrix\Main\Web\HttpClient();
        $this->client->setHeader('Content-Type', 'application/json', true);
    }

    public function getResponse()
    {
        return json_decode(
            $this->client->get($this->url . '?' . http_build_query($this->params)),
            true
        );
    }

    public function setUrl($url): void
    {
        $this->url = $url;
    }

    public function setQuery($params): void
    {
        $this->params = $params;
    }
}

class Dummy
{
    const url_products = "https://dummyjson.com/products";
    const url_search = "https://dummyjson.com/products/search";


    public static function getProducts($params = [])
    {
        $provider = new Provider(self::url_products, $params);

        return $provider->getResponse();
    }

    public static function getProductByID($id)
    {
        $productId = intval($id);
        if ($productId > 0) {
            $provider = new Provider(self::url_products . '/' . $productId, []);

            return $provider->getResponse();
        }

        return false;
    }

    public static function searchProduct($query = '')
    {
        $provider = new Provider(self::url_search . '/' . $query, []);

        return $provider->getResponse();
    }
}

class DummyCatalogManager extends Dummy
{
    private $catalog_id = 0;
    private $price_id = 0;
    function __construct($catalog_code)
    {
        $this->catalog_id = $this->getIblockIdByCode($catalog_code);
        $this->price_id = $this->getBasePriceId();
        $this->getBasePriceId();
    }

    public function getBasePriceId():int
    {
        Loader::includeModule('catalog');
        $rsGroup = \Bitrix\Catalog\GroupTable::getList([
            'select' => [
                'ID'
            ],
            'filter' => [
                'BASE' => 'Y'
            ]
        ])->fetch();
       return $rsGroup['ID'];
    }
    public function getIblockIdByCode($code):int
    {
        $id = false;
        $cacheDir = 'getIblockIdByCode';
        $cache = Cache::createInstance();

        if ($cache->initCache(86400, $code, $cacheDir)) {
            $id = $cache->getVars();
        } elseif ($cache->startDataCache()) {
            Loader::includeModule('iblock');
            $res = \CIBlock::GetList(
                array(),
                array('ACTIVE' => 'Y', 'CODE' => $code),
                true
            );
            if ($ar_res = $res->Fetch()) {
                $id = $ar_res['ID'];
            }
            $cache->endDataCache($id);
        }

        return $id;
    }

    public static function getCode($name):string
    {
        return \Cutil::translit((string)$name, "ru", ["replace_space" => "_", "replace_other" => "_"]);
    }

    private function getSections($asTree = false): array
    {
        $rsSection = \Bitrix\Iblock\SectionTable::getList(array(
            'order' => array('LEFT_MARGIN' => 'ASC'),
            'filter' => array(
                'IBLOCK_ID' => $this->catalog_id,
                'ACTIVE' => 'Y',
                'GLOBAL_ACTIVE' => 'Y',
            ),
            'select' => array(
                'ID',
                'CODE',
                'NAME',
                'IBLOCK_ID',
                'IBLOCK_SECTION_ID',
                'DEPTH_LEVEL',
                'TIMESTAMP_X',
            ),
        ))->fetchAll();

        if (!$asTree) {
            return $rsSection;
        }
        //пересобираем массив разделов в дерево
        $sectionList = array_combine(array_column($rsSection, 'ID'), $rsSection);

        foreach ($sectionList as &$section) {
            if ($section['DEPTH_LEVEL'] == 1) {
                $sectionList[$section['ID']]['CHILDS'] = [];
                continue;
            }
            $sectionList[$section['IBLOCK_SECTION_ID']]['CHILDS'][$section['ID']] = $section;
            unset($sectionList[$section['ID']]);
        }
        unset($rsSection);

        return $sectionList;
    }

    private function addSections($arRows): void
    {
        if (!empty($arRows)) {
            $res = \Bitrix\Iblock\SectionTable::addMulti($arRows, true);

            if (!$res->isSuccess()) {
                //TODO catch exeption
                throw new \Exception($res->getErrorMessages());

            }
        }
    }

    private function createSections($products): void
    {
        if (empty($products)) {
            return;
        }

        $arProductsSections = [];
        $arProductsSubSections = [];

        foreach ($products as $product) {
            $arProductsSections[] = $product['category'];
            $code = $this->getCode($product['brand']);
            $arProductsSubSections[$code] = [
                'name' => $product['brand'],
                'parent' => $product['category'],
                'code' => $code,
            ];
        }
        $arProductsSections = array_unique($arProductsSections);

        $arSections = $this->getSections(false);
        $arRows = [];
        foreach ($arProductsSections as $section) {
            if (!in_array($section, array_column($arSections, 'CODE'))) {
                $arRows[] = [
                    'CODE' => $this->getCode($section),
                    'IBLOCK_ID' => $this->catalog_id,
                    'NAME' => $section,
                    'ACTIVE' => 'Y',
                    'TIMESTAMP_X' => new DateTime(),
                    'DEPTH_LEVEL' => 1,
                ];
            }
        }

        $this->addSections($arRows);

        if(!empty($arRows)) {
            $arSections = $this->getSections(true);
        }
        $arSections = array_combine(array_column($arSections, 'CODE'), $arSections);
        $arRows = [];

        foreach ($arProductsSubSections as $section) {
            if (!in_array($section['code'], array_column($arSections[$section['parent']]['CHILDS'], 'CODE'))) {
                $arRows[$section['name']] = [
                    'CODE' => $section['code'],
                    'IBLOCK_ID' => $this->catalog_id,
                    'IBLOCK_SECTION_ID' => $arSections[$section['parent']]['ID'],
                    'NAME' => $section['name'],
                    'ACTIVE' => 'Y',
                    'TIMESTAMP_X' => new DateTime(),
                    'DEPTH_LEVEL' => 2,
                ];
            }
        }

        $this->addSections($arRows);
    }

    public function uploadProducts($params = [])
    {
        Loader::includeModule('catalog');
        Loader::includeModule('iblock');

        //получаем список продуктов
        $response = self::getProducts($params);
        $arDummyProductsList = $response['products'];

        //наполняем каталог разделами на основе полученных продуктов
        $this->createSections($arDummyProductsList);

        //получаем обновлённый список разделов (чтобы была возможность привязать продукт к разделу при создании)
        $arSections = $this->getSections();
        $arSections = array_combine(array_column($arSections, 'CODE'), $arSections);

        $el = new \CIBlockElement();
        foreach ($arDummyProductsList as $product) {
            $arSection = $arSections[$this->getCode($product['brand'])];
            $image = \CFile::MakeFileArray($product['thumbnail']);
            $arFields = [
                "ACTIVE" => 'Y',
                'NAME' => $product['title'],
                'CODE' => $this->getCode($product['title']),
                'IBLOCK_ID' => $this->catalog_id,
                'IBLOCK_SECTION_ID' => $arSection['ID'],
                'PREVIEW_TEXT' => $product['description'],
                'DETAIL_TEXT' => $product['description'],
                'PREVIEW_PICTURE' => $image,
                'DETAIL_PICTURE' => $image,
                'PROPERTY_VALUES' => [
                    'DISCOUNT_PERCENTAGE' => $product['discountPercentage'],
                ],
            ];

            //создаём элемент каталога
            $res = $el->Add($arFields);

            //добавляем свойста продукта
            if ($res) {
                $arFields = [
                    'ID' => $res,
                    'AVAILABLE' => intval($product["stock"]) > 0 ? "Y" : "N",
                    'QUANTITY' => $product["stock"],
                    'CAN_BUY_ZERO' => 'N',
                    'SUBSCRIBE' => "Y",

                    'WEIGHT' => $product['weight'],
                    'WIDTH' => $product['dimensions']['width'],
                    'LENGTH' => $product['dimensions']['height'],
                    'HEIGHT' => $product['dimensions']['depth'],
                ];
                \Bitrix\Catalog\Model\Product::add($arFields);


                \Bitrix\Catalog\Model\Price::add(
                    [
                        "PRODUCT_ID" => $res,
                        "CATALOG_GROUP_ID" => $this->price_id,
                        "PRICE" => $product['price'],
                        "CURRENCY" => "RUB"
                    ],
                );
            } else {
                throw new \Exception("Не удалось добавить товар");
            }
        }

    }
}


