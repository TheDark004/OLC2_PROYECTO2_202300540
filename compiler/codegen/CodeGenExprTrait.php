<?php

trait CodeGenExprTrait {
    //  EXPRESIONES: VARIABLES Y LITERALES
    public function visitIdExpr($ctx) {
        $name   = $ctx->ID()->getText();
        $symbol = $this->getSymbol($name);

        if (!empty($symbol->arrayDims)) {
            $reg = $this->regs->allocate();
            if ($symbol->isParameter) {
                // Si nosotros ya lo recibimos como puntero, pasamos ese mismo puntero
                $this->asm->writeLine("ldr $reg, [x29, #{$symbol->offset}]");
            } else {
                // Si es local nuestro, pasamos su dirección de memoria
                $this->emitFrameAddress($reg, $symbol->offset);
            }
            return $reg;
        }

        // Resto normal de lectura de variables
        // Si es float32, cargarlo en float register
        if ($symbol->type === 'float32') {
            $reg = $this->fregs->allocate();
            $this->asm->writeComment("Leer variable float '$name'");
            $this->asm->writeLine("ldr $reg, [x29, #{$symbol->offset}]");
            return $reg;
        }

        $reg  = $this->regs->allocate();
        $size = SymbolTable::getTypeSize($symbol->type, null, $symbol->isPointer);

        $this->asm->writeComment("Leer '$name'");
        if ($size === 8) {
            $this->asm->writeLine("ldr $reg, [x29, #{$symbol->offset}]");
        } else {
            $this->asm->writeLine("ldr " . $this->regs->to32($reg) . ", [x29, #{$symbol->offset}]");
        }

        return $reg;
    }

    public function visitArrayAccessND($ctx) {
        $name = $ctx->ID()->getText();
        $symbol = $this->getSymbol($name);
        if ($symbol === null) return null;

        $indexExprs = $ctx->e();
        
        $this->asm->writeComment("Lectura de arreglo ND: {$name}");
        
        $baseReg = $this->computeArrayElementAddress($symbol, $indexExprs);

        $valReg = $this->regs->allocate();
        $elementSize = $this->getSymbolElementSize($symbol);

       
        if ($elementSize === 8) {
            $this->asm->writeLine("ldr $valReg, [$baseReg]");
        } else {
            $wReg = $this->regs->to32($valReg);
            $this->asm->writeLine("ldr $wReg, [$baseReg]");
        }

        $this->regs->free($baseReg);
        return $valReg;
    }

    public function visitFloatLit($ctx) {
        $meta = $this->internFloatLiteral($ctx->getText());
        $addrReg = $this->regs->allocate();
        $tmpInt = $this->regs->allocate();
        $floatReg = $this->fregs->allocate();

        $this->asm->writeComment("Cargar float32 literal " . $ctx->getText());
        $this->asm->writeLine("adrp $addrReg, {$meta['bitsLabel']}");
        $this->asm->writeLine("add  $addrReg, $addrReg, :lo12:{$meta['bitsLabel']}");
        $this->asm->writeLine("ldr " . $this->regs->to32($tmpInt) . ", [$addrReg]");
        $this->asm->writeLine("fmov $floatReg, " . $this->regs->to32($tmpInt));

        $this->regs->free($addrReg);
        $this->regs->free($tmpInt);
        return $floatReg;
    }

