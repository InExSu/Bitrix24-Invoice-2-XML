<?php

class Application
{
    protected $xml;

    public function run(): bool
    {
        $logger = new Logger ("log.txt");
        // потом включи
        // $logger->write(print_r($_REQUEST, true));

        // Потом отключи
        $logger->clean();

        $arRequest = $_REQUEST;

        $this->checkAuth($arRequest);

        if (empty($arRequest["event"]) || empty($arRequest["data"]))
            throw new Exception("Отсутствуют данные для обработки");

        $idInvoice = (int)$arRequest['data']['FIELDS']['ID'];
        // if (empty($arRequest["event"]) || $arRequest["event"] != "ONCRMINVOICEADD" || $idInvoice <= 0)
        if (empty($arRequest["event"]) || $idInvoice <= 0)
            return false;

        $arInvoice = $this->getInvoiceData($idInvoice);
        $idDeal = (int)$arInvoice["UF_DEAL_ID"];

        if ($idInvoice <= 0)
            throw new Exception("В счете не указана сделка");

        $arDeal = $this->getDealData($idDeal);

        $arDealReadyForXml = $this->prepareDealForXml($arDeal, $arInvoice);

        $arProducts = $this->getDealProducts($idInvoice);

        $arProductsReadyForXml = $this->prepareProductsForXml($arProducts, $arInvoice["PRODUCT_ROWS"]);
        //$arProductsReadyForXml = $this->prepareProductsForXml($arInvoice["PRODUCT_ROWS"]);

        $this->xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\" ?><сведения></сведения>");
        $this->writeToFile($this->xml, $arDealReadyForXml, "сделка", "");
        $this->writeToFile($this->xml, $arProductsReadyForXml, "товары", "товар");
        return $this->doFinalActions($idInvoice);
    }

    protected function checkAuth(array $arRequest = []): bool
    {

        if (empty($arRequest) || empty($arRequest["auth"]))
            throw new Exception("Не передан массив авторизации");

        if ($arRequest["auth"]["application_token"] != APP_TOKEN)
            throw new Exception("Передан некорректный токен авторизации");

        return true;
    }

    protected function getInvoiceData(int $idInvoice): array
    {
        $arCrmRequest = CRest::call(
            'crm.invoice.get',
            [
                'id' => $idInvoice
            ]
        );

        if (empty($arCrmRequest["result"])) {
            throw new Exception("Не найден счет в срм c id " . $idInvoice);
        }

        $arInvoiceUserfields = CRest::call('crm.invoice.userfield.list');
        if (!empty($arInvoiceUserfields["result"])) {

            foreach ($arInvoiceUserfields["result"] as $arField) {
                if (array_key_exists($arField["FIELD_NAME"], $arCrmRequest["result"]) && $arField["USER_TYPE_ID"] === "enumeration") {
                    if (isset($arCrmRequest["result"][$arField["FIELD_NAME"]]))
                        foreach ($arField["LIST"] as $arValue) {
                            if ($arValue["ID"] == $arCrmRequest["result"][$arField["FIELD_NAME"]])
                                $arCrmRequest["result"][$arField["FIELD_NAME"]] = $arValue["VALUE"];
                        }
                }
            }
        }

        return $arCrmRequest["result"];
    }

    protected function getDealData(int $idDeal): array
    {
        $arDeal = CRest::call('crm.deal.get', ['id' => $idDeal]);

        if (empty($arDeal["result"]))
            throw new Exception("Не найдена сделка c id " . $idDeal);

        $arDealUserfields = CRest::call('crm.deal.userfield.list');

        if (!empty($arDealUserfields["result"])) {

            foreach ($arDealUserfields["result"] as $arField) {
                if (array_key_exists($arField["FIELD_NAME"], $arDeal["result"]) && $arField["USER_TYPE_ID"] === "enumeration") {
                    if (isset($arDeal["result"][$arField["FIELD_NAME"]]))
                        foreach ($arField["LIST"] as $arValue) {
                            if ($arValue["ID"] == $arDeal["result"][$arField["FIELD_NAME"]])
                                $arDeal["result"][$arField["FIELD_NAME"]] = $arValue["VALUE"];
                        }
                }
            }
        }

        $this->dealArrayAddCompanyName($arDeal);
        $this->dealArrayAddCompanyPhone($arDeal);

        // TODO потом удали
        file_put_contents('arDeal[result]', print_r($arDeal["result"], true));

        return $arDeal["result"];
    }

    public function dealArrayAddCompanyName(&$arDeal): void
    { // вернуть из сделки наименование компании покупателя

        $company_Id = $arDeal['result']['COMPANY_ID'];

        if ($company_Id !== '') {
            $arCompany = CRest::call(
                'crm.company.get',
                [
                    'id' => $company_Id
                ]
            );
            $title = $arCompany['result']['TITLE'];
            $arDeal['result']['покупательКлиентКомпания'] = $title;
        }
    }

    public function dealArrayAddCompanyPhone(&$arDeal): void
    { // вернуть из сделки наименование компании покупателя

        $company_Id = $arDeal['result']['COMPANY_ID'];

        if ($company_Id !== '') {
            $arCompany = CRest::call(
                'crm.company.get',
                [
                    'id' => $company_Id
                ]
            );

            // TODO потом удали
            file_put_contents('arCompany', print_r($arCompany, true));

            if ($arCompany) {
                $phone = $arCompany['result']['PHONE'][0]['VALUE'];
                $arDeal['result']['компанияТелефон'] = $phone;
            }
        }
    }

    protected function prepareDealForXml(array $arDeal = [], array $arInvoice = []): array
    {
        if (empty($arDeal))
            return [];

        // 2022-05-17 отсечь геокоординаты из
        $addressDost = $arInvoice[CRMFields::DELIVERY_Address];
        $addressDost = $this->stringCutGeo($addressDost, "|");

        $arDealClean = [
            "идСделки"           => $arDeal["ID"],
            "комментарий"        => $arDeal["COMMENTS"],
            "грузоотправитель"   => $this->getRequisites((int)$arInvoice[CRMFields::SHIPPER]),
            "номерДоговора"      => $arInvoice[CRMFields::CONTRACT] ?? "СИЗОД",   //$this->getListValue((int)$arDeal[CRMFields::CONTRACT], (int)CRMFields::CONTRACT_IBLOCK_ID),
            // "потребитель" => $this->getRequisites((int)$arInvoice[CRMFields::CONSUMER]),
            "потребитель"        => $this->getRequisites((int)$arDeal["COMPANY_ID"]),
            "грузополучатель"    => $this->getRequisites((int)$arInvoice[CRMFields::CONSIGNEE]),
            // "адресДоставки" => $addressDost, // $arInvoice[CRMFields::DELIVERY_Address],
            "адресДоставкиДляТК" => $addressDost, // $arInvoice[CRMFields::DELIVERY_Address],
            "видДоставки"        => $arInvoice[CRMFields::DELIVERY_TYPE],
            // "контактноеЛицо"           => $this->getContactPhone((int)$arInvoice[CRMFields::DELIVERY_CONTACT]),
            // Кузнецова, Инна Владимировна 2022-05-26 14:06
            // В тег КонтактноеЛицо взять информацию из поля "Контактное лицо грузополучателя" (строка) из сделки
            "контактноеЛицо"     => $arDeal["UF_CRM_1650885619"],
            "плательщикДляТК"    => $this->getRequisites((int)$arInvoice[CRMFields::PAYER]),
            // Кузнецова отключить 2022-02-26
            //            "видДоговора"        => "с покупателем",
            //            "типЦен"             => "отпускная (руб)",
            //            "основнаяСтатья"     => "СИЗОД",
            //            "подразделение"      => "Департамент СИЗОД и ФПП",
            //            "cклад"              => "Склад ГП СИЗОД",
            //            // Добавил 2021-10-29
            //            "условияОплаты"      => $arInvoice[CRMFields::TERMS_OF_PAYMENT],
            //            "срокПоставки"       => $arInvoice[CRMFields::TIME_DELIVERY],
            // Добавил 2022-03-09
            "отгрузкаТранзитом"  => $arInvoice[CRMFields::SHIPMENT_TRANSIT],
            // Добавил 2022-20-23. Попов, отключил 2022-20-26
            //            "покупательКлиентКомпания" => $arDeal['покупательКлиентКомпания'],
        ];

        return $arDealClean;
    }

    public function stringCutGeo($hayStack, string $needle)
    { //  адресу обрезать геокоординаты

        if (strpos($hayStack, $needle) !== false) {
            $hayStack = strtok($hayStack, $needle);
        }
        return $hayStack;
    } //Потребитель

