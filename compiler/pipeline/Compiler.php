<?php

use Antlr\Antlr4\Runtime\CommonTokenStream;
use Antlr\Antlr4\Runtime\InputStream;

class Compiler {
    public function compileAndRun(string $input): array {
        try {
            // ---  LÉXICO Y SINTÁCTICO ---
            $stream = InputStream::fromString($input);
            $lexer = new GolampiLexer($stream);
            $tokens = new CommonTokenStream($lexer);
            $parser = new GolampiParser($tokens);
            $tree = $parser->program();

            // ---  ANÁLISIS SEMÁNTICO ---
            $semantic = new SemanticVisitor();
            $semantic->visit($tree);

            if (count($semantic->errors) > 0) {
                return [
                    'status' => 'error_semantico',
                    'errors' => $semantic->errors
                ];
            }

            // --- GENERACIÓN DE CÓDIGO ---
            $codegen = new CodeGen($semantic->symbolTable);
            $codegen->visit($tree);
            $asmCode = $codegen->getOutput();

            // --- EJECUCIÓN ---
            $runner = new QemuRunner();
            $result = $runner->run($asmCode);

            return [
                'status' => 'success',
                'asm'    => $asmCode,
                'output' => $result['output'] ?? '',
                'error'  => $result['error'] ?? null
            ];

        } catch (Throwable $e) {
            return [
                'status' => 'error_critico',
                'message' => $e->getMessage()
            ];
        }
    }
}