    private function getExprType($ctx): string {
        if ($ctx === null) {
            return 'unknown';
        }

        $class = get_class($ctx);

        if (str_ends_with($class, 'IntLitContext')) {
            return 'int32';
        }
        if (str_ends_with($class, 'FloatLitContext')) {
            return 'float32';
        }
        if (str_ends_with($class, 'BoolLitContext')) {
            return 'bool';
        }
        if (str_ends_with($class, 'StringLitContext')) {
            return 'string';
        }
        if (str_ends_with($class, 'RuneLitContext')) {
            return 'rune';
        }
        if (str_ends_with($class, 'NilLitContext')) {
            return 'nil';
        }

        if (str_ends_with($class, 'IdExprContext')) {
            $symbol = $this->getSymbol($ctx->ID()->getText());
            if ($symbol === null) {
                return 'unknown';
            }
            if (!empty($symbol->arrayDims)) {
                return '*' . $symbol->type;
            }
            if ($symbol->isPointer) {
                return '*' . $symbol->type;
            }
            return $symbol->type;
        }

        if (str_ends_with($class, 'ArrayAccessNDContext')) {
            $name = $ctx->ID()->getText();
            $symbol = $this->getSymbol($name);
            if ($symbol === null) {
                return 'unknown';
            }
            return $symbol->type;
        }

        if (str_ends_with($class, 'RefExprContext')) {
            $symbol = $this->getSymbol($ctx->ID()->getText());
            if ($symbol === null) {
                return 'unknown';
            }
            return '*' . $symbol->type;
        }

        if (str_ends_with($class, 'DerefExprContext')) {
            $symbol = $this->getSymbol($ctx->ID()->getText());
            if ($symbol === null) {
                return 'unknown';
            }
            return $symbol->type;
        }

        if (str_ends_with($class, 'FuncCallExprContext')) {
            $name = $ctx->ID()->getText();
            
            // Detectar builtins
            if (in_array($name, ['len', 'now'], true)) {
                return 'int32';
            }
            if (in_array($name, ['typeof', 'substr'], true)) {
                return 'string';
            }
            
            // Búsqueda en funciones custom
            $sig = $this->functionSignatures[$name] ?? null;
            if ($sig !== null && count($sig['returns']) > 0) {
                return $sig['returns'][0];
            }
            return 'int32';
        }

        if (str_ends_with($class, 'GroupExprContext')) {
            return $this->getExprType($ctx->e());
        }

        if (str_ends_with($class, 'NegExprContext')) {
            return $this->getExprType($ctx->e());
        }

        if (str_ends_with($class, 'NotExprContext')) {
            return 'bool';
        }

        if (str_ends_with($class, 'AddExprContext') || str_ends_with($class, 'MulExprContext') || str_ends_with($class, 'RelExprContext') || str_ends_with($class, 'EqExprContext') || str_ends_with($class, 'AndExprContext') || str_ends_with($class, 'OrExprContext')) {
            $leftType = $this->getExprType($ctx->e(0));
            $rightType = $this->getExprType($ctx->e(1));
            if ($ctx instanceof \Context\RelExprContext || $ctx instanceof \Context\EqExprContext || $ctx instanceof \Context\AndExprContext || $ctx instanceof \Context\OrExprContext) {
                return 'bool';
            }
            if ($leftType === 'float32' || $rightType === 'float32') {
                return 'float32';
            }
            if ($leftType !== 'unknown') {
                return $leftType;
            }
            return $rightType;
        }

        return 'unknown';
    }

    private function isFloatExpr($ctx): bool {
        return $this->getExprType($ctx) === 'float32';
    }

    public function visitRuneLit($ctx) {
        $raw = $ctx->getText();
        $content = substr($raw, 1, -1);

        $value = match ($content) {
            "\\n" => 10,
            "\\t" => 9,
            "\\r" => 13,
            "\\\\" => 92,
            "\\'" => 39,
            default => ord($content[0] ?? "\0"),
        };

        $reg = $this->regs->allocate();
        $this->asm->writeLine("mov " . $this->regs->to32($reg) . ", #$value");
        return $reg;
    }

    public function visitIntLit($ctx) {
        $reg = $this->regs->allocate();
        $this->asm->writeLine("mov " . $this->regs->to32($reg) . ", #" . $ctx->getText());
        return $reg;
    }

    public function visitNilLit($ctx) {
        $reg = $this->regs->allocate();
        $this->asm->writeLine("mov " . $this->regs->to32($reg) . ", wzr");
        return $reg;
    }