//    public const CONSIGNEE = "UF_CRM_5F9BF01B2DD12"; //грузополучатель

    public function getRequisites(int $idCompany): array
    {
        $arRequisites = [
            "компанияНаименование"  => "-",
            "компанияИНН"           => "-",
            "компанияЮрАдрес"       => "-",
            "компанияАдресДоставки" => "-",
            "компанияКПП"           => "-",
            "компанияТелефон"       => "-",
        ];

        if ($idCompany <= 0) {
            return $arRequisites;
        }

        $arCrmRequisiteList = CRest::call(
            'crm.requisite.list',
            [
                "filter" => [
                    "ENTITY_ID" => $idCompany,
                ],
                "order"  => [
                    "ID" => "desc"
                ]
            ]
        );

        if (!empty($arCrmRequisiteList["result"])) {

            $arCrmAddressList = CRest::call(
                'crm.address.list',
                [
                    "filter" => [
                        "ENTITY_ID"      => $arCrmRequisiteList["result"][0]["ID"],
                        "ENTITY_TYPE_ID" => 8,
                    ],
                ]
            );

            $sAddress = "";
            $sDeliveryAdres = '';

            if (!empty($arCrmAddressList["result"])) {
                foreach ($arCrmAddressList["result"] as $arOneAddress) {
                    $arCrmAdr = [];
                    if ($arOneAddress["POSTAL_CODE"]) $arCrmAdr[] = $arOneAddress["POSTAL_CODE"];
                    if ($arOneAddress["COUNTRY"]) $arCrmAdr[] = $arOneAddress["COUNTRY"];
                    if ($arOneAddress["PROVINCE"]) $arCrmAdr[] = $arOneAddress["PROVINCE"];
                    if ($arOneAddress["CITY"]) $arCrmAdr[] = $arOneAddress["CITY"];
                    if ($arOneAddress["ADDRESS_1"]) $arCrmAdr[] = $arOneAddress["ADDRESS_1"];
                    if ($arOneAddress["ADDRESS_2"]) $arCrmAdr[] = $arOneAddress["ADDRESS_2"];

//                    if ($arOneAddress["TYPE_ID"] == 6) // юр адрес
//                    {
//                        $sAddress = implode(", ", $arCrmAdr);
//                    }
//                    if ($arOneAddress["TYPE_ID"] == 11) //  адрес доставки
//                    {
//                        $sDeliveryAdres = implode(", ", $arCrmAdr);
//                    }
// Кузнецова, Инна Владимировна написала 19 мая 13:30:
// <грузополучатель>
// <компанияАдресДоставки/> необходимо забрать юр.адрес из реквизитов компании
                    // Попов: делаю адреса одинаковыми
                    $sAddress = implode(", ", $arCrmAdr);
                    $sDeliveryAdres = $sAddress;
                }
            }

            $arRequisites = [
//                "компанияНаименование"  => $arCrmRequisiteList["result"][0]["RQ_COMPANY_FULL_NAME"] ? $arCrmRequisiteList["result"][0]["RQ_COMPANY_FULL_NAME"] : $arCrmRequisiteList["result"][0]["NAME"],
"компанияНаименование"  => $arCrmRequisiteList["result"][0]["RQ_COMPANY_FULL_NAME"] ?: $arCrmRequisiteList["result"][0]["NAME"],
"компанияИНН"           => $arCrmRequisiteList["result"][0]["RQ_INN"] ?: "-",
"компанияЮрАдрес"       => $sAddress,
"компанияАдресДоставки" => $sDeliveryAdres,
"компанияКПП"           => $arCrmRequisiteList["result"][0]["RQ_KPP"] ?: "-",
"компанияТелефон"       => $this->companyPhone($idCompany, 0),
            ];
        }
        return $arRequisites;
    }

        public function companyPhone($company_Id, $index = 0): string
    { // вернуть номер телефона

        $phone = '-';

        if ($company_Id !== '') {
            $arCompany = CRest::call(
                'crm.company.get',
                [
                    'id' => $company_Id
                ]
            );

            // TODO потом удали
            file_put_contents('arCompany', print_r($arCompany, true));

            if ($arCompany) {
                $phone = $arCompany['result']['PHONE'][$index]['VALUE'];
            }
        }
        return $phone;
    } //плательщик для ТК

