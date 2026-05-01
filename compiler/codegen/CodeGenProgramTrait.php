<?php

trait CodeGenProgramTrait {
    // Acumula strings para emitirlos en .data antes de .text
    // [ 'STR_1' => 'Hola mundo', 'STR_2' => 'otro texto', ... ]
    private array $stringLiterals = [];
    private array $floatLiterals = [];

    // HELPERS PARA STRINGS
    private function internString(string $value): string {
        // "\n" al salto de línea
        $value = str_replace('\n', "\n", $value);
        // También puedes manejar tabulaciones si lo necesitas
        $value = str_replace('\t', "\t", $value);

        $existing = array_search($value, $this->stringLiterals, true);
        if ($existing !== false) return $existing;
        
        $label = $this->labels->newLabel('STR');
        $this->stringLiterals[$label] = $value;
        return $label;
    }

    private function internFloatLiteral(string $value): array {
        $key = trim($value);
        if ($key === '' || (float) $key === 0.0) {
            $key = '0';
        }

        if (isset($this->floatLiterals[$key])) {
            return $this->floatLiterals[$key];
        }

        $bitsLabel = $this->labels->newLabel('FLT_BITS');
        $strLabel = $this->labels->newLabel('FLT_STR');
        $display = $key;
        $bits = unpack('V', pack('g', (float) $key))[1];

        $this->floatLiterals[$key] = [
            'bitsLabel' => $bitsLabel,
            'strLabel' => $strLabel,
            'display' => $display,
            'bits' => $bits,
        ];

        return $this->floatLiterals[$key];
    }

    private function emitWriteLabel(string $label, int $length): void {
        $this->asm->writeLine("adrp x1, $label");
        $this->asm->writeLine("add  x1, x1, :lo12:$label");
        $this->asm->writeLine("mov  x0, #1");
        $this->asm->writeLine("mov  x2, #$length");
        $this->asm->writeLine("mov  x8, #64");
        $this->asm->writeLine("svc  #0");
    }

    private function emitNewline(): void {
        $this->emitWriteLabel('newline', 1);
    }

    /**
     * Espacio entre argumentos del mismo Println.
     * fmt.Println("a", "b") -> imprime "a b\n"
     */
    private function emitSpace(): void {
        $this->emitWriteLabel('space', 1);
    }

    /**
     * Escapa un string PHP para GNU assembler (.ascii).
     * GNU as entiende \n, \t, \\, \" dentro de cadenas.
     */
    private function escapeForGas(string $s): string {
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace('"',  '\\"',  $s);
        $s = str_replace("\n", '\\n',  $s);
        $s = str_replace("\t", '\\t',  $s);
        return $s;
    }

    /**
     * Pre-pasada: recorre el AST buscando STRING_LIT para registrarlos
     * en $stringLiterals antes de emitir código.
     */
    private function collectStrings($ctx): void {
        if ($ctx === null) return;
        if ($ctx instanceof \Context\StringLitContext) {
            $raw   = $ctx->getText();
            $value = substr($raw, 1, strlen($raw) - 2); // quitar comillas
            $this->internString($value);
            return;
        }
        if ($ctx instanceof \Context\FloatLitContext) {
            $this->internFloatLiteral($ctx->getText());
            return;
        }
        if (property_exists($ctx, 'children') && $ctx->children !== null) {
            foreach ($ctx->children as $child) {
                $this->collectStrings($child);
            }
        }
    }

