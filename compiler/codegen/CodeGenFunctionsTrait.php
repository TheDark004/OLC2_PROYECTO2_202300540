<?php

trait CodeGenFunctionsTrait {

    // Stack de contexto de función actual
    // Permite a 'return' saber en qué función está y si tiene valor de retorno
    private array $functionStack = [];

    //  DECLARACIÓN DE FUNCIÓN VOID
    //  func suma() { ... }

    public function visitFuncDeclVoid($ctx) {
        $name = $ctx->ID()->getText();
        $asmName = ($name === 'main') ? '_start' : $name;

        $this->emitFunctionPrologue($ctx, $asmName, false);
        return null;
    }

    //  DECLARACIÓN DE FUNCIÓN CON RETORNO SIMPLE
    //  func suma(a int32, b int32) int32 { ... }
    public function visitFuncDeclReturn($ctx) {
        $name    = $ctx->ID()->getText();
        $asmName = ($name === 'main') ? '_start' : $name;

        $this->emitFunctionPrologue($ctx, $asmName, true, false);
        return null;
    }

    //  DECLARACIÓN DE FUNCIÓN CON MÚLTIPLES RETORNOS
    //  func div(a int32, b int32) (int32, bool) { ... }
    public function visitFuncDeclMultiReturn($ctx) {
        $name    = $ctx->ID()->getText();
        $asmName = ($name === 'main') ? '_start' : $name;

        $this->emitFunctionPrologue($ctx, $asmName, true, true);
        return null;
    }

    // Emite prólogo, cuerpo y epílogo de cualquier función
    private function emitFunctionPrologue($ctx, string $asmName, bool $hasReturn, bool $multiReturn = false): void {
        $funcName = $ctx->ID()->getText();
        $endLabel = $this->labels->newLabel("FUNC_END_{$asmName}");

        $previousFunctionScope = $this->currentFunctionScope ?? null;
        $this->currentFunctionScope = $this->symbolTable->findScopeByName($funcName);

        $this->functionStack[] = [
            'name'        => $asmName,
            'endLabel'    => $endLabel,
            'hasReturn'   => $hasReturn,
            'multiReturn' => $multiReturn,
        ];

        $this->asm->writeLabel($asmName);
        $this->asm->writeComment("Prologo de $asmName");

        $stackSize = $this->symbolTable->functionsStackSize[$funcName] ?? 0;

        $this->asm->writeLine("stp x29, x30, [sp, #-16]!");
        $this->asm->writeLine("mov x29, sp");
        if ($stackSize > 0) {
            $this->asm->writeLine("sub sp, sp, #$stackSize");
        }

        $params = $ctx->param() ?? [];
        foreach ($params as $i => $paramCtx) {
            $paramName = $paramCtx->ID()->getText();
            $symbol    = $this->getSymbol($paramName);

            if ($symbol === null) continue;

            $srcReg = "x$i";   
            $dstW   = "w$i";   

            $is64Bit = !empty($symbol->arrayDims) || 
                       (property_exists($symbol, 'isPointer') && $symbol->isPointer) || 
                       $symbol->type === 'string' || 
                       str_contains((string)$symbol->type, '*') || 
                       str_contains((string)$symbol->type, '[');

            if ($symbol->type === 'float32' || $symbol->type === 'float64') {
                $this->asm->writeComment("Param '$paramName' float (s$i → [x29,{$symbol->offset}])");
                $this->asm->writeLine("str s$i, [x29, #{$symbol->offset}]");
            } else if ($is64Bit) {
                $this->asm->writeComment("Param '$paramName' 64-bit (x$i → [x29,{$symbol->offset}])");
                $this->asm->writeLine("str $srcReg, [x29, #{$symbol->offset}]");
            } else {
                $this->asm->writeComment("Param '$paramName' 32-bit (w$i → [x29,{$symbol->offset}])");
                $this->asm->writeLine("str $dstW, [x29, #{$symbol->offset}]");
            }
        }

        $this->visit($ctx->block());

        $this->asm->writeLabel($endLabel);
        $this->asm->writeComment("Epilogo de $asmName");
        if ($stackSize > 0) {
            $this->asm->writeLine("add sp, sp, #$stackSize");
        }
        $this->asm->writeLine("ldp x29, x30, [sp], #16");

        if ($asmName === '_start') {
            $this->asm->writeComment("Syscall exit(0)");
            $this->asm->writeLine("mov x0, #0");
            $this->asm->writeLine("mov x8, #93");
            $this->asm->writeLine("svc #0");
        } else {
            $this->asm->writeLine("ret");
        }
        array_pop($this->functionStack);
        $this->currentFunctionScope = $previousFunctionScope;
    }

