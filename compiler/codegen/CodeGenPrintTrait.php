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
        $this->asm->writeComment("--- Print float ---");
        $this->emitSaveVolatiles();
        
        $isFloatReg = str_starts_with($reg, 's') || str_starts_with($reg, 'd');
        $endLabel = $this->labels->newLabel('FLT_END');

        
        if ($isFloatReg) {
            foreach ($this->floatLiterals as $meta) {
                $nextLabel = $this->labels->newLabel('FLT_NEXT');
                $tmpAddr = $this->regs->allocate();
                $tmpVal = $this->regs->allocate();
                $tmpFloat = $this->fregs->allocate();

                $this->asm->writeLine("adrp $tmpAddr, {$meta['bitsLabel']}");
                $this->asm->writeLine("add  $tmpAddr, $tmpAddr, :lo12:{$meta['bitsLabel']}");
                $this->asm->writeLine("ldr " . $this->regs->to32($tmpVal) . ", [$tmpAddr]");
                $this->asm->writeLine("fmov $tmpFloat, " . $this->regs->to32($tmpVal));
                $this->asm->writeLine("fcmp $reg, $tmpFloat");
                $this->asm->writeLine("b.ne $nextLabel");
                
                
                $this->emitWriteLabel($meta['strLabel'], strlen($meta['display']));
                $this->regs->free($tmpAddr);
                $this->regs->free($tmpVal);
                $this->fregs->free($tmpFloat);
                $this->asm->writeLine("b $endLabel");
                
           
                $this->asm->writeLabel($nextLabel);
                $this->regs->free($tmpAddr);
                $this->regs->free($tmpVal);
                $this->fregs->free($tmpFloat);
            }
        } else {
            $wReg = $this->regs->to32($reg);
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
                $this->regs->free($tmpAddr);
                $this->regs->free($tmpVal);
                $this->asm->writeLine("b $endLabel");
                
           
                $this->asm->writeLabel($nextLabel);
                $this->regs->free($tmpAddr);
                $this->regs->free($tmpVal);
            }
        }

        
        if ($reg !== 's0') {
            if ($isFloatReg) {
                $this->asm->writeLine("fmov s0, $reg");
            } else {
                $this->asm->writeLine("fmov s0, " . $this->regs->to32($reg));
            }
        }

        $this->asm->writeLine("fcvtzs w20, s0"); 

        $lPos = $this->labels->newLabel('FLOAT_POS');
        $this->asm->writeLine("fcmp s0, #0.0");
        $this->asm->writeLine("b.ge $lPos");
        $this->asm->writeLine("cmp w20, #0");
        $this->asm->writeLine("b.ne $lPos");
        
        $this->asm->writeLine("mov w0, #45"); 
        $this->asm->writeLine("str w0, [sp, -16]!");
        $this->asm->writeLine("mov x0, #1");
        $this->asm->writeLine("mov x1, sp");
        $this->asm->writeLine("mov x2, #1");
        $this->asm->writeLine("mov x8, #64");
        $this->asm->writeLine("svc #0");
        $this->asm->writeLine("add sp, sp, 16");
        
        $this->asm->writeLabel($lPos);

        $this->asm->writeLine("mov w0, w20");
        $this->asm->writeLine("bl print_int");
        
        $this->asm->writeLine("mov w0, #46");
        $this->asm->writeLine("str w0, [sp, -16]!");
        $this->asm->writeLine("mov x0, #1");
        $this->asm->writeLine("mov x1, sp");
        $this->asm->writeLine("mov x2, #1");
        $this->asm->writeLine("mov x8, #64");
        $this->asm->writeLine("svc #0");
        $this->asm->writeLine("add sp, sp, 16");

        $this->asm->writeLine("scvtf s1, w20");
        $this->asm->writeLine("fsub s0, s0, s1");
        $this->asm->writeLine("fabs s0, s0");
        
        $this->asm->writeLine("mov w21, #0x0000");
        $this->asm->writeLine("movk w21, #0x4120, lsl #16");
        $this->asm->writeLine("fmov s2, w21");

        for ($i = 0; $i < 4; $i++) {
            $this->asm->writeLine("fmul s0, s0, s2"); 
            $this->asm->writeLine("fcvtzs w20, s0");   

            $this->asm->writeLine("str s0, [sp, -16]!");
            $this->asm->writeLine("str s2, [sp, -16]!");
            
            $this->asm->writeLine("mov w0, w20");
            $this->asm->writeLine("bl print_int");
            
            $this->asm->writeLine("ldr s2, [sp], 16");
            $this->asm->writeLine("ldr s0, [sp], 16");

            $this->asm->writeLine("scvtf s1, w20");
            $this->asm->writeLine("fsub s0, s0, s1"); 
        }

        $this->asm->writeLabel($endLabel);
        $this->emitRestoreVolatiles();
    }

    private function emitSaveVolatiles(): void {
        $this->asm->writeComment("Guardar registros volatiles (Evitar Segfault)");
        
        for ($i = 1; $i <= 15; $i++) {
            
            $this->asm->writeLine("str x$i, [sp, -16]!"); 
            $this->asm->writeLine("str s$i, [sp, -16]!");
        }
    }

    private function emitRestoreVolatiles(): void {
        $this->asm->writeComment("Restaurar registros volatiles");
        for ($i = 15; $i >= 1; $i--) {
            // Restaurar en orden inverso
            $this->asm->writeLine("ldr s$i, [sp], 16");
            $this->asm->writeLine("ldr x$i, [sp], 16");
        }
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


    private function freeAnyReg(string $reg): void {
        if ($reg === null) return;
        if (str_starts_with($reg, 's') || str_starts_with($reg, 'd')) {
            $this->fregs->free($reg);
        } else {
            $this->regs->free($reg);
        }
    }

    // Emite código de impresión para UN argumento de Println.
    private function emitPrintExpr($expr): void {
        $exprText = method_exists($expr, 'getText') ? $expr->getText() : '';
        $class = get_class($expr); 

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
                $this->emitWriteLabel('str_true', 4);
            } else {
                $this->emitWriteLabel('str_false', 5);
            }
            return;
        }

        if ($exprText === 'nil') {
            $this->emitWriteLabel('str_nil', 5);
            return;
        }

        if ($this->isFloatLiteralText($exprText) || $this->getExprType($expr) === 'float32') {
            $reg = $this->visit($expr);
            $this->emitPrintFloatReg($reg);
            
            
            $this->freeAnyReg($reg);
            return;
        }

        if ($this->isBoolExpr($expr)) {
            $reg = $this->visit($expr);
            $this->emitPrintBoolReg($reg);
            $this->freeAnyReg($reg);
            return;
        }

     
        if (str_ends_with($class, 'IdExprContext')) {
            $name   = $expr->ID()->getText();
            $symbol = $this->getSymbol($name);

            if ($symbol !== null) {
                if ($symbol->type === 'bool') {
                    $reg = $this->visit($expr);
                    $this->emitPrintBoolReg($reg);
                    $this->freeAnyReg($reg);
                    return;
                }
                if ($symbol->type === 'string') {
                    $reg = $this->visit($expr);
                    $this->asm->writeLine("mov x0, $reg"); 
                    $this->emitSaveVolatiles();   
                    $this->asm->writeLine("bl print_cstr");
                    $this->emitRestoreVolatiles(); 
                    $this->freeAnyReg($reg);
                    return;
                }
                if ($symbol->type === 'float32') {
                    $reg = $this->visit($expr);
                    $this->emitPrintFloatReg($reg);
                    
                    
                    $this->freeAnyReg($reg);
                    return;
                }
            }

            // int32 / rune / fallback
            $reg = $this->visit($expr);
            $this->asm->writeComment("print int var '$name'");
            $this->asm->writeLine("mov w0, " . $this->regs->to32($reg));
            $this->emitSaveVolatiles();   
            $this->asm->writeLine("bl print_int");
            $this->emitRestoreVolatiles(); 
            $this->freeAnyReg($reg);
            return;
        }

      
        $reg = $this->visit($expr);
        
        if ($reg !== null) {
            $exprType = $this->getExprType($expr);
            
            // Forzamos "string" por si falla el getExprType
            if (str_starts_with($exprText, 'typeof(') || 
                str_starts_with($exprText, 'now(') || 
                str_starts_with($exprText, 'substr(')) {
                $exprType = 'string';
            }

            if ($exprType === 'string') {
                $this->asm->writeComment("print string expr/func");
                // Usamos $reg directamente
                $this->asm->writeLine("mov x0, $reg");
                $this->emitSaveVolatiles();   
                $this->asm->writeLine("bl print_cstr");
                $this->emitRestoreVolatiles();
            } else {
                $this->asm->writeComment("print int expr/func");
                // Para los enteros seguimos usando to32 como tú lo tenías
                $this->asm->writeLine("mov w0, " . $this->regs->to32($reg));
                $this->emitSaveVolatiles();   
                $this->asm->writeLine("bl print_int");
                $this->emitRestoreVolatiles(); 
            }
            
            $this->freeAnyReg($reg);
        }
    }
}
