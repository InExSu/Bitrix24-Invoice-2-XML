<?php
// вебхук исходящий 269
// вебхук входящий  271
require "config.php";
try 
{
    $app = new Application();
    $app->run();
} 
catch (\Throwable $th) 
{
    $logger = new Logger ("log.txt");
    $logger->write("!!! ОШИБКА !!! : " . print_r($th->getMessage(), true));
}