    //  RETURN
    //  return            -> salta al epilogo (void)
    //  return expr       -> valor en x0 (o s0 para floats), luego epilogo
    //  return e1, e2     -> e1 en x0, e2 en x1, luego epilogo
    

    public function visitReturnStmt($ctx) {
        $exprs = $ctx->e() ?? [];
        
        $this->asm->writeComment("Retorno de funcion");
        
        foreach ($exprs as $i => $exprCtx) {
            $reg = $this->visit($exprCtx);
            $exprType = $this->getExprType($exprCtx);

            if ($exprType === 'float32' || $exprType === 'float64' || $exprType === 'float') {
                // Para floats, el registro ya es sN
                // Mover a s0 (o s1 para segundo retorno)
                if ($reg !== 's' . $i && $i === 0) {
                    $this->asm->writeLine("fmov s$i, $reg");
                }
                $this->fregs->free($reg);
            } elseif (str_starts_with($reg, 's')) {
                // Si el registro es flotante pero esperamos entero, convertir
                $this->asm->writeLine("fmov x$i, $reg");
                $this->fregs->free($reg);
            } else {
                $this->asm->writeLine("mov x$i, $reg");
                $this->regs->free($reg);
            }
        }

        // Salto directo al epílogo de la función
        $endLabel = end($this->functionStack)['endLabel'];
        $this->asm->writeLine("b $endLabel");
        
        return null;
    }
    
    //  LLAMADA A FUNCIÓN COMO STATEMENT
    //  suma(3, 4)
    public function visitFuncCallStmt($ctx) {
        $this->emitFuncCall($ctx->ID()->getText(), $ctx->e() ?? [], false);
        return null;
    }


    //  LLAMADA A FUNCIÓN COMO EXPRESIÓN
    //  r := suma(3, 4)
    //  if esPar(x) { ... }

    public function visitFuncCallExpr($ctx) {
        $name   = $ctx->ID()->getText();
        $exprs  = $ctx->e() ?? [];

        $this->emitFuncCall($name, $exprs, true);

        // Detectar tipo de retorno para builtins
        if ($this->isBuiltin($name)) {
            $returnType = match($name) {
                'len', 'now' => 'int32',
                'typeof', 'substr' => 'string',
                default => 'int32'
            };
        } else {
            $returnType = 'int32';
            if (isset($this->functionSignatures[$name])) {
                $returns = $this->functionSignatures[$name]['returns'] ?? [];
                if (count($returns) > 0) {
                    $returnType = $returns[0];
                }
            }
        }

        if ($returnType === 'float32' || $returnType === 'float64' || $returnType === 'float') {
            $this->asm->writeComment("Retorno flotante detectado ($returnType) -> rescatar de s0");
            $floatReg = $this->fregs->allocate();
            $this->asm->writeLine("fmov $floatReg, s0");
            return $floatReg;
        } else {
            $reg = $this->regs->allocate();
            $this->asm->writeComment("Retorno normal detectado ($returnType) -> rescatar de x0");
            $this->asm->writeLine("mov $reg, x0");
            return $reg;
        }
    }
    

