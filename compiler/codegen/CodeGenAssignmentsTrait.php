<?php

trait CodeGenAssignmentsTrait {
    private function loadDefaultValueForSymbol($symbol): string {
        $reg = $this->regs->allocate();
        $size = $this->getSymbolElementSize($symbol);

        if ($symbol->type === 'string') {
            $this->asm->writeComment("Inicializar string vacio");
            $this->asm->writeLine("adrp $reg, empty_str");
            $this->asm->writeLine("add  $reg, $reg, :lo12:empty_str");
            return $reg;
        }

        if ($size === 8) {
            $this->asm->writeLine("mov $reg, xzr");
            return $reg;
        }

        $this->asm->writeLine("mov " . $this->regs->to32($reg) . ", wzr");
        return $reg;
    }

    private function emitFrameAddress(string $dstReg, int $offset): void {
        if ($offset === 0) {
            $this->asm->writeLine("mov $dstReg, x29");
            return;
        }

        $imm = abs($offset);
        $op = $offset > 0 ? 'add' : 'sub';
        $this->asm->writeLine("$op $dstReg, x29, #$imm");
    }

    private function getSymbolElementSize($symbol): int {
        return SymbolTable::getTypeSize($symbol->type, null, $symbol->isPointer);
    }

    private function storeRegisterInAddress($symbol, string $addrReg, string $valueReg): void {
        $size = $this->getSymbolElementSize($symbol);
        if ($size === 8) {
            $this->asm->writeLine("str $valueReg, [$addrReg]");
            return;
        }

        $this->asm->writeLine("str " . $this->regs->to32($valueReg) . ", [$addrReg]");
    }

    private function storeRegisterInFrameOffset($symbol, int $offset, string $valueReg): void {
        $addrReg = $this->regs->allocate();
        $this->emitFrameAddress($addrReg, $offset);
        $this->storeRegisterInAddress($symbol, $addrReg, $valueReg);
        $this->regs->free($addrReg);
    }

    private function flattenArrayLiteralExprs($ctx): array {
        if ($ctx === null) {
            return [];
        }

        if (method_exists($ctx, 'e') && $ctx->e() !== null) {
            $exprs = $ctx->e();
            if (is_array($exprs)) {
                return $exprs;
            }
            return [$exprs];
        }

        $flat = [];

        if (method_exists($ctx, 'arrayRow') && $ctx->arrayRow() !== null) {
            foreach ($ctx->arrayRow() as $rowCtx) {
                $flat = array_merge($flat, $this->flattenArrayLiteralExprs($rowCtx));
            }
        }

        if (method_exists($ctx, 'arrayContent') && $ctx->arrayContent() !== null) {
            $flat = array_merge($flat, $this->flattenArrayLiteralExprs($ctx->arrayContent()));
        }

        return $flat;
    }

    private function initializeArrayLiteral($symbol, $arrayLitCtx): void {
        $exprs = $this->flattenArrayLiteralExprs($arrayLitCtx);
        $elementSize = $this->getSymbolElementSize($symbol);

        foreach ($exprs as $i => $exprCtx) {
            $reg = $this->visit($exprCtx);
            $offset = $symbol->offset + ($i * $elementSize);
            $this->storeRegisterInFrameOffset($symbol, $offset, $reg);
            $this->regs->free($reg);
        }
    }

    private function zeroInitializeArray($symbol): void {
        $elementSize = $this->getSymbolElementSize($symbol);
        $count = 1;
        foreach ($symbol->arrayDims ?? [] as $dim) {
            $count *= (int) $dim;
        }

        for ($i = 0; $i < $count; $i++) {
            $offset = $symbol->offset + ($i * $elementSize);
            $this->storeRegisterInFrameOffset($symbol, $offset, $elementSize === 8 ? 'xzr' : 'wzr');
        }
    }

