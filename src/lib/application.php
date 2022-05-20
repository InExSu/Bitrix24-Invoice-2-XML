<?php

class Application
{
    protected $xml;

    public function run(): bool
    {
// потом включи
//        $logger = new Logger ("log.txt");
//        $logger->write(print_r($_REQUEST, true));

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
                if (array_key_exists($arField["FIELD_NAME"], $arCrmRequest["result"]) && $arField["USER_TYPE_ID"] == "enumeration") {
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
            throw new Exception("Не найдена сделка в срм c id " . $idDeal);

        // потом удали
//        $logger = new Logger ("log.txt");
//        $logger->write(print_r($arDeal, true));
//        $logger->write('$arDeal' . PHP_EOL);
//

        $arDealUserfields = CRest::call('crm.deal.userfield.list');

        if (!empty($arDealUserfields["result"])) {

            foreach ($arDealUserfields["result"] as $arField) {
                if (array_key_exists($arField["FIELD_NAME"], $arDeal["result"]) && $arField["USER_TYPE_ID"] == "enumeration") {
                    if (isset($arDeal["result"][$arField["FIELD_NAME"]]))
                        foreach ($arField["LIST"] as $arValue) {
                            if ($arValue["ID"] == $arDeal["result"][$arField["FIELD_NAME"]])
                                $arDeal["result"][$arField["FIELD_NAME"]] = $arValue["VALUE"];
                        }
                }
            }
        }

        $this->dealArrayAddCompanyName($arDeal);
        return $arDeal["result"];
    }

    public function dealArrayAddCompanyName($arDeal)
    {

        $company_Id = $arDeal['result']['COMPANY_ID'];
        if ($company_Id !== '') {
// взять имя компании
            $id = $arDeal['result']['ID'];
// CRest::call('crm.deal.get', ['id' => $idDeal]);
            $arCompany = CRest::call('crm.company.get', $id);
            $title = $arCompany['TITLE'];
//            $logger->write(print_r($arCompany, true));
//            $logger->write(print_r($arCompany . PHP_EOL, true));
//            $logger->write(print_r($title, true));
//            $logger->write(print_r('$title' . PHP_EOL, true));
//        }
        }
    } // Отгрузка транзитом


    protected function prepareDealForXml(array $arDeal = [], array $arInvoice = []): array
    {
        if (empty($arDeal))
            return [];

        // 2022-05-17 отсечь из геокоординаты из
        $addressDost = $arInvoice[CRMFields::DELIVERY_ADRESS];
        $addressDost = $this->stringCutGeo($addressDost, "|");

        $arDealClean = [
            "идСделки"           => $arDeal["ID"],
            "грузоотправитель"   => $this->getRequisites((int)$arInvoice[CRMFields::SHIPPER]),
            "номерДоговора"      => $arInvoice[CRMFields::CONTRACT] ?? "СИЗОД",   //$this->getListValue((int)$arDeal[CRMFields::CONTRACT], (int)CRMFields::CONTRACT_IBLOCK_ID),
            "потребитель"        => $this->getRequisites((int)$arInvoice[CRMFields::CONSUMER]),
            "грузополучатель"    => $this->getRequisites((int)$arInvoice[CRMFields::CONSIGNEE]),
            // "адресДоставки" => $addressDost, // $arInvoice[CRMFields::DELIVERY_ADRESS],
            "адресДоставкиДляТК" => $addressDost, // $arInvoice[CRMFields::DELIVERY_ADRESS],
            "видДоставки"        => $arInvoice[CRMFields::DELIVERY_TYPE],
            "контактноеЛицо"     => $this->getContactPhone((int)$arInvoice[CRMFields::DELIVERY_CONTACT]),
            "плательщикДляТК"    => $this->getRequisites((int)$arInvoice[CRMFields::PAYER]),
            "видДоговора"        => "с покупателем",
            "типЦен"             => "отпускная (руб)",
            "основнаяСтатья"     => "СИЗОД",
            "подразделение"      => "Департамент СИЗОД и ФПП",
            "cклад"              => "Склад ГП СИЗОД",
            // Добавил 2021-10-29
            "условияОплаты"      => $arInvoice[CRMFields::TERMS_OF_PAYMENT],
            "срокПоставки"       => $arInvoice[CRMFields::TIME_DELIVERY],
            // ДОбавил 2022-03-09
            "отгрузкаТранзитом"  => $arInvoice[CRMFields::SHIPMENT_TRANSIT],
        ];

        // потом удали
//        $ar2sting = $arInvoice[CRMFields::DELIVERY_ADRESS];
//        $logger->write(print_r('$arInvoice[CRMFields::DELIVERY_ADRESS]' . $ar2sting, true));

        return $arDealClean;
    } //Потребитель

//    public const CONSIGNEE = "UF_CRM_5F9BF01B2DD12"; //грузополучатель

    public
    function stringCutGeo($hayStack, string $needle)
    { //    адресу обрезать геокоординаты

        if (strpos($hayStack, $needle) !== false) {
            $hayStack = strtok($hayStack, $needle);
        }
        return $hayStack;
    } //грузополучатель для ТК

    public
    function getRequisites(int $idCompnay): array
    {
        $arRequisites = [
            "компанияНаименование"  => "-",
            "компанияИНН"           => "-",
            "компанияЮрАдрес"       => "-",
            "компанияАдресДоставки" => "-",
            "компанияКПП"           => "-",
        ];
        if ($idCompnay <= 0)
            return $arRequisites;

        $arCrmRequest = CRest::call(
            'crm.requisite.list',
            [
                "filter" => [
                    "ENTITY_ID" => $idCompnay,
                ],
                "order"  => [
                    "ID" => "desc"
                ]
            ]
        );

        if (!empty($arCrmRequest["result"])) {

            $arCrmAdressRequest = CRest::call(
                'crm.address.list',
                [
                    "filter" => [
                        "ENTITY_ID"      => $arCrmRequest["result"][0]["ID"],
                        "ENTITY_TYPE_ID" => 8,
                    ],
                ]
            );

            $sAdress = "";
            $sDeliveryAdres = '';
            if (!empty($arCrmAdressRequest["result"])) {
                foreach ($arCrmAdressRequest["result"] as $arOneAdress) {
                    $arCrmAdr = [];
                    if ($arOneAdress["POSTAL_CODE"]) array_push($arCrmAdr, $arOneAdress["POSTAL_CODE"]);
                    if ($arOneAdress["COUNTRY"]) array_push($arCrmAdr, $arOneAdress["COUNTRY"]);
                    if ($arOneAdress["PROVINCE"]) array_push($arCrmAdr, $arOneAdress["PROVINCE"]);
                    if ($arOneAdress["CITY"]) array_push($arCrmAdr, $arOneAdress["CITY"]);
                    if ($arOneAdress["ADDRESS_1"]) array_push($arCrmAdr, $arOneAdress["ADDRESS_1"]);
                    if ($arOneAdress["ADDRESS_2"]) array_push($arCrmAdr, $arOneAdress["ADDRESS_2"]);

                    if ($arOneAdress["TYPE_ID"] == 6) // юр адрес
                    {
                        $sAdress = implode($arCrmAdr, ", ");
                    }
                    if ($arOneAdress["TYPE_ID"] == 11) //  адрес доставки
                    {
                        $sDeliveryAdres = implode($arCrmAdr, ", ");
                    }
                }
            }
// потом удали
//            $logger = new Logger ("log.txt");
//            $logger->write(print_r($arCrmRequest["result"], true));

            $arRequisites = [
                // "компанияНаименование" => $arCrmRequest["result"][0]["RQ_COMPANY_FULL_NAME"] ? $arCrmRequest["result"][0]["RQ_COMPANY_FULL_NAME"] : "-",
                "компанияНаименование"  => $arCrmRequest["result"][0]["RQ_COMPANY_FULL_NAME"] ? $arCrmRequest["result"][0]["RQ_COMPANY_FULL_NAME"] : $arCrmRequest["result"][0]["NAME"],
                "компанияИНН"           => $arCrmRequest["result"][0]["RQ_INN"] ? $arCrmRequest["result"][0]["RQ_INN"] : "-",
                "компанияЮрАдрес"       => $sAdress,
                "компанияАдресДоставки" => $sDeliveryAdres,
                "компанияКПП"           => $arCrmRequest["result"][0]["RQ_KPP"] ? $arCrmRequest["result"][0]["RQ_KPP"] : "-",
            ];
        }
        return $arRequisites;
    } //плательщик для ТК

//    public const DELIVERY_ADRESS = "UF_CRM_1610367809"; // адрес доставки

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
        $sPhone = "";
        if ($idContact > 0) {
            $arContactFields = CRest::call(
                'crm.contact.get',
                [
                    "id" => $idContact
                ]
            );

            if (!empty($arContactFields["result"])) {
                if (!empty($arContactFields["result"]["PHONE"]))
                    $sPhone = $arContactFields["result"]["PHONE"][0]["VALUE"];

                $sContact = [
                    "фамилия"  => $arContactFields["result"]["LAST_NAME"] ?? "",
                    "имя"      => $arContactFields["result"]["NAME"] ?? "",
                    "отчество" => $arContactFields["result"]["SECOND_NAME"] ?? "",
                    "телефон"  => $arContactFields["result"]["PHONE"][0]["VALUE"] ?? "",
                    "email"    => $arContactFields["result"]["EMAIL"][0]["VALUE"] ?? "",
                ];
            }
        }
        return $sContact;
    } // Адрес доставки для ТК

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
            $arCatalogProducts = [];

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
    }

    protected
    function getProductProperties(): array
    {
        $arProperties = [];

        $arCrmPropertiesRequest = CRest::call('crm.product.property.list', []);
        if (isset($arCrmPropertiesRequest["result"]))
            foreach ($arCrmPropertiesRequest["result"] as $arProperty)
                $arProperties[$arProperty["ID"]] = $arProperty;

        return $arProperties;
    } // договор поставки

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
                            if ($arProps[$idProperty]["MULTIPLE"] == "Y") {
                                $arPropertyValues = [];
                                foreach ($arField as $arOneValue) {
                                    if ($arProps[$idProperty]["PROPERTY_TYPE"] == "L")
                                        $arPropertyValues[] = $arProps[$idProperty]["VALUES"][$arOneValue["value"]]["VALUE"];
                                    else
                                        $arPropertyValues[] = $arOneValue["value"];
                                }
                                $newProperty["VALUE"] = $arPropertyValues;
                            } else
                                if ($arProps[$idProperty]["PROPERTY_TYPE"] == "L")
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
    } // тип доставки

    protected
    function prepareProductsForXml(array $arDirtyProducts = [], array $arInvoiceItems = []): array
    {
        if (empty($arDirtyProducts))
            return [];

        $arSortedItems = [];
        foreach ($arInvoiceItems as $arItem)
            $arSortedItems[$arItem['ID']] = $arItem;

        $arClearProducts = [];
        foreach ($arDirtyProducts as $arOneProduct) {
            $bVatIncluded = ($arSortedItems[$arOneProduct['ID']]["VAT_INCLUDED"] == "Y");


            if ($bVatIncluded) {
                $dFinalPrice = $arSortedItems[$arOneProduct["ID"]]["PRICE"];
                $dPriceWithoutTax = $dFinalPrice / (1 + $arSortedItems[$arOneProduct["ID"]]["VAT_RATE"]);
                $dTaxSize = $arSortedItems[$arOneProduct["ID"]]["PRICE"] - $dPriceWithoutTax;

                $dPriceWithoutTaxAndWithoutDiscount = ($dPriceWithoutTax + $arOneProduct["DISCOUNT_SUM"]) / 9 * 10;

            } else {
                $dFinalPrice = $arSortedItems[$arOneProduct["ID"]]["PRICE"];
                $dPriceWithoutTax = $dFinalPrice / (1 + $arSortedItems[$arOneProduct["ID"]]["VAT_RATE"]);
                $dTaxSize = $dFinalPrice * $arSortedItems[$arOneProduct["ID"]]["VAT_RATE"];

                $dPriceWithoutTaxAndWithoutDiscount = $dPriceWithoutTax + $arOneProduct["DISCOUNT_SUM"];

            }

            $priceExclusiveWithDiscount = $arSortedItems[$arOneProduct["ID"]]["PRICE"];


            $arClearProducts[] = [
                "наименование"   => $arOneProduct['PRODUCT_NAME'], //
                "количество"     => $arOneProduct['QUANTITY'], //
                "производитель"  => implode($arOneProduct['PROPS']['PROPERTY_485']["VALUE"], ";"),
                "код"            => ($arOneProduct['PROPS']['PROPERTY_539']["VALUE"] ? $arOneProduct['PROPS']['PROPERTY_539']["VALUE"] : ""),  //$arOneProduct['PRODUCT_ID'],
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
    } // Контактное лицо грузополучателя

//public const DELIVERY_CONTACT = "UF_CRM_5F9BF01AAC772"; // контактное лицо при доставке

    protected
    function writeToFile($xml, array $arDataForXml, string $sectionName, string $elementName = "")
    {
        $node = $xml->addChild($sectionName);
        $this->arrayToXml($arDataForXml, $node, $elementName);

    } // инфоблок контрактов

// Добавил 2021-10-29

    public
    function arrayToXml(array $array, &$xml, $elementName = "")
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (!is_numeric($key)) {
                    $subnode = $xml->addChild("$key");
                    $this->arrayToXml($value, $subnode);
                } else {
                    if ($elementName != "")
                        $subnode = $xml->addChild("$elementName");
                    $this->arrayToXml($value, $subnode);
                }
            } else {
                $xml->addChild("$key", "$value");
            }
        }
    } // Условия оплаты

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

    public function getListValue(int $elementId, int $iblockId)
    {
        $arDealEnumFields = CRest::call(
            'lists.element.get',
            [
                "FILTER"         =>
                    [
                        'ID' => $elementId
                    ],
                "IBLOCK_ID"      => $iblockId,
                "IBLOCK_TYPE_ID" => "lists"
            ]
        );

        return !empty($arDealEnumFields["result"]) ? $arDealEnumFields["result"][0]["NAME"] : "-";
    }

    public function log($variable)
    { // вывести в лог имя переменной и значение

        $logger = new Logger ("log.txt");
        $logger->write(print_r($variable, true));
        $logger->write(variable_Name($variable));
    }

    public function variable_Name(): string
    { // вернуть имя переменной

        // читаем обратную трассировку
        $bt = debug_backtrace();

        // читаем файл
        $file = file($bt[0]['file']);

        // выбираем точную строку print_var_name($varname)
        $src = $file[$bt[0]['line'] - 1];

        // шаблон поиска
        $pat = '#(.*)' . __FUNCTION__ . ' *?\( *?(.*) *?\)(.*)#i';

        // извлечение $varname из совпадения №2
        return preg_replace($pat, '$2', $src);
    }
}

class CRMFields
{
    public const SHIPPER = "UF_CRM_1610359373";
    public const CONSUMER = "UF_CRM_5F9BF01B658A8";
    public const CONSIGNEE = "UF_CRM_613AF9D4D685D";
    public const PAYER = "UF_CRM_5F9BF01B7389A";
    public const DELIVERY_ADRESS = "UF_CRM_60D96D778591E";
    public const CONTRACT = "UF_CRM_1608889603";
    public const DELIVERY_TYPE = "UF_CRM_5F9BF01B5504B";
    public const DELIVERY_CONTACT = "UF_CRM_60D96D7707C69";
    public const CONTRACT_IBLOCK_ID = 37;
    public const TERMS_OF_PAYMENT = 'UF_CRM_613AF9D4CF1D7';
    public const TIME_DELIVERY = 'UF_CRM_616D4E97213B7';
    public const SHIPMENT_TRANSIT = 'UF_CRM_6144A3A9DEE1D';
    public $addressWithGeo = "UF_CRM_60D96D778591E";
}