    private function emitFuncCall(string $name, array $exprs, bool $preserveReturn = false): void {
        $this->asm->writeComment("Llamada a $name(" . count($exprs) . " args)");

        // Detectar y manejar builtins
        if ($this->isBuiltin($name)) {
            $this->emitBuiltinCall($name, $exprs);
            return;
        }

        // Solo permitir hasta 8 argumentos (ARM64 ABI estándar)
        $numArgs = min(count($exprs), 8);
        
        // Registros que se van a usar para argumentos
        $argRegs = array_map(fn($i) => "x$i", range(0, $numArgs - 1));
        
        // Reservar solo los registros que se usarán como argumentos
        $this->regs->reserve($argRegs);

        // Evaluar argumentos (no pueden usar x0-x[numArgs-1])
        $tempRegs = [];
        foreach ($exprs as $i => $expr) {
            $reg = $this->visit($expr);
            $tempRegs[] = $reg;
        }

        // Desreservar para permitir mover argumentos
        $this->regs->unreserve($argRegs);

        // Mover argumentos a sus posiciones
        foreach ($tempRegs as $i => $reg) {
            if ($i < 8) {
                if (str_starts_with($reg, 's') || str_starts_with($reg, 'd')) {
                    $this->asm->writeLine("fmov s$i, $reg");
                } else {
                    $this->asm->writeLine("mov x$i, $reg");
                }
            }
            $this->regs->free($reg);
        }

        $this->asm->writeLine("bl $name");
    }

    private function isBuiltin(string $name): bool {
        return in_array($name, ['len', 'typeof', 'substr', 'now'], true);
    }

    private function emitBuiltinCall(string $name, array $exprs): void {
        match($name) {
            'len' => $this->emitLen($exprs),
            'typeof' => $this->emitTypeOf($exprs),
            'substr' => $this->emitSubstr($exprs),
            'now' => $this->emitNow($exprs),
        };
    }

    private function emitLen(array $exprs): void {
        if (count($exprs) === 0) {
            // len() sin argumentos retorna 0
            $this->asm->writeLine("mov x0, #0");
            return;
        }

        $arg = $this->visit($exprs[0]);
        
        // Si es un array o string, obtener su longitud
        // Para strings: obtener longitud del string
        $exprText = $exprs[0]->getText() ?? '';
        $symbol = null;
        
        if (method_exists($exprs[0], 'ID') && $exprs[0]->ID() !== null) {
            $symbol = $this->getSymbol($exprs[0]->ID()->getText());
        }

        if ($symbol !== null) {
            if ($symbol->type === 'string') {
                // String: arg contiene la dirección del string
                // Contar caracteres hasta null-terminator
                $countReg = $this->regs->allocate();
                $this->asm->writeLine("mov $countReg, #0");
                $loopLabel = $this->labels->newLabel('STRLEN_LOOP');
                $endLabel = $this->labels->newLabel('STRLEN_END');
                
                $this->asm->writeLabel($loopLabel);
                $this->asm->writeLine("ldrb w1, [$arg, $countReg]");
                $this->asm->writeLine("cmp w1, #0");
                $this->asm->writeLine("b.eq $endLabel");
                $this->asm->writeLine("add $countReg, $countReg, #1");
                $this->asm->writeLine("b $loopLabel");
                
                $this->asm->writeLabel($endLabel);
                $this->asm->writeLine("mov x0, $countReg");
                $this->regs->free($countReg);
            } else if (!empty($symbol->arrayDims)) {
                // Array: retornar el tamaño del primer nivel
                $this->asm->writeLine("mov x0, #{$symbol->arrayDims[0]}");
            } else {
                // Otro tipo: retornar 0
                $this->asm->writeLine("mov x0, #0");
            }
        } else {
            // Expresión desconocida: retornar 0
            $this->asm->writeLine("mov x0, #0");
        }
        
        $this->regs->free($arg);
    }

    private function emitTypeOf(array $exprs): void {
        if (count($exprs) === 0) {
            // typeof() sin argumentos
            $label = $this->internString('unknown');
            $this->asm->writeLine("adrp x0, $label");
            $this->asm->writeLine("add x0, x0, :lo12:$label");
            return;
        }

        $expr = $exprs[0];
        $exprType = $this->getExprType($expr);
        
        // Internalize type string
        $typeStr = match($exprType) {
            'int32' => 'int32',
            'float32' => 'float32',
            'bool' => 'bool',
            'string' => 'string',
            default => 'unknown'
        };

        $label = $this->internString($typeStr);
        $this->asm->writeLine("adrp x0, $label");
        $this->asm->writeLine("add x0, x0, :lo12:$label");
    }