//    public const DELIVERY_Address = "UF_CRM_1610367809"; // адрес доставки

    protected
    function getDealProducts(int $idInvoice): array
    {
        $arCrmRequest = CRest::call(
            'crm.productrow.list',
            [
                'filter' => [
                    'OWNER_ID'   => $idInvoice,
                    'OWNER_TYPE' => 'I'
                ],
                'select' => [
                    '*'
                ]
            ]
        );

        if (!empty($arCrmRequest["error_description"]) && array_key_exists('error', $arCrmRequest)) {
            throw new Exception("Ошибка запроса товаров из сделки: " . $arCrmRequest["error_description"]);
        }


        if (!empty($arCrmRequest["result"])) {
            $arProductIds = [];
//            $arCatalogProducts = [];

            foreach ($arCrmRequest["result"] as $arProduct) {

                $arProductIds[] = $arProduct["PRODUCT_ID"];
            }
            if (!empty($arProductIds)) {

                $arProperties = $this->getProductProperties();
                $arProductsFull = $this->getProductsFull($arProductIds, $arProperties);


                foreach ($arCrmRequest["result"] as &$arProduct) {
                    if (array_key_exists($arProduct["PRODUCT_ID"], $arProductsFull))
                        $arProduct["PROPS"] = $arProductsFull[$arProduct["PRODUCT_ID"]];
                }

            }

        }


        return $arCrmRequest["result"];
    } // Адрес доставки для ТК