    private function computeArrayElementAddress($symbol, array $indexExprs): string {
        $baseReg = $this->regs->allocate();
        
        if ($symbol->isParameter && !empty($symbol->arrayDims)) {
            // Si el arreglo viene por parámetro, el stack guarda el PUNTERO
            $this->asm->writeComment("Cargar puntero base del arreglo parámetro '{$symbol->name}'");
            $this->asm->writeLine("ldr $baseReg, [x29, #{$symbol->offset}]");
        } else {
            // Si es local, calculamos su dirección normal
            $this->emitFrameAddress($baseReg, $symbol->offset);
        }

        $linearReg = $this->visit($indexExprs[0]);
        $linearW = $this->regs->to32($linearReg);
        $dims = $symbol->arrayDims ?? [];

        for ($i = 1; $i < count($indexExprs); $i++) {
            $dimReg = $this->regs->allocate();
            $dimW = $this->regs->to32($dimReg);
            $this->asm->writeLine("mov $dimW, #{$dims[$i]}");
            $this->asm->writeLine("mul $linearW, $linearW, $dimW");
            $this->regs->free($dimReg);

            $idxReg = $this->visit($indexExprs[$i]);
            $idxW = $this->regs->to32($idxReg);
            $this->asm->writeLine("add $linearW, $linearW, $idxW");
            $this->regs->free($idxReg);
        }

        $elementSize = $this->getSymbolElementSize($symbol);
        if ($elementSize > 1) {
            $sizeReg = $this->regs->allocate();
            $sizeW = $this->regs->to32($sizeReg);
            $this->asm->writeLine("mov $sizeW, #$elementSize");
            $this->asm->writeLine("mul $linearW, $linearW, $sizeW");
            $this->regs->free($sizeReg);
        }

        $this->asm->writeLine("add $baseReg, $baseReg, $linearReg");
        $this->regs->free($linearReg);
        return $baseReg;
    }

    //  ASIGNACIONES
    public function visitVarDeclInit($ctx) {
        return $this->handleAssignment($ctx->ID()->getText(), $ctx->e());
    }

    public function visitVarArrayND($ctx) {
        $symbol = $this->getSymbol($ctx->ID()->getText());
        if ($symbol !== null) {
            $this->asm->writeComment("Inicializar arreglo '{$symbol->name}' en cero");
            $this->zeroInitializeArray($symbol);
        }
        return null;
    }

    public function visitVarArrayNDInit($ctx) {
        $symbol = $this->getSymbol($ctx->ID()->getText());
        if ($symbol !== null) {
            $this->asm->writeComment("Inicializar arreglo '{$symbol->name}' con literal");
            $this->initializeArrayLiteral($symbol, $ctx->arrayLit());
        }
        return null;
    }

    public function visitShortVarDecl($ctx) {
        return $this->handleAssignment($ctx->ID()->getText(), $ctx->e());
    }

    public function visitShortVarArrayND($ctx) {
        $symbol = $this->getSymbol($ctx->ID()->getText());
        if ($symbol !== null) {
            $this->asm->writeComment("Inicializar arreglo corto '{$symbol->name}' con literal");
            $this->initializeArrayLiteral($symbol, $ctx->arrayLit());
        }
        return null;
    }

    public function visitAssignStmt($ctx) {
        return $this->handleAssignment($ctx->ID()->getText(), $ctx->e());
    }

    public function visitArrayAssignND($ctx) {
        $name = $ctx->ID()->getText();
        $symbol = $this->getSymbol($name);
        if ($symbol === null) return null;

        $exprs = $ctx->e();
        $valueToStoreCtx = array_pop($exprs); 
        $valueReg = $this->visit($valueToStoreCtx);

      
        $addrReg = $this->computeArrayElementAddress($symbol, $exprs);

       
        $elementSize = $this->getSymbolElementSize($symbol);
        $this->asm->writeComment("Asignar a arreglo '$name'");
        
        if ($elementSize === 8) {
            $this->asm->writeLine("str $valueReg, [$addrReg]");
        } else {
            $this->asm->writeLine("str " . $this->regs->to32($valueReg) . ", [$addrReg]");
        }

        $this->regs->free($valueReg);
        $this->regs->free($addrReg);
        return null;
    }

    public function visitPlusAssignStmt($ctx) {
        return $this->handleCompoundAssign($ctx->ID()->getText(), $ctx->e(), 'add');
    }

    public function visitMinusAssignStmt($ctx) {
        return $this->handleCompoundAssign($ctx->ID()->getText(), $ctx->e(), 'sub');
    }

    public function visitStarAssignStmt($ctx) {
        return $this->handleCompoundAssign($ctx->ID()->getText(), $ctx->e(), 'mul');
    }

    public function visitSlashAssignStmt($ctx) {
        return $this->handleCompoundAssign($ctx->ID()->getText(), $ctx->e(), 'sdiv');
    }

