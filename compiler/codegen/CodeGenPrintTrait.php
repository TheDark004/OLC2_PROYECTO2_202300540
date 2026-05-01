<?php

trait CodeGenPrintTrait {
    private function isFloatLiteralText(string $text): bool {
        return preg_match('/^[0-9]+\.[0-9]+$/', $text) === 1;
    }

    private function isBoolExpr($expr): bool {
        return $expr instanceof \Context\RelExprContext
            || $expr instanceof \Context\EqExprContext
            || $expr instanceof \Context\AndExprContext
            || $expr instanceof \Context\OrExprContext
            || $expr instanceof \Context\NotExprContext;
    }

    private function emitPrintBoolReg(string $reg): void {
        $wReg = $this->regs->to32($reg);
        $lTrue = $this->labels->newLabel('BT');
        $lEnd = $this->labels->newLabel('BE');

        $this->asm->writeLine("cmp $wReg, #0");
        $this->asm->writeLine("b.ne $lTrue");
        $this->emitWriteLabel('str_false', 5);
        $this->asm->writeLine("b $lEnd");
        $this->asm->writeLabel($lTrue);
        $this->emitWriteLabel('str_true', 4);
        $this->asm->writeLabel($lEnd);
    }

    private function emitPrintFloatReg(string $reg): void {
        $wReg = $this->regs->to32($reg);
        $matchLabel = $this->labels->newLabel('FLT_MATCH');
        $endLabel = $this->labels->newLabel('FLT_END');

        foreach ($this->floatLiterals as $meta) {
            $nextLabel = $this->labels->newLabel('FLT_NEXT');
            $tmpAddr = $this->regs->allocate();
            $tmpVal = $this->regs->allocate();
            $tmpW = $this->regs->to32($tmpVal);

            $this->asm->writeLine("adrp $tmpAddr, {$meta['bitsLabel']}");
            $this->asm->writeLine("add  $tmpAddr, $tmpAddr, :lo12:{$meta['bitsLabel']}");
            $this->asm->writeLine("ldr $tmpW, [$tmpAddr]");
            $this->asm->writeLine("cmp $wReg, $tmpW");
            $this->asm->writeLine("b.ne $nextLabel");
            $this->emitWriteLabel($meta['strLabel'], strlen($meta['display']));
            $this->asm->writeLine("b $endLabel");
            $this->asm->writeLabel($nextLabel);

            $this->regs->free($tmpAddr);
            $this->regs->free($tmpVal);
        }

        $this->emitWriteLabel('str_float_unknown', 7);
        $this->asm->writeLabel($endLabel);
    }

    //  FMT.PRINTLN —  MULTI-TIPO Y MULTI-ARGUMENTO
    /**
     * Despacha cada argumento al emisor correcto según su tipo.
     * Entre argumentos emite un espacio. Al final siempre newline.
     *
     * Tipos soportados:
     *   StringLit  -> syscall write directo al label en .data
     *   BoolLit    -> imprime "true" o "false" desde .data
     *   IdExpr     -> detecta tipo en tabla de símbolos y despacha
     *   cualquier  -> evalúa expresión y llama print_int
     */
    public function visitPrintlnStmt($ctx) {
        $exprs = $ctx->e();
        $this->asm->writeComment("--- fmt.Println ---");

        foreach ($exprs as $i => $expr) {
            if ($i > 0) $this->emitSpace();
            $this->emitPrintExpr($expr);
        }

        $this->emitNewline();
        return null;
    }

    // Emite código de impresión para UN argumento de Println.
    private function emitPrintExpr($expr): void {
        $exprText = method_exists($expr, 'getText') ? $expr->getText() : '';

        // String literal: "Hola"
        if (strlen($exprText) >= 2 && $exprText[0] === '"' && substr($exprText, -1) === '"') {
            $raw   = $exprText;
            $value = substr($raw, 1, strlen($raw) - 2);
            $label = $this->internString($value);
            $this->asm->writeComment("print string: $raw");
            $this->emitWriteLabel($label, strlen($value));
            return;
        }

        // Bool literal: true / false
        if ($exprText === 'true' || $exprText === 'false') {
            if ($exprText === 'true') {
                $this->asm->writeComment("print bool literal: true");
                $this->emitWriteLabel('str_true', 4);
            } else {
                $this->asm->writeComment("print bool literal: false");
                $this->emitWriteLabel('str_false', 5);
            }
            return;
        }

        if ($exprText === 'nil') {
            $this->asm->writeComment("print nil literal");
            $this->emitWriteLabel('str_nil', 5);
            return;
        }

        if ($this->isFloatLiteralText($exprText)) {
            $reg = $this->visit($expr);
            $this->asm->writeComment("print float literal");
            $this->emitPrintFloatReg($reg);
            $this->regs->free($reg);
            return;
        }

        if ($this->isBoolExpr($expr)) {
            $reg = $this->visit($expr);
            $this->asm->writeComment("print bool expr");
            $this->emitPrintBoolReg($reg);
            $this->regs->free($reg);
            return;
        }

        // Variable: despachar según tipo en tabla de símbolos
        if (method_exists($expr, 'ID') && $expr->ID() !== null) {
            $name   = $expr->ID()->getText();
            $symbol = $this->getSymbol($name);

            if ($symbol !== null) {
                // Bool: leer 0/1 y elegir "true" o "false"
                if ($symbol->type === 'bool') {
                    $reg = $this->visit($expr);
                    $this->asm->writeComment("print bool var '$name'");
                    $this->emitPrintBoolReg($reg);
                    $this->regs->free($reg);
                    return;
                }

                // String: ya tiene la dirección guardada en stack
                if ($symbol->type === 'string') {
                    $reg = $this->visit($expr);
                    $this->asm->writeComment("print string var '$name'");
                    $this->asm->writeLine("mov x0, $reg");
                    $this->asm->writeLine("bl print_cstr");
                    $this->regs->free($reg);
                    return;
                }

                if ($symbol->type === 'float32') {
                    $reg = $this->visit($expr);
                    $this->asm->writeComment("print float var '$name'");
                    $this->emitPrintFloatReg($reg);
                    $this->regs->free($reg);
                    return;
                }
            }

            // int32 / rune / float32 -> print_int
            $reg = $this->visit($expr);
            $this->asm->writeComment("print int var '$name'");
            $this->asm->writeLine("mov w0, " . $this->regs->to32($reg));
            $this->asm->writeLine("bl print_int");
            $this->regs->free($reg);
            return;
        }

        // Cualquier otra expresión -> se evalúa y se trata como int.
        $reg = $this->visit($expr);
        if ($reg !== null) {
            $this->asm->writeComment("print expr");
            $this->asm->writeLine("mov w0, " . $this->regs->to32($reg));
            $this->asm->writeLine("bl print_int");
            $this->regs->free($reg);
        }
    }
}
