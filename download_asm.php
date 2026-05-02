<?php

$asmFile = __DIR__ . '/tmp/output.s';

if (!is_file($asmFile)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "No se encontró tmp/output.s. Primero compila el programa con Run.";
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: attachment; filename="output.s"');
header('Content-Length: ' . filesize($asmFile));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

readfile($asmFile);