    public function visitIncStmt($ctx) {
        return $this->handleIncDec($ctx->ID()->getText(), true);
    }

    public function visitDecStmt($ctx) {
        return $this->handleIncDec($ctx->ID()->getText(), false);
    }

    private function handleAssignment(string $name, $exprCtx) {
        $symbol = $this->getSymbol($name);
        if ($exprCtx === null || $symbol === null) return null;

        $reg  = $this->visit($exprCtx);
        if ($reg === null) {
            throw new Exception("La expresión asignada a '{$name}' todavía no está soportada en la generación ARM64.");
        }

        $this->asm->writeComment("Guardar '$name' en [x29, {$symbol->offset}]");
        $this->storeRegisterInFrameOffset($symbol, $symbol->offset, $reg);

        $this->regs->free($reg);
        return null;
    }

    private function handleCompoundAssign(string $name, $exprCtx, string $op) {
        $symbol = $this->getSymbol($name);
        if ($exprCtx === null || $symbol === null) return null;

        $left = $this->regs->allocate();
        $leftW = $this->regs->to32($left);
        $this->asm->writeLine("ldr $leftW, [x29, #{$symbol->offset}]");

        $right = $this->visit($exprCtx);
        $rightW = $this->regs->to32($right);
        $res = $this->regs->allocate();
        $resW = $this->regs->to32($res);

        $this->asm->writeLine("$op $resW, $leftW, $rightW");
        $this->asm->writeLine("str $resW, [x29, #{$symbol->offset}]");

        $this->regs->free($left);
        $this->regs->free($right);
        $this->regs->free($res);
        return null;
    }

    private function handleIncDec(string $name, bool $isInc) {
        $symbol = $this->getSymbol($name);
        if ($symbol === null) return null;

        $reg = $this->regs->allocate();
        $wReg = $this->regs->to32($reg);
        $this->asm->writeLine("ldr $wReg, [x29, #{$symbol->offset}]");
        $this->asm->writeLine(($isInc ? "add" : "sub") . " $wReg, $wReg, #1");
        $this->asm->writeLine("str $wReg, [x29, #{$symbol->offset}]");
        $this->regs->free($reg);
        return null;
    }

    public function visitForVarInit($ctx) {
        return $this->handleAssignment($ctx->ID()->getText(), $ctx->e());
    }

    public function visitForShortInit($ctx) {
        return $this->handleAssignment($ctx->ID()->getText(), $ctx->e());
    }

    public function visitForIncPost($ctx) {
        return $this->handleIncDec($ctx->ID()->getText(), true);
    }

    public function visitForDecPost($ctx) {
        return $this->handleIncDec($ctx->ID()->getText(), false);
    }

    public function visitForPlusAssignPost($ctx) {
        return $this->handleCompoundAssign($ctx->ID()->getText(), $ctx->e(), 'add');
    }

    public function visitForMinusAssignPost($ctx) {
        return $this->handleCompoundAssign($ctx->ID()->getText(), $ctx->e(), 'sub');
    }

    public function visitForMulAssignPost($ctx) {
        return $this->handleCompoundAssign($ctx->ID()->getText(), $ctx->e(), 'mul');
    }

    public function visitForDivAssignPost($ctx) {
        return $this->handleCompoundAssign($ctx->ID()->getText(), $ctx->e(), 'sdiv');
    }

    public function visitForAssignPost($ctx) {
        return $this->handleAssignment($ctx->ID()->getText(), $ctx->e());
    }

    public function visitVarDeclEmpty($ctx) {
        $symbol = $this->getSymbol($ctx->ID()->getText());
        if ($symbol === null) {
            return null;
        }

        $reg = $this->loadDefaultValueForSymbol($symbol);
        $this->asm->writeComment("Inicializar '{$symbol->name}' con valor por defecto");
        $this->storeRegisterInFrameOffset($symbol, $symbol->offset, $reg);
        $this->regs->free($reg);
        return null;
    }

    public function visitConstDeclStmt($ctx) {
        return $this->handleAssignment($ctx->ID()->getText(), $ctx->e());
    }

    public function visitVarDeclMulti($ctx) {
        $ids = $ctx->ID();
        $exprs = $ctx->e();

        foreach ($ids as $i => $idNode) {
            if (!isset($exprs[$i])) {
                continue;
            }
            $this->handleAssignment($idNode->getText(), $exprs[$i]);
        }

        return null;
    }
}