    // INICIO DEL PROGRAMA
    public function visitP($ctx) {
        $this->collectStrings($ctx);
        $this->internFloatLiteral('0');

        $this->asm->addRaw(".section .data\n");
        $this->asm->addRaw("newline:   .ascii \"\\n\"\n");
        $this->asm->addRaw("space:     .ascii \" \"\n");
        $this->asm->addRaw("empty_str: .asciz \"\"\n");
        $this->asm->addRaw("str_true:  .ascii \"true\"\n");
        $this->asm->addRaw("str_false: .ascii \"false\"\n");
        $this->asm->addRaw("str_nil:   .ascii \"<nil>\"\n");
        $this->asm->addRaw("str_float_unknown: .ascii \"<float>\"\n");

        foreach ($this->stringLiterals as $label => $value) {
            $escaped = $this->escapeForGas($value);
            $len     = strlen($value);
            $this->asm->addRaw("$label: .asciz \"$escaped\" /* len=$len */\n");
        }

        foreach ($this->floatLiterals as $floatMeta) {
            $escaped = $this->escapeForGas($floatMeta['display']);
            $len = strlen($floatMeta['display']);
            $bits = sprintf("0x%08X", $floatMeta['bits']);
            $this->asm->addRaw("{$floatMeta['bitsLabel']}: .word $bits\n");
            $this->asm->addRaw("{$floatMeta['strLabel']}: .asciz \"$escaped\" /* len=$len */\n");
        }

        $this->asm->addRaw("\n.section .text\n");
        $this->asm->addRaw(".align 2\n");

        $this->asm->addRaw("\n# print_int: imprime w0 como entero decimal\n");
        $this->asm->addRaw("print_int:\n");
        $this->asm->addRaw("\tsub sp, sp, #32\n");
        $this->asm->addRaw("\tmov x1, sp\n");
        $this->asm->addRaw("\tadd x1, x1, #30\n");
        $this->asm->addRaw("\tmov w2, #10\n");
        $this->asm->addRaw("\tmov w3, w0\n");
        $this->asm->addRaw("\tcbnz w3, print_int_loop\n");
        $this->asm->addRaw("\tmov w4, #48\n");
        $this->asm->addRaw("\tstrb w4, [x1]\n");
        $this->asm->addRaw("\tmov x2, #1\n");
        $this->asm->addRaw("\tb print_int_write\n");
        $this->asm->addRaw("print_int_loop:\n");
        $this->asm->addRaw("\tudiv w4, w3, w2\n");
        $this->asm->addRaw("\tmsub w5, w4, w2, w3\n");
        $this->asm->addRaw("\tadd w5, w5, #48\n");
        $this->asm->addRaw("\tstrb w5, [x1]\n");
        $this->asm->addRaw("\tsub x1, x1, #1\n");
        $this->asm->addRaw("\tmov w3, w4\n");
        $this->asm->addRaw("\tcbnz w3, print_int_loop\n");
        $this->asm->addRaw("\tadd x1, x1, #1\n");
        $this->asm->addRaw("\tadd x3, sp, #31\n");
        $this->asm->addRaw("\tsub x2, x3, x1\n");
        $this->asm->addRaw("print_int_write:\n");
        $this->asm->addRaw("\tmov x0, #1\n");
        $this->asm->addRaw("\tmov x8, #64\n");
        $this->asm->addRaw("\tsvc #0\n");
        $this->asm->addRaw("\tadd sp, sp, #32\n");
        $this->asm->addRaw("\tret\n");

        $this->asm->addRaw("\n# print_cstr: imprime string terminado en cero apuntado por x0\n");
        $this->asm->addRaw("print_cstr:\n");
        $this->asm->addRaw("\tmov x1, x0\n");
        $this->asm->addRaw("\tmov x2, #0\n");
        $this->asm->addRaw("print_cstr_loop:\n");
        $this->asm->addRaw("\tldrb w3, [x1, x2]\n");
        $this->asm->addRaw("\tcbz w3, print_cstr_write\n");
        $this->asm->addRaw("\tadd x2, x2, #1\n");
        $this->asm->addRaw("\tb print_cstr_loop\n");
        $this->asm->addRaw("print_cstr_write:\n");
        $this->asm->addRaw("\tmov x0, #1\n");
        $this->asm->addRaw("\tmov x8, #64\n");
        $this->asm->addRaw("\tsvc #0\n");
        $this->asm->addRaw("\tret\n");

        $this->asm->addRaw("\n.global _start\n\n");

        foreach ($ctx->decl() as $decl) {
            $this->visit($decl);
        }

        return null;
    }
}