protected
    function getProductProperties(): array
    {
        $arProperties = [];

        $arCrmPropertiesRequest = CRest::call('crm.product.property.list', []);
        if (isset($arCrmPropertiesRequest["result"]))
            foreach ($arCrmPropertiesRequest["result"] as $arProperty)
                $arProperties[$arProperty["ID"]] = $arProperty;

        return $arProperties;
    }

        protected
    function getProductsFull(array $arProductIds = [], array $arProps = []): array
    {
        $arProducts = [];

        if (empty($arProductIds))
            return [];

        $arCrmAdditionalRequest = CRest::call(
            'crm.product.list',
            [
                'filter' => [
                    'ID' => $arProductIds,
                ],
                'select' => [
                    '*', 'PROPERTY_*'
                ]
            ]
        );

        if (isset($arCrmAdditionalRequest["result"]))
            foreach ($arCrmAdditionalRequest["result"] as $arProduct) {
                foreach ($arProduct as $sFieldKey => &$arField) {

                    if (substr_count($sFieldKey, "PROPERTY_") !== 1)
                        continue;

                    $idProperty = (int)str_replace("PROPERTY_", "", $sFieldKey);

                    if ($idProperty > 0 && array_key_exists($idProperty, $arProps)) {
                        $newProperty = [
                            "ID"   => $arProps[$idProperty]["ID"],
                            "NAME" => $arProps[$idProperty]["NAME"],
                        ];

                        if (is_array($arField))
                            if ($arProps[$idProperty]["MULTIPLE"] === "Y") {
                                $arPropertyValues = [];
                                foreach ($arField as $arOneValue) {
                                    if ($arProps[$idProperty]["PROPERTY_TYPE"] === "L")
                                        $arPropertyValues[] = $arProps[$idProperty]["VALUES"][$arOneValue["value"]]["VALUE"];
                                    else
                                        $arPropertyValues[] = $arOneValue["value"];
                                }
                                $newProperty["VALUE"] = $arPropertyValues;
                            } else
                                if ($arProps[$idProperty]["PROPERTY_TYPE"] === "L")
                                    $newProperty["VALUE"] = $arProps[$idProperty]["VALUES"][$arField["value"]]["VALUE"];
                                else
                                    $newProperty["VALUE"] = $arField["value"];
                        else
                            $newProperty["VALUE"] = $arField;

                        $arField = $newProperty;
                    }
                }
                $arProducts[$arProduct["ID"]] = $arProduct;
            }
        return $arProducts;
    } // договор поставки

    protected function prepareProductsForXml(array $arDirtyProducts = [], array $arInvoiceItems = []): array
    {
        if (empty($arDirtyProducts))
            return [];

        $arSortedItems = [];
        foreach ($arInvoiceItems as $arItem)
            $arSortedItems[$arItem['ID']] = $arItem;

        $arClearProducts = [];
        foreach ($arDirtyProducts as $arOneProduct) {
            $bVatIncluded = ($arSortedItems[$arOneProduct['ID']]["VAT_INCLUDED"] === "Y");


            if ($bVatIncluded) {
                $dFinalPrice = $arSortedItems[$arOneProduct["ID"]]["PRICE"];
                $dPriceWithoutTax = $dFinalPrice / (1 + $arSortedItems[$arOneProduct["ID"]]["VAT_RATE"]);
                $dTaxSize = $arSortedItems[$arOneProduct["ID"]]["PRICE"] - $dPriceWithoutTax;

//                $dPriceWithoutTaxAndWithoutDiscount = ($dPriceWithoutTax + $arOneProduct["DISCOUNT_SUM"]) / 9 * 10;

            } else {
                $dFinalPrice = $arSortedItems[$arOneProduct["ID"]]["PRICE"];
//                $dPriceWithoutTax = $dFinalPrice / (1 + $arSortedItems[$arOneProduct["ID"]]["VAT_RATE"]);
                $dTaxSize = $dFinalPrice * $arSortedItems[$arOneProduct["ID"]]["VAT_RATE"];

//                $dPriceWithoutTaxAndWithoutDiscount = $dPriceWithoutTax + $arOneProduct["DISCOUNT_SUM"];

            }

            $priceExclusiveWithDiscount = $arSortedItems[$arOneProduct["ID"]]["PRICE"];


            $arClearProducts[] = [
                "наименование"   => $arOneProduct['PRODUCT_NAME'], //
                "количество"     => $arOneProduct['QUANTITY'], //
                "производитель"  => implode($arOneProduct['PROPS']['PROPERTY_485']["VALUE"], ";"),
                "код"            => ($arOneProduct['PROPS']['PROPERTY_539']["VALUE"] ?: ""),  //$arOneProduct['PRODUCT_ID'],
                "цена"           => ($bVatIncluded) ? $priceExclusiveWithDiscount + $arOneProduct['DISCOUNT_SUM'] * 1.2 : $priceExclusiveWithDiscount + $arOneProduct['DISCOUNT_SUM'],
                "суммаБезСкидки" => ($priceExclusiveWithDiscount + $arOneProduct['DISCOUNT_SUM']) * $arOneProduct['QUANTITY'],
                "суммаСкидки"    => $arOneProduct['DISCOUNT_SUM'] * $arOneProduct['QUANTITY'],
                "процентCкидки"  => $arOneProduct['DISCOUNT_RATE'],
                "сумма"          => $priceExclusiveWithDiscount * $arOneProduct['QUANTITY'],
                "едИзмерения"    => $arOneProduct['MEASURE_NAME'],
                "ставкаНДС"      => $arOneProduct['TAX_RATE'],
                "суммаНДС"       => round($dTaxSize * $arOneProduct['QUANTITY'], 2),
                "всегоСНДС"      => $arOneProduct['PRICE'] * $arOneProduct['QUANTITY'],
            ];

        }
        return $arClearProducts;
    } // тип доставки

    protected
    function writeToFile($xml, array $arDataForXml, string $sectionName, string $elementName = "")
    {
        $node = $xml->addChild($sectionName);
        $this->arrayToXml($arDataForXml, $node, $elementName);

    } // Контактное лицо грузополучателя