    /**
     * Bool literal en contexto de expresión
     * true -> 1, false -> 0 en registro.
     */
    public function visitBoolLit($ctx) {
        $reg = $this->regs->allocate();
        $val = $ctx->getText() === 'true' ? 1 : 0;
        $this->asm->writeLine("mov " . $this->regs->to32($reg) . ", #$val");
        return $reg;
    }

    // String literal en contexto de expresión.
    public function visitStringLit($ctx) {
        $raw   = $ctx->getText();
        $value = substr($raw, 1, strlen($raw) - 2);
        $label = $this->internString($value);

        $reg = $this->regs->allocate();
        $this->asm->writeComment("Dirección de string literal");
        
        $this->asm->writeLine("adrp $reg, $label");
        $this->asm->writeLine("add  $reg, $reg, :lo12:$label");
        return $reg;
    }

    //  EXPRESIONES: ARITMÉTICA
    public function visitAddExpr($ctx) {
        $l   = $this->visit($ctx->e(0));
        $r   = $this->visit($ctx->e(1));
        
        $isLFloat = str_starts_with($l, 's') || str_starts_with($l, 'd');
        $isRFloat = str_starts_with($r, 's') || str_starts_with($r, 'd');

        if ($isLFloat || $isRFloat) {
            $res = $this->fregs->allocate();
            $fpOp = $ctx->op->getText() === '+' ? 'fadd' : 'fsub';

            $sL = $l;
            $sR = $r;
            $freeL = false;
            $freeR = false;

            if ($isLFloat && !$isRFloat) {
                $sR = $this->fregs->allocate();
                $this->asm->writeLine("scvtf $sR, " . $this->regs->to32($r));
                $freeR = true;
            } else if (!$isLFloat && $isRFloat) {
                $sL = $this->fregs->allocate();
                $this->asm->writeLine("scvtf $sL, " . $this->regs->to32($l));
                $freeL = true;
            }

            $this->asm->writeLine("$fpOp $res, $sL, $sR");

            if ($freeL) $this->fregs->free($sL);
            if ($freeR) $this->fregs->free($sR);
            
            if ($isLFloat) $this->fregs->free($l); else $this->regs->free($l);
            if ($isRFloat) $this->fregs->free($r); else $this->regs->free($r);

            return $res;
        }

        $res = $this->regs->allocate();
        $op  = $ctx->op->getText() === '+' ? 'add' : 'sub';
        $this->asm->writeLine("$op " . $this->regs->to32($res) . ", " . $this->regs->to32($l) . ", " . $this->regs->to32($r));

        $this->regs->free($l);
        $this->regs->free($r);
        return $res;
    }

    public function visitMulExpr($ctx) {
        $l   = $this->visit($ctx->e(0));
        $r   = $this->visit($ctx->e(1));
        
        $isLFloat = str_starts_with($l, 's') || str_starts_with($l, 'd');
        $isRFloat = str_starts_with($r, 's') || str_starts_with($r, 'd');

        if ($isLFloat || $isRFloat) {
            $res = $this->fregs->allocate();
            $op  = $ctx->op->getText();
            $fpOp = $op === '*' ? 'fmul' : 'fdiv';
            
            $sL = $l;
            $sR = $r;
            $freeL = false;
            $freeR = false;

            if ($isLFloat && !$isRFloat) {
                $sR = $this->fregs->allocate();
                $this->asm->writeLine("scvtf $sR, " . $this->regs->to32($r));
                $freeR = true;
            } else if (!$isLFloat && $isRFloat) {
                $sL = $this->fregs->allocate();
                $this->asm->writeLine("scvtf $sL, " . $this->regs->to32($l));
                $freeL = true;
            }

            $this->asm->writeLine("$fpOp $res, $sL, $sR");

            if ($freeL) $this->fregs->free($sL);
            if ($freeR) $this->fregs->free($sR);
            
            if ($isLFloat) $this->fregs->free($l); else $this->regs->free($l);
            if ($isRFloat) $this->fregs->free($r); else $this->regs->free($r);

            return $res;
        }

        // Integer multiplication
        $res = $this->regs->allocate();
        $op  = $ctx->op->getText();
        
        if ($op === '*') {
            $this->asm->writeLine("mul " . $this->regs->to32($res) . ", " . $this->regs->to32($l) . ", " . $this->regs->to32($r));
        } elseif ($op === '/') {
            $this->asm->writeLine("sdiv " . $this->regs->to32($res) . ", " . $this->regs->to32($l) . ", " . $this->regs->to32($r));
        } elseif ($op === '%') {
            $tmp = $this->regs->allocate();
            $this->asm->writeLine("sdiv " . $this->regs->to32($tmp) . ", " . $this->regs->to32($l) . ", " . $this->regs->to32($r));
            $this->asm->writeLine("msub " . $this->regs->to32($res) . ", " . $this->regs->to32($tmp) . ", " . $this->regs->to32($r) . ", " . $this->regs->to32($l));
            $this->regs->free($tmp);
        }

        $this->regs->free($l);
        $this->regs->free($r);
        return $res;
    }

