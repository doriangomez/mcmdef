<?php
header('Content-Type: text/plain; charset=UTF-8');
echo 'SAPI=' . php_sapi_name() . PHP_EOL;
echo 'upload_max_filesize=' . ini_get('upload_max_filesize') . PHP_EOL;
echo 'post_max_size=' . ini_get('post_max_size') . PHP_EOL;
echo 'memory_limit=' . ini_get('memory_limit') . PHP_EOL;
echo 'max_execution_time=' . ini_get('max_execution_time') . PHP_EOL;
