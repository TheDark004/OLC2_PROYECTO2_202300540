<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

use Antlr\Antlr4\Runtime\InputStream;
use Antlr\Antlr4\Runtime\CommonTokenStream;
use Antlr\Antlr4\Runtime\Error\BailErrorStrategy;
use Antlr\Antlr4\Runtime\Error\Exceptions\ParseCancellationException;
use Antlr\Antlr4\Runtime\Error\Exceptions\InputMismatchException;

$input   = "";
$output  = "";
$errores = [];
$symbols = [];
$parserListener = null;
$asmDownloadReady = false;
$asmDownloadHref = "download_asm.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = $_POST["code"] ?? "";

    if (!empty(trim($input))) {
        try {
            $inputStream = InputStream::fromString($input);
            $lexer       = new GolampiLexer($inputStream);

            $lexerListener = new class implements \Antlr\Antlr4\Runtime\Error\Listeners\ANTLRErrorListener {
                public array $errors = [];
                public function syntaxError($recognizer, $offendingSymbol, int $line, int $charPositionInLine, string $msg, $e): void {
                    $this->errors[] = [
                        'type' => 'Léxico',
                        'desc' => "Token no reconocido: '" . trim(str_replace('token recognition error at:', '', $msg)) . "'",
                        'line' => $line,
                        'col'  => $charPositionInLine,
                    ];
                }
                public function reportAmbiguity(...$args): void {}
                public function reportAttemptingFullContext(...$args): void {}
                public function reportContextSensitivity(...$args): void {}
            };

            $lexer->removeErrorListeners();
            $lexer->addErrorListener($lexerListener);
            $tokens      = new CommonTokenStream($lexer);
            $parser      = new GolampiParser($tokens);

            $parserListener = new class implements \Antlr\Antlr4\Runtime\Error\Listeners\ANTLRErrorListener {
                public array $errors = [];
                public function syntaxError($recognizer, $offendingSymbol, int $line, int $charPositionInLine, string $msg, $e): void {
                    $this->errors[] = [
                        'type' => 'Sintáctico',
                        'desc' => "Entrada inválida: " . $msg,
                        'line' => $line,
                        'col'  => $charPositionInLine,
                    ];
                }
                public function reportAmbiguity(...$args): void {}
                public function reportAttemptingFullContext(...$args): void {}
                public function reportContextSensitivity(...$args): void {}
            };
            
            // Si hay error sintáctico, esto abortará e irá directo al catch
            $parser->removeErrorListeners();
            $parser->addErrorListener($parserListener);
            $parser->setErrorHandler(new BailErrorStrategy()); 
            
            $tree = $parser->program();
            $semantic = new SemanticVisitor();
            $semantic->visit($tree);

            // Obtenemos los símbolos para mostrarlos en la tabla de tu Frontend
            $symbols = $semantic->symbolTable->getAllSymbols(); 

            // Revisar si hubo errores léxicos o semánticos
            if (count($lexerListener->errors) > 0 || count($parserListener->errors) > 0 || count($semantic->errors) > 0) {
                // Juntar todos los errores para la pestaña de Errores
                $errores = array_merge($lexerListener->errors, $parserListener->errors, $semantic->errors);
            } else {
                // GENERACIÓN DE CÓDIGO (Si no hay errores)
                $codegen = new CodeGen($semantic->symbolTable);
                $codegen->visit($tree);

                $asmCode = $codegen->getOutput();

                $tmpDir = __DIR__ . '/tmp';
                if (!is_dir($tmpDir)) {
                    mkdir($tmpDir, 0775, true);
                }
                file_put_contents($tmpDir . '/output.s', $asmCode);
                $asmDownloadReady = true;

                // La salida del compilador es ARM64. El .s queda en tmp/output.s
                // para ensamblarlo, enlazarlo y correrlo con QEMU aparte.
                $output = $asmCode;
            } 

        } catch (Throwable $e) { 
            if ($parserListener !== null && count($parserListener->errors) > 0) {
                $errores = array_merge($errores, $parserListener->errors);
            }

            if ($e instanceof ParseCancellationException && count($errores) === 0) {
                $errores[] = [
                    'type' => 'Sintáctico',
                    'desc' => "Entrada inválida. Recuerda que las sentencias deben estar dentro de una función, por ejemplo func main() { ... }.",
                    'line' => 1,
                    'col'  => 0
                ];
            } elseif (count($errores) === 0) {
                $errores[] = [
                    'type' => 'Sintáctico/Crítico', // Puede ser sintáctico por el BailErrorStrategy
                    'desc' => $e->getMessage(),
                    'line' => 0,
                    'col'  => 0
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Golampi-202300540</title>
    <link rel="stylesheet" href="/static/Style.css">
</head>
<body>

<!-- Navbar -->
<div class="navbar">
    <h1>René Gutiérrez &#11042; Golampi Interpreter &#11042; 202300540</h1>
    <button type="button" onclick="nuevoArchivo()">Nuevo</button>
    <button type="button" onclick="document.getElementById('fileInput').click()">Cargar</button>
    <input type="file" id="fileInput" accept=".go,.golampi,.txt" onchange="cargarArchivo(event)">
    <button type="button" onclick="guardarArchivo()">Guardar</button>
    <?php if ($asmDownloadReady): ?>
        <a class="btn-download" href="<?= htmlspecialchars($asmDownloadHref) ?>" download="output.s">Descargar .s</a>
    <?php else: ?>
        <button type="button" class="btn-disabled" disabled>Descargar .s</button>
    <?php endif; ?>
    <input type="submit" form="mainForm" class="btn-run" value="&#9654; Run">
    <button type="button" onclick="limpiarConsola()">Limpiar consola</button>
</div>

<form id="mainForm" method="POST">
<div class="workspace">

    <!-- Editor arriba -->
    <div class="editor-section">
        <div class="section-title">Código fuente</div>
        <div class="editor-wrap">
            <div class="line-numbers" id="lineNumbers">1</div>
            <textarea
                id="code" name="code"
                spellcheck="false"
                oninput="actualizarLineas()"
                onscroll="syncScroll()"
                onkeydown="handleTab(event)"
            ><?php echo htmlspecialchars($input); ?></textarea>
        </div>
    </div>

    <!-- Consola + Reportes abajo -->
    <div class="bottom-section">

        <!-- Consola -->
        <div class="console-wrap">
            <div class="section-title">Salida</div>
            <pre class="console-out"><?php echo htmlspecialchars($output); ?></pre>
        </div>

        <!-- Pestañas -->
        <div class="reports-wrap">
            <div class="tabs">
                <button type="button" class="tab active" id="tabErrores" onclick="switchTab('Errores')">
                    Errores
                    <?php if (!empty($errores)): ?>
                        <span class="badge"><?= count($errores) ?></span>
                    <?php endif; ?>
                </button>
                <button type="button" class="tab" id="tabSimbolos" onclick="switchTab('Simbolos')">
                    Tabla de Símbolos  
                </button>
            </div>

            <!-- Panel Errores -->
            <div id="panelErrores" class="tab-panel active">
                <?php if (!isset($_POST['code'])): ?>
                    <p class="placeholder"></p>
                <?php elseif (empty($errores)): ?>
                    <p class="sin-errores"></p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr><th>#</th><th>Tipo</th><th>Descripción</th><th>Línea</th><th>Columna</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($errores as $i => $err): ?>
                            <?php $tag = match($err['type']) {
                                'Léxico'     => 'tag-lex',
                                'Sintáctico' => 'tag-sint',
                                default      => 'tag-sem'
                            }; ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><span class="tag <?= $tag ?>"><?= htmlspecialchars($err['type']) ?></span></td>
                                <td><?= htmlspecialchars($err['desc']) ?></td>
                                <td><?= $err['line'] ?></td>
                                <td><?= $err['col'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Panel Símbolos -->
            <div id="panelSimbolos" class="tab-panel">
                <?php if (!isset($_POST['code'])): ?>
                    <p class="placeholder"></p>
                <?php elseif (empty($symbols)): ?>
                    <p class="placeholder">No se encontraron símbolos.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr><th>#</th><th>Identificador</th><th>Tipo</th><th>Offset (Memoria)</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($symbols as $i => $sym): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($sym->name ?? '') ?></strong></td>
                                <td><?= htmlspecialchars($sym->type ?? '') ?></td>
                                <td><span class="badge"><?= htmlspecialchars($sym->offset ?? '0') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</form>

<script src="/static/Script.js"></script>
</body>
</html>