    // EXPRESIONES: COMPARACIONES Y LÓGICA
    public function visitRelExpr($ctx) {
        $l   = $this->visit($ctx->e(0));
        $r   = $this->visit($ctx->e(1));
        $res = $this->regs->allocate();

        $this->asm->writeComment("Comparación: " . $ctx->getText());
        
        // Detectar si los registros son flotantes
        $isLFloat = str_starts_with($l, 's') || str_starts_with($l, 'd');
        $isRFloat = str_starts_with($r, 's') || str_starts_with($r, 'd');

        if ($isLFloat || $isRFloat) {
            $sL = $l;
            $sR = $r;
            $freeL = false;
            $freeR = false;

            if ($isLFloat && !$isRFloat) {
                $sR = $this->fregs->allocate();
                $this->asm->writeLine("scvtf $sR, " . $this->regs->to32($r));
                $freeR = true;
            } else if (!$isLFloat && $isRFloat) {
                $sL = $this->fregs->allocate();
                $this->asm->writeLine("scvtf $sL, " . $this->regs->to32($l));
                $freeL = true;
            }

            $this->asm->writeLine("fcmp $sL, $sR");

            if ($freeL) $this->fregs->free($sL);
            if ($freeR) $this->fregs->free($sR);
        } else {
            $this->asm->writeLine("cmp " . $this->regs->to32($l) . ", " . $this->regs->to32($r));
        }

        $cond = match($ctx->op->getText()) {
            '<'  => 'lt',
            '>'  => 'gt',
            '<=' => 'le',
            '>=' => 'ge',
            '==' => 'eq',
            '!=' => 'ne',
            default => 'eq'
        };
        $this->asm->writeLine("cset " . $this->regs->to32($res) . ", $cond");

        if ($isLFloat) $this->fregs->free($l); else $this->regs->free($l);
        if ($isRFloat) $this->fregs->free($r); else $this->regs->free($r);

        return $res;
    }