//public const DELIVERY_CONTACT = "UF_CRM_5F9BF01AAC772"; // контактное лицо при доставке

    public function arrayToXml(array $array, &$xml, $elementName = "")
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (!is_numeric($key)) {
                    $subnode = $xml->addChild("$key");
                    $this->arrayToXml($value, $subnode);
                } else {
// Попов: переделал 2022-05-25
//                    if ($elementName != "")
//                        $subnode = $xml->addChild("$elementName");
//                    $this->arrayToXml($value, $subnode);
                    if ($elementName != "") {
                        $subnode = $xml->addChild("$elementName");
                        $this->arrayToXml($value, $subnode);
                    }
                }
            } else {
                $xml->addChild("$key", "$value");
            }
        }
    } // инфоблок контрактов

// Добавил 2021-10-29

protected function doFinalActions(int $idDeal): bool
    {
        file_put_contents("files/$idDeal.xml", $this->xml->asXML());

        $ftpManager = new FTPManager(FTP_HOST, FTP_PORT, FTP_LOGIN, FTP_PASSWORD);
        if ($ftpManager->connect()) {
            $ftpManager->upload("$idDeal.xml", __DIR__ . "/../../files/", FTP_REMOTE_DIR);
            $ftpManager->disconnect();
            return true;
        } else
            return false;
    }

    public function log($variable, $comment): void
    { // вывести в лог имя переменной и значение

        $logger = new Logger ("log.txt");
        $logger->write(print_r($variable, true));
        $logger->write($comment . PHP_EOL);
    }

    public
    function getContactPhone(int $idContact): array
    {
        $sContact = [
            "фамилия"  => "",
            "имя"      => "",
            "отчество" => "",
            "телефон"  => "",
            "email"    => ""
        ];
//        $sPhone = "";
        if ($idContact > 0) {
            $arContactFields = CRest::call(
                'crm.contact.get',
                [
                    "id" => $idContact
                ]
            );

            if (!empty($arContactFields["result"])) {
                if (!empty($arContactFields["result"]["PHONE"])) //                    $sPhone = $arContactFields["result"]["PHONE"][0]["VALUE"];

                {
                    $sContact = [
                        "фамилия"  => $arContactFields["result"]["LAST_NAME"] ?? "",
                        "имя"      => $arContactFields["result"]["NAME"] ?? "",
                        "отчество" => $arContactFields["result"]["SECOND_NAME"] ?? "",
                        "телефон"  => $arContactFields["result"]["PHONE"][0]["VALUE"] ?? "",
                        "email"    => $arContactFields["result"]["EMAIL"][0]["VALUE"] ?? "",
                    ];
                }
            }
        }
        return $sContact;
    }

