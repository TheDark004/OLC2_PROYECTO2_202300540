<?php
require "vendor/autoload.php";
require "bootstrap.php";

$code = file_get_contents("test/archivo3_funciones.go");
$compiler = new Compiler();
$result = $compiler->compileAndRun($code);

if(is_array($result) && isset($result["asm"])) {
  file_put_contents("tmp/debug_full.s", $result["asm"]);
  echo "Assembly guardado en tmp/debug_full.s\n";
  echo "Tamaño: " . strlen($result["asm"]) . " bytes\n";
  
  // Extraer solo las funciones potencia y euclides
  $asm = $result["asm"];
  $lines = explode("\n", $asm);
  
  $potenciaStart = false;
  $euclidesStart = false;
  $potenciaAsm = [];
  $euclidesAsm = [];
  
  foreach ($lines as $line) {
    if (strpos($line, "potencia:") !== false) {
      $potenciaStart = true;
      $euclidesStart = false;
    } elseif (strpos($line, "euclides:") !== false) {
      $euclidesStart = true;
      $potenciaStart = false;
    } elseif ($potenciaStart && preg_match('/^[a-zA-Z_]/', $line)) {
      if (trim($line) !== "" && !str_starts_with($line, "\t")) {
        $potenciaStart = false;
      }
    } elseif ($euclidesStart && preg_match('/^[a-zA-Z_]/', $line)) {
      if (trim($line) !== "" && !str_starts_with($line, "\t")) {
        $euclidesStart = false;
      }
    }
    
    if ($potenciaStart) {
      $potenciaAsm[] = $line;
    } elseif ($euclidesStart) {
      $euclidesAsm[] = $line;
    }
  }
  
  echo "\n=== POTENCIA ===\n";
  echo implode("\n", array_slice($potenciaAsm, 0, 100)) . "\n";
  
  echo "\n=== EUCLIDES ===\n";
  echo implode("\n", array_slice($euclidesAsm, 0, 100)) . "\n";
} else {
  echo "Error en compilación\n";
  var_dump($result);
}
?>