    public function visitEqExpr($ctx) {
        $l   = $this->visit($ctx->e(0));
        $r   = $this->visit($ctx->e(1));
        $res = $this->regs->allocate();

        $this->asm->writeComment("Comparación de igualdad: " . $ctx->getText());
        
        // Detección dinámica de flotantes
        $isLFloat = str_starts_with($l, 's') || str_starts_with($l, 'd');
        $isRFloat = str_starts_with($r, 's') || str_starts_with($r, 'd');

        if ($isLFloat || $isRFloat) {
            $sL = $l;
            $sR = $r;
            $freeL = false;
            $freeR = false;

            // Si chocan un float y un entero, convertimos el entero a float
            if ($isLFloat && !$isRFloat) {
                $sR = $this->fregs->allocate();
                $this->asm->writeLine("scvtf $sR, " . $this->regs->to32($r));
                $freeR = true;
            } else if (!$isLFloat && $isRFloat) {
                $sL = $this->fregs->allocate();
                $this->asm->writeLine("scvtf $sL, " . $this->regs->to32($l));
                $freeL = true;
            }

            // Usamos la comparación exclusiva de flotantes
            $this->asm->writeLine("fcmp $sL, $sR");

            if ($freeL) $this->fregs->free($sL);
            if ($freeR) $this->fregs->free($sR);
        } else {
            // Si ambos son enteros, usamos la comparación normal
            $this->asm->writeLine("cmp " . $this->regs->to32($l) . ", " . $this->regs->to32($r));
        }

        // Determinar si es == o !=
        $cond = $ctx->op->getText() === '==' ? 'eq' : 'ne';
        $this->asm->writeLine("cset " . $this->regs->to32($res) . ", $cond");

        // Liberar registros originales
        if ($isLFloat) $this->fregs->free($l); else $this->regs->free($l);
        if ($isRFloat) $this->fregs->free($r); else $this->regs->free($r);

        return $res;
    }

    // AND con cortocircuito:
    // Si el LI es false (0), salta sin evaluar el derecho.
    public function visitAndExpr($ctx) {
        $l          = $this->visit($ctx->e(0));
        $res        = $this->regs->allocate();
        $labelFalse = $this->labels->newLabel("AND_F");
        $labelEnd   = $this->labels->newLabel("AND_E");

        $this->asm->writeComment("AND con cortocircuito");
        $this->asm->writeLine("cmp " . $this->regs->to32($l) . ", #0");
        $this->asm->writeLine("b.eq $labelFalse");

        $r = $this->visit($ctx->e(1));
        $this->asm->writeLine("cmp " . $this->regs->to32($r) . ", #0");
        $this->asm->writeLine("b.eq $labelFalse");

        $this->asm->writeLine("mov " . $this->regs->to32($res) . ", #1");
        $this->asm->writeLine("b $labelEnd");
        $this->asm->writeLabel($labelFalse);
        $this->asm->writeLine("mov " . $this->regs->to32($res) . ", #0");
        $this->asm->writeLabel($labelEnd);

        $this->regs->free($l);
        if (isset($r)) $this->regs->free($r);
        return $res;
    }

    /**
     * OR con cortocircuito:
     * Si el lado izquierdo es true (!= 0), salta sin evaluar el derecho.
     */
    public function visitOrExpr($ctx) {
        $l         = $this->visit($ctx->e(0));
        $res       = $this->regs->allocate();
        $labelTrue = $this->labels->newLabel("OR_T");
        $labelEnd  = $this->labels->newLabel("OR_E");

        $this->asm->writeComment("OR con cortocircuito");
        $this->asm->writeLine("cmp " . $this->regs->to32($l) . ", #0");
        $this->asm->writeLine("b.ne $labelTrue");

        $r = $this->visit($ctx->e(1));
        $this->asm->writeLine("cmp " . $this->regs->to32($r) . ", #0");
        $this->asm->writeLine("b.ne $labelTrue");

        $this->asm->writeLine("mov " . $this->regs->to32($res) . ", #0");
        $this->asm->writeLine("b $labelEnd");
        $this->asm->writeLabel($labelTrue);
        $this->asm->writeLine("mov " . $this->regs->to32($res) . ", #1");
        $this->asm->writeLabel($labelEnd);

        $this->regs->free($l);
        if (isset($r)) $this->regs->free($r);
        return $res;
    }

    public function visitNotExpr($ctx) {
        $reg = $this->visit($ctx->e());
        // NOT lógico: cmp con 0, cset eq -> 1 si era 0, 0 si era != 0
        $this->asm->writeLine("cmp "  . $this->regs->to32($reg) . ", #0");
        $this->asm->writeLine("cset " . $this->regs->to32($reg) . ", eq");
        return $reg;
    }

