<?php

// autoload.php @generated by Composer

require_once __DIR__ . '/composer/autoload_real.php';

ComposerAutoloaderInit28bd722cc0e471e36fc2ad8a441308e8::getLoader();

require_once __DIR__.'/mkreusch/amzon-pay-api-extended-sdk/Struct/StructBase.php';
require_once __DIR__.'/mkreusch/amzon-pay-api-extended-sdk/Struct/Price.php';
foreach(glob(__DIR__.'/mkreusch/amzon-pay-api-extended-sdk/*/*.php') as $_file){
    require_once $_file;
}


