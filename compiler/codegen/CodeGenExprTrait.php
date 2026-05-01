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
        if ($symbol === null) {
            return null;
        }

        $addrReg = $this->computeArrayElementAddress($symbol, $ctx->e()); // se obtiene el registro que contiene en memoria

        $valueReg = $this->regs->allocate(); // se prepara para guardar el valor 
        $elementSize = SymbolTable::getTypeSize($symbol->type, null, $symbol->isPointer);

        $this->asm->writeComment("Leer arreglo '$name'");
        
        if ($elementSize === 8) {
            $this->asm->writeLine("ldr $valueReg, [$addrReg]");
        } else {
            $this->asm->writeLine("ldr " . $this->regs->to32($valueReg) . ", [$addrReg]");
        }

        $this->regs->free($addrReg);
        return $valueReg;
    }

    public function visitIntLit($ctx) {
        $reg = $this->regs->allocate();
        $this->asm->writeLine("mov " . $this->regs->to32($reg) . ", #" . $ctx->getText());
        return $reg;
    }

    public function visitFloatLit($ctx) {
        $meta = $this->internFloatLiteral($ctx->getText());
        $addrReg = $this->regs->allocate();
        $reg = $this->regs->allocate();

        $this->asm->writeComment("Cargar float32 literal " . $ctx->getText());
        $this->asm->writeLine("adrp $addrReg, {$meta['bitsLabel']}");
        $this->asm->writeLine("add  $addrReg, $addrReg, :lo12:{$meta['bitsLabel']}");
        $this->asm->writeLine("ldr " . $this->regs->to32($reg) . ", [$addrReg]");

        $this->regs->free($addrReg);
        return $reg;
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
        $res = $this->regs->allocate();
        $op  = $ctx->op->getText();

        if ($op === '*') {
            $this->asm->writeLine("mul " . $this->regs->to32($res) . ", " . $this->regs->to32($l) . ", " . $this->regs->to32($r));
        } elseif ($op === '/') {
            // sdiv = signed divide, necesario para int32 con signo
            $this->asm->writeLine("sdiv " . $this->regs->to32($res) . ", " . $this->regs->to32($l) . ", " . $this->regs->to32($r));
        } elseif ($op === '%') {
            // res = l - (l / r) * r  ->  sdiv tmp, l, r; msub res, tmp, r, l
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
        $this->asm->writeLine("cmp " . $this->regs->to32($l) . ", " . $this->regs->to32($r));

        $cond = match($ctx->op->getText()) {
            '<'  => 'lt',
            '>'  => 'gt',
            '<=' => 'le',
            '>=' => 'ge',
            '==' => 'eq',
            '!=' => 'ne',
            default => 'eq'
        };
        // 1 si condición verdadera, 0 si falsa
        $this->asm->writeLine("cset " . $this->regs->to32($res) . ", $cond");

        $this->regs->free($l);
        $this->regs->free($r);
        return $res;
    }

    public function visitEqExpr($ctx) {
        return $this->visitRelExpr($ctx);
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
        // neg = 0 - reg
        $this->asm->writeLine("neg " . $this->regs->to32($reg) . ", " . $this->regs->to32($reg));
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

}
