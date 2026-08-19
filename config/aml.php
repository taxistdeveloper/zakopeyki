<?php

/**
 * AML/CFT: локальная сверка с перечнями АФМ РК.
 *
 * Официального открытого REST API у АФМ для частных платформ нет.
 * Списки публикуются на портале АФМ / websfm.kz (XML/JSON). Прямой URL
 * может требовать регистрацию субъекта финансового мониторинга.
 * Задайте AML_XML_URL или положите файл в storage/aml/afm_list.xml.
 */
$root = dirname(__DIR__);

return [
    'xml_urls' => array_values(array_filter([
        getenv('AML_XML_URL') ?: null,
        is_file($root . '/storage/aml/afm_list.xml') ? $root . '/storage/aml/afm_list.xml' : null,
    ])),
    'temp_file' => $root . '/storage/aml/temp_afm_list.xml',
    'chunk_size' => 500,
    'user_agent' => 'Zakopeyki-AML-Sync/1.0',
    'timeout' => 90,
    'redis' => [
        'main_key' => 'aml:blacklisted_iins',
        'temp_key' => 'aml:blacklisted_iins:temp',
    ],
];