//    public function getListValue(int $elementId, int $iblockId)
//    {
//        $arDealEnumFields = CRest::call(
//            'lists.element.get',
//            [
//                "FILTER"         =>
//                    [
//                        'ID' => $elementId
//                    ],
//                "IBLOCK_ID"      => $iblockId,
//                "IBLOCK_TYPE_ID" => "lists"
//            ]
//        );
//
//        return !empty($arDealEnumFields["result"]) ? $arDealEnumFields["result"][0]["NAME"] : "-";
//    }
}

class CRMFields
{
    public const SHIPPER = "UF_CRM_1610359373";
    public const CONSUMER = "UF_CRM_5F9BF01B658A8";
    public const CONSIGNEE = "UF_CRM_613AF9D4D685D";
    public const PAYER = "UF_CRM_5F9BF01B7389A";
    public const DELIVERY_Address = "UF_CRM_60D96D778591E";
    public const CONTRACT = "UF_CRM_1608889603";
    public const DELIVERY_TYPE = "UF_CRM_5F9BF01B5504B";
    public const DELIVERY_CONTACT = "UF_CRM_60D96D7707C69";
//    public const CONTRACT_IBLOCK_ID = 37;
    public const TERMS_OF_PAYMENT = 'UF_CRM_613AF9D4CF1D7';
    public const TIME_DELIVERY = 'UF_CRM_616D4E97213B7';
    public const SHIPMENT_TRANSIT = 'UF_CRM_6144A3A9DEE1D';
    public $addressWithGeo = "UF_CRM_60D96D778591E";
}