    public function visitNegExpr($ctx) {
        $reg = $this->visit($ctx->e());
        if ($this->isFloatExpr($ctx)) {
            $this->asm->writeLine("fneg s" . substr($reg, 1) . ", s" . substr($reg, 1));
        } else {
            $this->asm->writeLine("neg " . $this->regs->to32($reg) . ", " . $this->regs->to32($reg));
        }
        return $reg;
    }

    public function visitGroupExpr($ctx) {
        return $this->visit($ctx->e());
    }

    public function visitCastFloat32($ctx) {
        // En "float32(suma)", esto visita "suma" y devuelve su registro
        return $this->visit($ctx->e());
    }

    public function visitCastInt32($ctx) {
        return $this->visit($ctx->e());
    }

    public function visitCastInt($ctx) {
        return $this->visit($ctx->e());
    }

    public function visitCastBool($ctx) {
        return $this->visit($ctx->e());
    }

    public function visitCastString($ctx) {
        return $this->visit($ctx->e());
    }

    public function visitCastRune($ctx) {
        return $this->visit($ctx->e());
    }

    public function visitRefExpr($ctx) {
        $name = $ctx->ID()->getText();
        $symbol = $this->getSymbol($name);
        if ($symbol === null) return null;

        $reg = $this->regs->allocate();
        $this->asm->writeComment("Obtener dirección de memoria (&) de '{$name}'");
        
       
        $this->emitFrameAddress($reg, $symbol->offset);
        
        return $reg;
    }

    public function visitDerefExpr($ctx) {
        $name = $ctx->ID()->getText();
        $symbol = $this->getSymbol($name);
        if ($symbol === null) return null;

        $this->asm->writeComment("Leer valor a través del puntero (*) '{$name}'");

        $ptrReg = $this->regs->allocate();
        $this->asm->writeLine("ldr $ptrReg, [x29, #{$symbol->offset}]");

        $valReg = $this->regs->allocate();
        $elementSize = $this->getSymbolElementSize($symbol);
        
        if ($elementSize === 8) {
            $this->asm->writeLine("ldr $valReg, [$ptrReg]");
        } else {
        
            $wReg = $this->regs->to32($valReg);
            $this->asm->writeLine("ldr $wReg, [$ptrReg]");
        }

        $this->regs->free($ptrReg);
        return $valReg;
    }

    public function visitTernaryExpr($ctx) {
        $this->asm->writeComment("--- Operador Ternario (cond ? e1 : e2) ---");
        
        
        $condReg = $this->visit($ctx->e(0));
        $condW = $this->regs->to32($condReg);

        $falseLabel = $this->labels->newLabel("TERNARY_FALSE");
        $endLabel = $this->labels->newLabel("TERNARY_END");

        $this->asm->writeLine("cmp $condW, #0");
        $this->regs->free($condReg); 
        
       
        $this->asm->writeLine("b.eq $falseLabel");

        
        $resReg1 = $this->visit($ctx->e(1));
        
        
        $isFloat = str_starts_with($resReg1, 's') || str_starts_with($resReg1, 'd');
        $finalReg = $isFloat ? $this->fregs->allocate() : $this->regs->allocate();

        if ($isFloat) {
            $this->asm->writeLine("fmov $finalReg, $resReg1");
            $this->fregs->free($resReg1);
        } else {
            $this->asm->writeLine("mov $finalReg, $resReg1");
            $this->regs->free($resReg1);
        }
        
    
        $this->asm->writeLine("b $endLabel");

        
        $this->asm->writeLabel($falseLabel);
        $resReg2 = $this->visit($ctx->e(2));
        
        if ($isFloat) {
            $this->asm->writeLine("fmov $finalReg, $resReg2");
            $this->fregs->free($resReg2);
        } else {
            $this->asm->writeLine("mov $finalReg, $resReg2");
            $this->regs->free($resReg2);
        }

       
        $this->asm->writeLabel($endLabel);

        return $finalReg;
    }
}