    private function emitSubstr(array $exprs): void {
        if (count($exprs) < 3) {
            // substr requiere 3 argumentos: string, start, length
            $label = $this->internString('');
            $this->asm->writeLine("adrp x0, $label");
            $this->asm->writeLine("add x0, x0, :lo12:$label");
            return;
        }

        $str = $this->visit($exprs[0]);
        $start = $this->visit($exprs[1]);
        $len = $this->visit($exprs[2]);

        // Para substring, necesitamos crear un buffer dinámico
        // Por ahora, simplemente retornamos la dirección + offset
        // Esto es una simplificación; en producción habría que copiar el substring
        
        $this->asm->writeLine("add x0, $str, $start");
        
        $this->regs->free($str);
        $this->regs->free($start);
        $this->regs->free($len);
    }

    private function emitNow(array $exprs): void {
        // now() retorna el timestamp actual (syscall)
        $this->asm->writeLine("mov x0, #0");
        $this->asm->writeLine("mov x1, sp");
        $this->asm->writeLine("mov x8, #169");  // gettimeofday syscall
        $this->asm->writeLine("svc #0");
        $this->asm->writeLine("ldr x0, [sp]");  // Retornar segundos
    }

    //  ASIGNACIÓN MULTI-RETORNO
    //  resultado, ok := dividir(10, 2)
    public function visitMultiShortVarDecl($ctx) {
        $ids   = $ctx->ID();         
        $exprs = $ctx->e();          

        // Si la expresión es una llamada a función, emitimos el bl
        // y tomamos x0 para el primer ID y x1 para el segundo
        if (count($exprs) === 1 && $exprs[0] instanceof \Context\FuncCallExprContext) {
            $funcName  = $exprs[0]->ID()->getText();
            $funcExprs = $exprs[0]->e() ?? [];

            $this->emitFuncCall($funcName, $funcExprs, true);

            // Para builtins no-multireturns, solo usar x0
            if (!$this->isBuiltin($funcName)) {
                // x0 -> primer variable
                $sym0 = $this->getSymbol($ids[0]->getText());
                if ($sym0 !== null) {
                    $this->asm->writeComment("Multi-return[0] → '{$ids[0]->getText()}'");
                    $this->asm->writeLine("str w0, [x29, #{$sym0->offset}]");
                }

                // x1 -> segunda variable
                if (isset($ids[1])) {
                    $sym1 = $this->getSymbol($ids[1]->getText());
                    if ($sym1 !== null) {
                        $this->asm->writeComment("Multi-return[1] → '{$ids[1]->getText()}'");
                        $this->asm->writeLine("str w1, [x29, #{$sym1->offset}]");
                    }
                }
            } else {
                // Builtins: solo usan un retorno
                $sym0 = $this->getSymbol($ids[0]->getText());
                if ($sym0 !== null) {
                    $this->asm->writeComment("Builtin return → '{$ids[0]->getText()}'");
                    $this->asm->writeLine("str w0, [x29, #{$sym0->offset}]");
                }
            }

            return null;

            // x1 -> segunda variable (si existe)
            if (isset($ids[1])) {
                $sym1 = $this->getSymbol($ids[1]->getText());
                if ($sym1 !== null) {
                    $this->asm->writeComment("Multi-return[1] → '{$ids[1]->getText()}'");
                    $this->asm->writeLine("str w1, [x29, #{$sym1->offset}]");
                }
            }

            return null;
        }

        // Caso normal: múltiples expresiones (a, b := 1, 2)
        foreach ($ids as $i => $idNode) {
            $sym = $this->getSymbol($idNode->getText());
            if ($sym === null || !isset($exprs[$i])) continue;

            $reg  = $this->visit($exprs[$i]);
            $this->storeRegisterInFrameOffset($sym, $sym->offset, $reg);
            $this->regs->free($reg);
        }

        return null;
    }

    //  tamaño de tipo en bytes
    //  Necesario para saber si usar w (32-bit) o x (64-bit)
    
    private function getTypeSize(string $type): int {
        return match(trim($type)) {
            'int32', 'int', 'bool', 'rune', 'float32' => 4,
            'string' => 8,   // puntero de 64 bits
            default  => 8,
        };
    }
}
